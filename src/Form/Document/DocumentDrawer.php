<?php

declare(strict_types=1);

namespace Drupal\surface\Form\Document;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Contract\SchemaResolver;
use Drupal\surface\Contract\Schemas;
use Drupal\surface\Contract\ServedContract;
use Drupal\surface\Form\MachineNameElement;
use Drupal\surface\Form\Refreshing;
use Drupal\surface\Form\SurfaceFormBuilder;
use Drupal\surface\Form\SurfaceFormBuilderInterface;
use Drupal\surface\Pipeline\Sentences;
use Drupal\surface\Plugin\Validation\Constraint\JsonSchemaConstraintValidator;
use Drupal\surface\Widget\NodeDrawerInterface;
use Drupal\surface\Widget\SurfaceWidgetBase;
use Drupal\surface\Widget\SurfaceWidgetManager;
use Drupal\surface\Widget\WidgetContext;
use Drupal\surface\Widget\WidgetHostInterface;
use PortableSchema\Builder\Schema;

/**
 * Draws the served document as Form API elements.
 *
 * The form door's reader of the envelope a data surface serves: the
 * document's `schema` (emitted with the widget hints), its `values` and
 * its `stale` pointers. It reads the schema inline
 * (SchemaResolver::inline()) and draws each key as the React app does,
 * one rule per method, named as React names it where the names fit:
 *
 * - the widget is the one `x-portable-ui.widget` names, else the stock
 *   one for the node's shape (SurfaceWidgetManager::widgetFor());
 * - a key is required where its owner's `required` lists it;
 * - its title, description and `options.help`, and the note of a lock
 *   (`x-surface.locked`, or "Fixed for this operation."), are its label
 *   and description, plain text;
 * - a `readOnly` key is drawn disabled, and everything inside it with it;
 * - a select's empty option is the document's `emptyOption`, and a stale
 *   value one the select stands for (emptyOption());
 * - a group a frame declares under `x-portable-ui.groups` holds the keys
 *   naming it, drawn where its first key is, by weight (arranged()); a
 *   key naming no declared group is drawn ungrouped;
 * - a list of objects is a collection, one details per item, with its
 *   buttons (collection()), each item the items' object narrowed by the
 *   conditionals its values meet (narrowedFor());
 * - variants are the variant the parent's conditionals choose for the
 *   deciding key's value, or nothing (resolveVariants());
 * - a key the document leaves out of its frame, listed in the frame's
 *   `x-surface.doesNotApply`, does not apply, and is drawn as its
 *   wrapper alone;
 * - a computed key not sent is drawn as its value, fixed, and a machine
 *   name computed unless sent as core's machine name element;
 * - what a key depends on (`x-surface.dependsOn`) rebuilds it over AJAX.
 *
 * Each element drawn for a value carries its JSON Pointer
 * (SurfaceFormBuilderInterface::PATH_KEY) and the widget that drew it;
 * extraction finds the node at that pointer in the envelope
 * (DocumentNodes::at()). The container keeps the values the envelope was
 * emitted for (SurfaceFormBuilderInterface::VALUES_KEY), never the
 * nodes. A key the envelope leaves deferred with no conditional for the
 * value its deciding key holds (a variant inside a collection's item) is
 * drawn from the document the host gives for that place and that value
 * (DocumentNodes::chosenVariant()), each frame below it narrowed by its
 * conditionals as it is drawn. It reads no data surface, no definition and
 * no Typed Data: what changes when a value does is a new envelope, which
 * the host asks for and hands over.
 *
 * Decision: see docs/decisions.md#the-form-is-drawn-from-the-document.
 *
 * @internal
 */
final class DocumentDrawer implements NodeDrawerInterface {

  /**
   * Render key marking a collection's element: its dotted path.
   */
  public const LIST_KEY = '#surface_list';

  /**
   * The key of the note a container locked whole carries, above the form.
   *
   * With an at sign, which no data surface key can take; it posts nothing.
   */
  public const LOCKED_KEY = '@locked';

  /**
   * Marks the item a computed key is drawn as.
   */
  protected const COMPUTED_KEY = '#surface_computed';

  /**
   * Marks an element, until it is wired, with what its key depends on.
   */
  protected const DEPENDS_KEY = '#surface_depends_on';

  /**
   * Marks an element, until it is wired, with the ports its keys read.
   *
   * Its `x-surface.reads`: each port, by the name its keys' `dependsOn`
   * use, to the key of the frame around it the port reads.
   */
  protected const READS_KEY = '#surface_reads';

  /**
   * The key of a collection's "Add another" button inside its element.
   *
   * With an at sign, which no delta and no data surface key can take.
   */
  protected const ADD_KEY = '@add';

  /**
   * The key of an item's "Remove" button inside the item's element.
   */
  protected const REMOVE_KEY = '@remove';

  /**
   * The element types a value is typed into, which take a placeholder.
   */
  protected const PLACEHOLDER_TYPES = [
    'textfield',
    'textarea',
    'email',
    'url',
    'tel',
    'search',
    'number',
    'machine_name',
  ];

  /**
   * The container being drawn: its DOM id, and whether it is locked whole.
   *
   * @var array{wrapper: string, whole: bool, nodes: \Drupal\surface\Form\Document\DocumentNodes|null}
   */
  protected array $drawing = ['wrapper' => '', 'whole' => FALSE, 'nodes' => NULL];

  /**
   * Constructs the drawer.
   *
   * @param \Drupal\surface\Widget\SurfaceWidgetManager $widgetManager
   *   The widget manager, whose widgets draw each node.
   * @param \Drupal\surface\Form\Refreshing $refreshing
   *   What wires the drawn container to the rebuild.
   */
  public function __construct(
    protected readonly SurfaceWidgetManager $widgetManager,
    protected readonly Refreshing $refreshing,
  ) {
  }

  /**
   * Draws a served contract as a container of form elements.
   *
   * @param \Drupal\surface\Contract\ServedContract $contract
   *   The envelope, emitted with the widget hints.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the form the container is drawn in.
   * @param \Drupal\surface\Widget\WidgetHostInterface|null $host
   *   What the host answers that the document does not say.
   * @param string $wrapper_key
   *   The key the host places the container under, which its DOM id is
   *   made from.
   * @param string[] $keys
   *   The top keys to draw, a flow step's; none for every key.
   * @param \Drupal\surface\Form\Document\DocumentNodes|null $nodes
   *   The envelope's nodes, when the caller read them already.
   *
   * @return array
   *   The container.
   */
  public function draw(ServedContract $contract, FormStateInterface $form_state, ?WidgetHostInterface $host = NULL, string $wrapper_key = 'surface', array $keys = [], ?DocumentNodes $nodes = NULL): array {
    $nodes ??= DocumentNodes::of($contract, $host);
    $root = $nodes->root;
    $values = $nodes->values;
    $stale = array_values(array_filter((array) ($contract->document['stale'] ?? []), 'is_string'));
    // One id per built container, not one per plugin: two placements of
    // the same block on one page would otherwise share a wrapper id. On a
    // rebuild the id is the one the browser holds, which the trigger sent.
    $wrapper_id = static::postedWrapperId($form_state, $wrapper_key) ?? Html::getUniqueId($wrapper_key . '-wrapper');
    $container = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => ['id' => $wrapper_id],
      '#process' => $this->refreshing->surfaceProcess('container'),
      Refreshing::WRAPPER_KEY => $wrapper_id,
      // What extraction finds each element's node by, with the envelope
      // the host emits for them again where this build is not in memory.
      SurfaceFormBuilderInterface::VALUES_KEY => $values,
    ];
    // Drawn after the type's own, which an explicit list replaces.
    $container['#pre_render'] = $this->refreshing->surfacePreRender('container');
    $was = $this->drawing;
    // Locked whole (`readOnly` at the top), the reason is said once,
    // above the form: no element repeats it, and none is marked required.
    // Decision: see docs/decisions.md#locked-at-the-top.
    $this->drawing = ['wrapper' => $wrapper_id, 'whole' => $root->get('readOnly') === TRUE, 'nodes' => $nodes];
    try {
      $context = new WidgetContext('', FALSE, FALSE, FALSE, $host, $this, $stale);
      $container += $this->frame($root, $values, $context, $keys);
    }
    finally {
      $this->drawing = $was;
    }
    $container = $this->wired($container);
    $absent = Refreshing::ruledOutPaths($container);
    if ($absent !== []) {
      // Decision: see docs/decisions.md#a-key-that-does-not-apply.
      $container[SurfaceFormBuilderInterface::ABSENT_MARKER_KEY] = [
        '#type' => 'hidden',
        '#value' => implode(' ', $absent),
      ];
    }
    $container = static::withGroups($container);
    $reason = static::lockedWhole($root);
    if ($reason !== NULL) {
      // Locked whole: the reason, said once above the form, where the host
      // form drew it from the data surface before.
      // Decision: see docs/decisions.md#locked-at-the-top.
      $container[self::LOCKED_KEY] = [
        '#type' => 'item',
        '#weight' => -100,
        '#markup' => new TranslatableMarkup('This may be seen and not changed: @reason', ['@reason' => $reason]),
      ];
    }
    $stale_paths = Refreshing::stalePaths($container);
    if ($stale_paths !== []) {
      // Fixed to this build: what the person is looking at now, not what
      // the previous build posted.
      $container[SurfaceFormBuilderInterface::STALE_MARKER_KEY] = [
        '#type' => 'hidden',
        '#value' => implode(' ', $stale_paths),
      ];
    }
    // What the refined data surface and the option lists it offers depend
    // on is what this container depends on: without it a form holding a
    // state-dependent shape would be cached as though it were static.
    CacheableMetadata::createFromObject($contract)->applyTo($container);
    return $container;
  }

  /**
   * Reads why the envelope is locked whole.
   *
   * @param \PortableSchema\Builder\Schema $root
   *   The envelope's schema, inline.
   *
   * @return string|null
   *   The root `x-surface.locked` beside a `readOnly` root: why the access
   *   answer locked the whole; NULL where it is not locked whole.
   */
  public static function lockedWhole(Schema $root): ?string {
    return $root->get('readOnly') === TRUE ? SurfaceWidgetBase::text(SurfaceWidgetBase::extension($root)['locked'] ?? NULL) : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function drawProperties(Schema $object, mixed $value, WidgetContext $context): array {
    return $this->frame($object, is_array($value) ? $value : [], $context);
  }

  /**
   * {@inheritdoc}
   */
  public function drawNode(Schema $node, mixed $value, WidgetContext $context): array {
    return $this->key($node, $value, $context, NULL, []);
  }

  /**
   * Draws the properties of an object node, each as its own element.
   *
   * In the object's order; a key the document leaves out because it does
   * not apply (the frame's `x-surface.doesNotApply`) is drawn as its
   * wrapper alone, after the first key it depends on, so the rebuild that
   * makes it apply has a place to put it.
   *
   * @param \PortableSchema\Builder\Schema $object
   *   The object node, inline.
   * @param array $values
   *   What it holds, by key.
   * @param \Drupal\surface\Widget\WidgetContext $context
   *   The object's context.
   * @param string[] $keys
   *   The keys to draw; none for every key.
   *
   * @return array
   *   One element per key, keyed by name.
   */
  protected function frame(Schema $object, array $values, WidgetContext $context, array $keys = []): array {
    if ($context->narrows) {
      // Drawn from a document narrowed for no values: narrowed here, its
      // declared rules among them.
      $object = static::narrowedFor($object, $values, TRUE);
    }
    $properties = $object->isBoolean() ? [] : (array) ($object->get('properties') ?? []);
    $required = array_map('strval', (array) ($object->get('required') ?? []));
    $groups = $object->isBoolean() ? [] : (Schemas::data(SurfaceWidgetBase::ui($object)['groups'] ?? NULL) ?? []);
    $elements = [];
    foreach ($properties as $name => $node) {
      $name = (string) $name;
      if ($keys !== [] && !in_array($name, $keys, TRUE)) {
        // Another step's: no element, so it says nothing, and accept keeps
        // what it holds.
        // Decision: see
        // docs/decisions.md#a-flow-is-declared-on-the-route-that-draws-it-not-a-resource.
        continue;
      }
      if (!$node instanceof Schema || $node->isBoolean()) {
        $elements[$name] = static::ruledOut();
        continue;
      }
      $at = $context->at($name, in_array($name, $required, TRUE));
      $elements[$name] = static::arranged($this->key($node, $values[$name] ?? NULL, $at, $object, $values), $node, $groups);
    }
    $ruled = $object->isBoolean() ? [] : (Schemas::data(SurfaceWidgetBase::extension($object)['doesNotApply'] ?? NULL) ?? []);
    foreach ($ruled as $name => $dependencies) {
      $name = (string) $name;
      if (isset($elements[$name]) || ($keys !== [] && !in_array($name, $keys, TRUE))) {
        continue;
      }
      $placeholder = static::ruledOut();
      $placeholder[self::DEPENDS_KEY] = array_values(array_filter((array) $dependencies, 'is_string'));
      $elements = static::placedAfter($elements, $name, $placeholder, $placeholder[self::DEPENDS_KEY]);
    }
    return $elements;
  }

  /**
   * Draws one key: a computed value, a collection, variants or its widget.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The key's node, inline.
   * @param mixed $value
   *   What it holds, as shown.
   * @param \Drupal\surface\Widget\WidgetContext $context
   *   Where it is drawn.
   * @param \PortableSchema\Builder\Schema|null $frame
   *   The object holding it, whose conditionals choose its variant; NULL
   *   for a list's row.
   * @param array $frame_values
   *   What that object holds.
   *
   * @return array
   *   The element.
   */
  protected function key(Schema $node, mixed $value, WidgetContext $context, ?Schema $frame, array $frame_values): array {
    $extension = SurfaceWidgetBase::extension($node);
    $depends = array_values(array_filter((array) ($extension['dependsOn'] ?? []), 'is_string'));
    $locked = $node->get('readOnly') === TRUE && !$context->locked;
    $computed = Schemas::data($extension['computed'] ?? NULL);
    if ($computed !== NULL && ($computed['when'] ?? NULL) !== 'unless_sent') {
      // Shown and fixed: nobody types it, and nothing is posted for it.
      // One computed unless sent is drawn by its widget, below.
      // Decision: see
      // docs/decisions.md#a-computed-key-is-a-pure-function-of-its-sources.
      return static::computedItem($node, $computed, $frame, $value) + [self::DEPENDS_KEY => $depends];
    }
    if (static::isCollection($node)) {
      // Decision: see docs/decisions.md#a-list-of-child-invocations.
      $element = $this->collection($node, $value, $context, $locked);
    }
    elseif (DocumentNodes::isVariants($node)) {
      // The variant the frame's conditionals choose; a deferred one inside
      // an item, which no conditional ties to it, from the host's
      // document for that place and that value, a full schema whose
      // frames are narrowed as they are drawn.
      $variant = $frame === NULL ? NULL : static::resolveVariants($frame, $context->name(), $frame_values);
      if ($variant === NULL && $frame !== NULL) {
        $variant = $this->drawing['nodes']?->chosenVariant($frame, $context->name(), $context->pointer, $frame_values);
        $context = $variant === NULL ? $context : $context->narrowing();
      }
      if ($variant === NULL) {
        // Variants whose deciding key chose nothing have no shape to
        // render: nothing but the wrapper, so the rebuild the deciding key
        // is wired to has a place to put the variant once it is chosen.
        return [
          '#markup' => '',
          SurfaceFormBuilderInterface::PLACEHOLDER_KEY => TRUE,
          self::DEPENDS_KEY => $depends,
        ];
      }
      $element = $this->drawn($variant, $value, $locked ? $context->locked() : $context);
      // What the elements were drawn for, read back by extraction and by
      // the rebuild: input drawn for another variant is dropped.
      // Decision: see
      // docs/decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter.
      $by = (string) $extension['by'];
      $element[SurfaceFormBuilderInterface::DRAWN_FOR_KEY] = [
        'by' => $by,
        'for' => (string) ($frame_values[$by] ?? $extension['chosen'] ?? ''),
      ];
    }
    else {
      $element = $this->drawn($node, $value, $locked ? $context->locked() : $context);
      $source = $computed['from'][0] ?? NULL;
      $properties = $frame === NULL ? [] : (array) ($frame->get('properties') ?? []);
      if (!$locked && !$context->locked && is_string($source) && isset($properties[$source]) && $node->get('pattern') === MachineNameElement::PATTERN) {
        // A machine name computed from a title: core's element, suggesting
        // it as the title is typed.
        // Decision: see
        // docs/decisions.md#a-machine-name-is-a-computed-identity.
        $element = MachineNameElement::draw($element, $source, SurfaceWidgetBase::text($node->get('title')));
      }
    }
    $element[self::DEPENDS_KEY] = $depends;
    $reads = Schemas::data($extension['reads'] ?? NULL);
    if ($reads !== NULL) {
      $element[self::READS_KEY] = $reads;
    }
    if ($locked) {
      // Visible but fixed: the consumer sees the key and its value and
      // cannot change it, the form-side spelling of readOnly. A locked
      // fieldset passes #disabled on to every element inside it, as the
      // form API does for any parent.
      $element['#disabled'] = TRUE;
      $element = $this->drawing['whole'] ? static::withoutRequired($element) : static::describeLocked($element, SurfaceWidgetBase::text($extension['locked'] ?? NULL));
    }
    return $element;
  }

  /**
   * Draws a node with the widget it names, and marks the element.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The node, inline.
   * @param mixed $value
   *   What it holds.
   * @param \Drupal\surface\Widget\WidgetContext $context
   *   Where it is drawn.
   *
   * @return array
   *   The element.
   */
  protected function drawn(Schema $node, mixed $value, WidgetContext $context): array {
    $widget = $this->widgetManager->widgetFor($node, $context);
    $element = $widget->buildElement($node, $value, $context);
    $plugin_id = $widget instanceof SurfaceWidgetBase ? $widget->getPluginId() : get_class($widget);
    return static::marked($element, $context, $plugin_id);
  }

  /**
   * Marks an element with where its value is, and what reads it back.
   *
   * Its JSON Pointer and the widget that drew it, nothing more: the node
   * is the envelope's at that pointer (DocumentNodes::at()).
   *
   * @param array $element
   *   The element a widget built.
   * @param \Drupal\surface\Widget\WidgetContext $context
   *   Where it was drawn.
   * @param string $widget
   *   The widget's plugin ID.
   *
   * @return array
   *   The element, marked.
   */
  protected static function marked(array $element, WidgetContext $context, string $widget): array {
    if (!empty($element[SurfaceFormBuilderInterface::PLACEHOLDER_KEY])) {
      return $element;
    }
    $element[SurfaceFormBuilderInterface::PATH_KEY] = $context->pointer;
    $element[SurfaceFormBuilderInterface::WIDGET_KEY] = $widget;
    return $element;
  }

  /**
   * Answers whether a node is a collection: a list of objects.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The node.
   *
   * @return bool
   *   TRUE for `x-surface.collection`, and for any list whose items are
   *   objects with properties.
   */
  protected static function isCollection(Schema $node): bool {
    if (SurfaceWidgetBase::type($node) !== 'array') {
      return FALSE;
    }
    if (!empty(SurfaceWidgetBase::extension($node)['collection'])) {
      return TRUE;
    }
    $items = $node->get('items');
    return $items instanceof Schema && !$items->isBoolean() && is_array($items->get('properties')) && !SurfaceWidgetBase::offers($items);
  }

  /**
   * Finds the variant a key holds: React's `resolveVariants()`.
   *
   * Read from the parent's `allOf`: the `then` whose `if` names the value
   * the deciding key holds. A declared rule's conditional is no variant,
   * nor is a `then` saying the key does not apply.
   *
   * @param \PortableSchema\Builder\Schema $parent
   *   The object holding the key, inline.
   * @param string $name
   *   The key.
   * @param array $parent_values
   *   What the object holds.
   *
   * @return \PortableSchema\Builder\Schema|null
   *   The variant's node, or NULL when the deciding key chose nothing.
   */
  public static function resolveVariants(Schema $parent, string $name, array $parent_values): ?Schema {
    $properties = (array) ($parent->get('properties') ?? []);
    $key = $properties[$name] ?? NULL;
    $by = $key instanceof Schema && !$key->isBoolean() ? (SurfaceWidgetBase::extension($key)['by'] ?? NULL) : NULL;
    if (!is_string($by)) {
      return NULL;
    }
    $value = $parent_values[$by] ?? SurfaceWidgetBase::extension($key)['chosen'] ?? NULL;
    if ($value === NULL || is_array($value)) {
      return NULL;
    }
    foreach ((array) ($parent->get('allOf') ?? []) as $condition) {
      if (!$condition instanceof Schema || $condition->isBoolean() || !empty(SurfaceWidgetBase::extension($condition)['declared'])) {
        continue;
      }
      $if = $condition->get('if');
      $decided = $if instanceof Schema && !$if->isBoolean() ? ((array) ($if->get('properties') ?? []))[$by] ?? NULL : NULL;
      $then = $condition->get('then');
      $branch = $then instanceof Schema && !$then->isBoolean() ? ((array) ($then->get('properties') ?? []))[$name] ?? NULL : NULL;
      if ($decided instanceof Schema && !$decided->isBoolean() && $decided->has('const') && (string) $decided->get('const') === (string) $value && $branch instanceof Schema && !$branch->isBoolean()) {
        return $branch;
      }
    }
    return NULL;
  }

  /**
   * Lays over an item's object what its conditionals answer for its values.
   *
   * The document states one `items` for every item of a collection, with
   * what its keys answer for each value they watch as conditionals
   * (`x-surface.enumerated`): each conditional whose `if` the item's
   * values meet (holds()), a key they leave out read at its document
   * default, has each key its `then` names merged over the key, later
   * winning, as a narrowing only narrows; a key its `then` rules out
   * (`false`) does not apply, its wrapper wired to what it depends on. A
   * conditional naming a variant is left to resolveVariants(). A declared
   * rule's conditional is read only for a frame of a document narrowed for
   * no values (a deferred key's chosen document, $declared): its `if` is
   * held to the values by the JSON Schema validator, as the React app
   * reads a declared rule. A frame drawn whole from the envelope is
   * already narrowed for its values, so only an item is read so there.
   *
   * @param \PortableSchema\Builder\Schema $object
   *   The items' object, inline.
   * @param array $values
   *   One item's values.
   * @param bool $declared
   *   Whether declared rules are read too.
   *
   * @return \PortableSchema\Builder\Schema
   *   The object, narrowed for the item.
   */
  public static function narrowedFor(Schema $object, array $values, bool $declared = FALSE): Schema {
    if ($object->isBoolean() || !is_array($object->get('allOf'))) {
      return $object;
    }
    $properties = (array) ($object->get('properties') ?? []);
    $ruled = Schemas::data(SurfaceWidgetBase::extension($object)['doesNotApply'] ?? NULL) ?? [];
    // A key the values leave out is read at its document default, as it
    // is drawn.
    foreach ($properties as $name => $property) {
      if (!array_key_exists((string) $name, $values) && $property instanceof Schema && !$property->isBoolean() && $property->has('default')) {
        $values[(string) $name] = $property->get('default');
      }
    }
    foreach ($object->get('allOf') as $condition) {
      if (!$condition instanceof Schema || $condition->isBoolean()) {
        continue;
      }
      $rule = !empty(SurfaceWidgetBase::extension($condition)['declared']);
      if ($rule ? !$declared || !static::ruleHolds($condition->get('if'), $values) : !static::holds($condition->get('if'), $values)) {
        continue;
      }
      $then = $condition->get('then');
      foreach ($then instanceof Schema && !$then->isBoolean() ? (array) ($then->get('properties') ?? []) : [] as $name => $branch) {
        $key = $properties[$name] ?? NULL;
        if (!$key instanceof Schema || $key->isBoolean() || isset(SurfaceWidgetBase::extension($key)['by'])) {
          continue;
        }
        if ($branch instanceof Schema && $branch->isBoolean()) {
          // Does not apply: its wrapper alone, wired to what it depends on.
          unset($properties[$name]);
          $ruled[$name] = array_values(array_filter((array) (SurfaceWidgetBase::extension($key)['dependsOn'] ?? []), 'is_string'));
          continue;
        }
        if ($branch instanceof Schema) {
          $properties[$name] = SchemaResolver::merge($key, $branch);
        }
      }
    }
    $object = $object->with('properties', $properties);
    return $ruled === [] ? $object : $object->with('x-surface', ['doesNotApply' => $ruled] + SurfaceWidgetBase::extension($object));
  }

  /**
   * Answers whether values meet a conditional's `if`.
   *
   * Only an `if` this reader can read is judged: `required`, and under
   * `properties` each key's `const` or `enum`. One that says anything else
   * (`not`, a `type`, a pattern) is skipped, never counted as holding. A
   * key `properties` names and the values leave out meets its `const` and
   * its `enum`, as JSON Schema reads them: the key is not there, so the
   * subschema holds; `required` is what asks for it. Values are compared
   * as JSON compares them: a number equal to a number, anything else the
   * same value of the same type.
   *
   * @param mixed $if
   *   The `if`.
   * @param array $values
   *   The values.
   *
   * @return bool
   *   TRUE when the `if` is readable and the values meet it.
   */
  public static function holds(mixed $if, array $values): bool {
    if (!$if instanceof Schema || $if->isBoolean()) {
      return FALSE;
    }
    foreach (array_keys($if->all()) as $keyword) {
      if (!in_array($keyword, ['properties', 'required'], TRUE) && !str_starts_with((string) $keyword, 'x-')) {
        return FALSE;
      }
    }
    foreach ((array) ($if->get('required') ?? []) as $name) {
      if (!array_key_exists((string) $name, $values) || $values[$name] === NULL) {
        return FALSE;
      }
    }
    foreach ((array) ($if->get('properties') ?? []) as $name => $said) {
      if (!$said instanceof Schema || $said->isBoolean()) {
        return FALSE;
      }
      $keywords = array_filter(array_keys($said->all()), static fn (int|string $keyword): bool => !str_starts_with((string) $keyword, 'x-'));
      if ($keywords === [] || array_diff($keywords, ['const', 'enum']) !== []) {
        return FALSE;
      }
      if (!array_key_exists((string) $name, $values)) {
        continue;
      }
      $held = $values[$name];
      if ($said->has('const') && !static::same($held, $said->get('const'))) {
        return FALSE;
      }
      if ($said->has('enum') && array_filter((array) $said->get('enum'), static fn (mixed $allowed): bool => static::same($held, $allowed)) === []) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Answers whether values meet a declared rule's `if`.
   *
   * @param mixed $if
   *   The `if`.
   * @param array $values
   *   The values.
   *
   * @return bool
   *   TRUE when the JSON Schema validator holds the values to it.
   */
  protected static function ruleHolds(mixed $if, array $values): bool {
    if (!$if instanceof Schema) {
      return FALSE;
    }
    $decoded = Schemas::decoded($if);
    if (is_bool($decoded)) {
      return $decoded;
    }
    $document = json_decode((string) json_encode((object) $values, JSON_PRESERVE_ZERO_FRACTION));
    return JsonSchemaConstraintValidator::verdict($document, $decoded) === NULL;
  }

  /**
   * Answers whether two values are the same JSON value.
   *
   * @param mixed $a
   *   One value.
   * @param mixed $b
   *   The other.
   *
   * @return bool
   *   TRUE for two equal numbers, or the same value of the same type.
   */
  protected static function same(mixed $a, mixed $b): bool {
    if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
      return $a == $b;
    }
    return $a === $b;
  }

  /**
   * Draws a computed key: its value, fixed, and what it is computed from.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The key's node.
   * @param array $computed
   *   Its `x-surface.computed`: `from` and `when`.
   * @param \PortableSchema\Builder\Schema|null $frame
   *   The object holding it, which titles its sources.
   * @param mixed $value
   *   What it holds for the values shown.
   *
   * @return array
   *   An item, which posts nothing.
   */
  protected static function computedItem(Schema $node, array $computed, ?Schema $frame, mixed $value): array {
    $properties = $frame === NULL ? [] : (array) ($frame->get('properties') ?? []);
    $sources = implode(', ', array_map(static function (mixed $source) use ($properties): string {
      $property = $properties[(string) $source] ?? NULL;
      return ($property instanceof Schema && !$property->isBoolean() ? SurfaceWidgetBase::text($property->get('title')) : NULL) ?? (string) $source;
    }, (array) ($computed['from'] ?? [])));
    $note = ($computed['when'] ?? NULL) === 'change'
      ? t('Computed from @sources, again whenever they change.', ['@sources' => $sources])
      : t('Computed from @sources when this is created, and kept as it is after.', ['@sources' => $sources]);
    $description = SurfaceWidgetBase::text($node->get('description'));
    return [
      '#type' => 'item',
      '#title' => SurfaceWidgetBase::text($node->get('title')),
      '#plain_text' => is_scalar($value) ? (string) $value : '',
      '#description' => $description !== NULL ? new Sentences($description, $note) : $note,
      self::COMPUTED_KEY => TRUE,
    ];
  }

  /**
   * Draws a collection: one details per item, and its two controls.
   *
   * The list's own details holds one details per item, each the items'
   * object drawn with that item's values and titled with the list's title
   * and the item's number, then an "Add another" button. Each item
   * carries a "Remove" button. Both are submit buttons whose handler
   * rewrites the posted items and rebuilds the form, and whose AJAX
   * callback is the refinement one, replacing the list by its own wrapper
   * and nothing else. Adding is disabled once the list holds `maxItems`
   * and removing once it holds `minItems`, and the list is drawn with at
   * least its minimum, an empty item for each missing one. A locked list
   * is drawn disabled, every item and both controls with it.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The list's node.
   * @param mixed $value
   *   The items, as shown.
   * @param \Drupal\surface\Widget\WidgetContext $context
   *   Where the list is drawn.
   * @param bool $locked
   *   Whether the list itself is locked, not only inside a locked element.
   *
   * @return array
   *   The list's element.
   */
  protected function collection(Schema $node, mixed $value, WidgetContext $context, bool $locked): array {
    $items_node = $node->get('items');
    $items_node = $items_node instanceof Schema ? $items_node : Schema::from(['type' => 'object']);
    $items = is_array($value) ? array_values(array_filter($value, static fn (int|string $delta): bool => is_int($delta), ARRAY_FILTER_USE_KEY)) : [];
    $min = is_int($node->get('minItems')) ? $node->get('minItems') : 0;
    $max = is_int($node->get('maxItems')) ? $node->get('maxItems') : NULL;
    while (count($items) < $min) {
      $items[] = [];
    }
    $path = WidgetContext::parentsOf($context->pointer);
    $label = SurfaceWidgetBase::text($node->get('title')) ?? (string) end($path);
    $dotted = implode('.', $path);
    $disabled = $locked || $context->locked;
    $element = [
      '#type' => 'details',
      '#title' => $label,
      '#open' => TRUE,
      self::LIST_KEY => $dotted,
      // The list is what adding or removing an item replaces.
      Refreshing::REFRESH_KEY => $dotted,
      SurfaceFormBuilderInterface::PATH_KEY => $context->pointer,
    ];
    $description = SurfaceWidgetBase::text($node->get('description'));
    if ($description !== NULL) {
      $element['#description'] = $description;
    }
    if ($context->required) {
      $element['#required'] = TRUE;
    }
    $wrapper_id = $this->drawing['wrapper'];
    $control = static fn (string $name, array $at, bool $off): array => [
      '#type' => 'submit',
      '#value' => static::controlLabel($name),
      '#name' => 'surface_' . implode('_', array_map([Html::class, 'getId'], [...$path, ...$at])),
      '#submit' => [[SurfaceFormBuilder::class, $name === self::ADD_KEY ? 'addListItem' : 'removeListItem']],
      // Nothing is judged: the person said only "one more", or "not this
      // one", and every value is still mid-edit.
      '#limit_validation_errors' => [],
      '#disabled' => $off || $disabled,
      '#ajax' => [
        'callback' => [SurfaceFormBuilder::class, 'refreshSurface'],
        'wrapper' => $wrapper_id,
        'submit' => [SurfaceFormBuilderInterface::WRAPPER_INPUT => $wrapper_id],
      ],
      SurfaceFormBuilderInterface::TRIGGER_KEY => ['path' => [...$path, ...$at], 'replaces' => [$dotted]],
    ];
    foreach ($items as $delta => $item) {
      $at = $context->at($delta, FALSE, $disabled)->inItem();
      $element[$delta] = [
        '#type' => 'details',
        '#title' => static::itemLabel($label, $delta),
        '#open' => TRUE,
        SurfaceFormBuilderInterface::PATH_KEY => $at->pointer,
      ] + $this->frame(static::narrowedFor($items_node, is_array($item) ? $item : [], $context->narrows), is_array($item) ? $item : [], $at);
      foreach (static::elementChildren($element[$delta]) as $name) {
        if (!empty($element[$delta][$name][self::DEPENDS_KEY])) {
          // Each item is a frame of its own: a key of it that depends on
          // another has its wrapper, whether or not a trigger on this page
          // reaches it (a port the item reads that nothing binds here).
          $element[$delta][$name][Refreshing::REFRESH_KEY] = $dotted . '.' . $delta . '.' . $name;
        }
      }
      $element[$delta][self::REMOVE_KEY] = $control(self::REMOVE_KEY, [(string) $delta, self::REMOVE_KEY], count($items) <= $min);
      // Every item's button says "Remove"; its accessible name says which.
      $element[$delta][self::REMOVE_KEY]['#attributes']['aria-label'] = static::removeLabel(static::itemLabel($label, $delta));
      $element[$delta][self::REMOVE_KEY]['#weight'] = 1000;
    }
    $element[self::ADD_KEY] = $control(self::ADD_KEY, [self::ADD_KEY], $max !== NULL && count($items) >= $max);
    return $element;
  }

  /**
   * Draws a key's hints on its element: React's `arranged()` for one key.
   *
   * Its weight among its frame's keys, the group it is drawn in when its
   * frame declares that group (a marker withGroups() reads), a prefix and
   * a suffix beside the input, the help after its description, and a
   * placeholder on an input a value is typed into (never on a password).
   * Nothing here changes what the element posts or what is accepted.
   *
   * Decision: see docs/decisions.md#arrangement-is-declared-once.
   *
   * @param array $element
   *   The element drawn for the key.
   * @param \PortableSchema\Builder\Schema $node
   *   The key's own node, as its frame holds it.
   * @param array $groups
   *   The groups its frame declares, by id.
   *
   * @return array
   *   The element, arranged.
   */
  public static function arranged(array $element, Schema $node, array $groups): array {
    $ui = SurfaceWidgetBase::ui($node);
    if (is_int($ui['weight'] ?? NULL)) {
      $element['#weight'] = $ui['weight'];
    }
    $id = $ui['group'] ?? NULL;
    $group = is_string($id) ? Schemas::data($groups[$id] ?? NULL) : NULL;
    if ($group !== NULL) {
      $options = Schemas::data($group['options'] ?? NULL) ?? [];
      $element[SurfaceFormBuilderInterface::GROUP_KEY] = [
        'id' => $id,
        'label' => (string) ($group['label'] ?? $id),
        'as' => ($group['widget'] ?? NULL) === 'details' ? 'details' : 'fieldset',
        'collapsed' => ($options['open'] ?? NULL) === FALSE,
        'weight' => is_int($group['weight'] ?? NULL) ? $group['weight'] : NULL,
        'description' => SurfaceWidgetBase::text($group['description'] ?? NULL),
      ];
    }
    if (!empty($element[SurfaceFormBuilderInterface::PLACEHOLDER_KEY])) {
      // A key that does not apply has no element, only its place.
      return $element;
    }
    $options = SurfaceWidgetBase::options($node);
    $type = $element['#type'] ?? NULL;
    $placeholder = SurfaceWidgetBase::text($options['placeholder'] ?? NULL);
    if ($placeholder !== NULL && in_array($type, self::PLACEHOLDER_TYPES, TRUE)) {
      $element['#placeholder'] = $placeholder;
    }
    $prefix = SurfaceWidgetBase::text($options['prefix'] ?? NULL);
    if ($prefix !== NULL) {
      $element['#field_prefix'] = $prefix;
    }
    $suffix = SurfaceWidgetBase::text($options['suffix'] ?? NULL);
    if ($suffix !== NULL) {
      $element['#field_suffix'] = $suffix;
    }
    $help = SurfaceWidgetBase::text($options['help'] ?? NULL);
    if ($help !== NULL) {
      $element['#description'] = isset($element['#description']) ? $element['#description'] . ' ' . $help : $help;
    }
    return $element;
  }

  /**
   * Draws the wrapper of a key that does not apply.
   *
   * Not rendered at all, not disabled and not hidden: the key does not
   * apply to these values, so there is nothing to answer. Its wrapper
   * alone is drawn, for the rebuild that makes it apply.
   *
   * Decision: see docs/decisions.md#a-key-that-does-not-apply.
   *
   * @return array
   *   The placeholder.
   */
  protected static function ruledOut(): array {
    return [
      '#markup' => '',
      SurfaceFormBuilderInterface::PLACEHOLDER_KEY => TRUE,
      SurfaceFormBuilderInterface::RULED_OUT_KEY => TRUE,
    ];
  }

  /**
   * Puts an element after the first of the keys it depends on.
   *
   * @param array $elements
   *   A frame's elements, in order.
   * @param string $name
   *   The element's key.
   * @param array $element
   *   The element.
   * @param string[] $after
   *   The keys it may follow, in order.
   *
   * @return array
   *   The elements, the new one after the first key it names, or last.
   */
  protected static function placedAfter(array $elements, string $name, array $element, array $after): array {
    $anchor = NULL;
    foreach ($after as $candidate) {
      $first = explode('.', $candidate)[0];
      if (isset($elements[$first])) {
        $anchor = $first;
        break;
      }
    }
    if ($anchor === NULL) {
      $elements[$name] = $element;
      return $elements;
    }
    $placed = [];
    $passed = FALSE;
    foreach ($elements as $key => $existing) {
      if ($passed && !empty($existing[SurfaceFormBuilderInterface::RULED_OUT_KEY])) {
        // Keys that do not apply after the same anchor keep their order.
        $placed[$key] = $existing;
        continue;
      }
      if ($passed) {
        $placed[$name] = $element;
        $passed = FALSE;
        $anchor = NULL;
      }
      $placed[$key] = $existing;
      if ((string) $key === $anchor) {
        $passed = TRUE;
      }
    }
    if (!isset($placed[$name])) {
      $placed[$name] = $element;
    }
    return $placed;
  }

  /**
   * Marks what a rebuild may replace and wires what triggers one.
   *
   * Every element drawn for a key that depends on others
   * (`x-surface.dependsOn`, or the frame's `x-surface.doesNotApply`) is a
   * target, with a wrapper of its own the rebuild replaces; each key it
   * names, read in the frame the target sits in or the nearest one around
   * it that holds the key, is a trigger, whose change rebuilds the form
   * and replaces every target it reaches, directly or through others.
   *
   * @param array $container
   *   The container, drawn.
   *
   * @return array
   *   The container, wired.
   */
  protected function wired(array $container): array {
    $edges = static::edges($container, $container, []);
    $replaces = [];
    foreach (array_keys($edges) as $trigger) {
      $reached = [];
      $queue = [(string) $trigger];
      while ($queue !== []) {
        foreach ($edges[array_shift($queue)] ?? [] as $target) {
          if ($target !== $trigger && !isset($reached[$target])) {
            $reached[$target] = TRUE;
            $queue[] = $target;
          }
        }
      }
      // An object holding a target the same change reaches is not
      // replaced whole: the target inside it is (a module's mount whose
      // key reads a port, its group left on the page).
      $reached = array_keys($reached);
      $replaces[(string) $trigger] = array_values(array_filter($reached, static function (string $target) use ($reached): bool {
        foreach ($reached as $other) {
          if (str_starts_with($other, $target . '.')) {
            return FALSE;
          }
        }
        return TRUE;
      }));
    }
    foreach (array_unique(array_merge([], ...array_values($replaces))) as $target) {
      $parents = explode('.', $target);
      if (is_array(NestedArray::getValue($container, $parents))) {
        NestedArray::setValue($container, [...$parents, Refreshing::REFRESH_KEY], $target);
      }
    }
    foreach ($replaces as $trigger => $targets) {
      $parents = explode('.', (string) $trigger);
      $element = NestedArray::getValue($container, $parents);
      if (is_array($element)) {
        NestedArray::setValue($container, $parents, $this->refreshing->attachRefinementAjax($element, $this->drawingWrapper($container), $parents, $targets));
      }
    }
    return static::withoutDependencies($container);
  }

  /**
   * Lists what each key's change reaches directly, below an element.
   *
   * @param array $container
   *   The container, which a dependency is read in.
   * @param array $element
   *   The container, or an element inside it.
   * @param string[] $path
   *   The element's path below the container.
   *
   * @return array<string, list<string>>
   *   The dotted paths of the targets, by the dotted path of their trigger.
   */
  protected static function edges(array $container, array $element, array $path): array {
    $edges = [];
    foreach (static::elementChildren($element) as $key) {
      $child = $element[$key];
      if (str_starts_with((string) $key, '@')) {
        continue;
      }
      $at = [...$path, (string) $key];
      foreach ((array) ($child[self::DEPENDS_KEY] ?? []) as $dependency) {
        $steps = explode('.', (string) $dependency);
        // Read in the frame the target sits in, or the nearest around it
        // that holds the key.
        for ($frame = array_slice($at, 0, -1); TRUE; array_pop($frame)) {
          foreach (static::candidates($container, $frame, $steps) as $candidate) {
            $exists = FALSE;
            $trigger = NestedArray::getValue($container, $candidate, $exists);
            if ($exists && is_array($trigger) && !empty($trigger[SurfaceFormBuilderInterface::PATH_KEY] ?? NULL) && empty($trigger[SurfaceFormBuilderInterface::PLACEHOLDER_KEY])) {
              $edges[implode('.', $candidate)][] = implode('.', $at);
              break 2;
            }
          }
          if ($frame === []) {
            break;
          }
        }
      }
      $edges = array_merge_recursive($edges, static::edges($container, $child, $at));
    }
    return $edges;
  }

  /**
   * Lists where a key a frame's key depends on may be, in that frame.
   *
   * The key itself, in the frame; and, when it is a port, the key of the
   * frame around it the port reads (`x-surface.reads`): of an attached
   * structure, that sibling of the structure; of each entry of a map of
   * keys from the site, that sibling map's entry of the same id.
   *
   * Decision: see docs/decisions.md#a-port-is-a-live-sibling-value.
   *
   * @param array $container
   *   The container.
   * @param string[] $frame
   *   The frame's path below the container.
   * @param string[] $steps
   *   The dependency, by its steps.
   *
   * @return list<string[]>
   *   The paths to look for it at, in order.
   */
  protected static function candidates(array $container, array $frame, array $steps): array {
    $candidates = [[...$frame, ...$steps]];
    if ($frame === []) {
      return $candidates;
    }
    $own = NestedArray::getValue($container, $frame);
    $reads = is_array($own) ? ($own[self::READS_KEY] ?? []) : [];
    if (is_string($reads[$steps[0]] ?? NULL)) {
      $candidates[] = [...array_slice($frame, 0, -1), ...explode('.', $reads[$steps[0]]), ...array_slice($steps, 1)];
    }
    if (count($frame) > 1) {
      $map = NestedArray::getValue($container, array_slice($frame, 0, -1));
      $each = is_array($map) ? ($map[self::READS_KEY] ?? []) : [];
      if (is_string($each[$steps[0]] ?? NULL)) {
        $candidates[] = [
          ...array_slice($frame, 0, -2),
          ...explode('.', $each[$steps[0]]),
          (string) end($frame),
          ...array_slice($steps, 1),
        ];
      }
    }
    return $candidates;
  }

  /**
   * Reads the container's DOM id.
   *
   * @param array $container
   *   The container.
   *
   * @return string
   *   Its id.
   */
  protected function drawingWrapper(array $container): string {
    return (string) ($container[Refreshing::WRAPPER_KEY] ?? '');
  }

  /**
   * Takes the dependency marks off every element, once wired.
   *
   * @param array $element
   *   The container, or an element inside it.
   *
   * @return array
   *   The same, unmarked.
   */
  protected static function withoutDependencies(array $element): array {
    unset($element[self::DEPENDS_KEY], $element[self::READS_KEY]);
    foreach (static::elementChildren($element) as $key) {
      $element[$key] = static::withoutDependencies($element[$key]);
    }
    return $element;
  }

  /**
   * Titles one item of a collection: the list's title and its number.
   *
   * @param string|\Stringable $label
   *   The list's title.
   * @param int $delta
   *   The item's delta; its number is one more.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The title.
   */
  protected static function itemLabel(string|\Stringable $label, int $delta): TranslatableMarkup {
    return t('@label @number', ['@label' => $label, '@number' => $delta + 1]);
  }

  /**
   * Words a collection's control.
   *
   * @param string $control
   *   ADD_KEY or REMOVE_KEY.
   *
   * @return string
   *   What the button says.
   */
  protected static function controlLabel(string $control): string {
    return (string) ($control === self::ADD_KEY ? t('Add another') : t('Remove'));
  }

  /**
   * Names a "Remove" button for the item it takes out.
   *
   * @param string|\Stringable $item
   *   The item's title, "Volunteers 2".
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The accessible name, "Remove Volunteers 2".
   */
  protected static function removeLabel(string|\Stringable $item): TranslatableMarkup {
    return t('Remove @item', ['@item' => $item]);
  }

  /**
   * Reads what every element a rebuild may replace is drawn as.
   *
   * @param array $element
   *   The container, or an element inside it.
   *
   * @return array<string, array>
   *   Each replaceable element as drawing() reads it, by dotted path.
   */
  public static function drawnAs(array $element): array {
    $found = [];
    foreach (static::elementChildren($element) as $key) {
      $child = $element[$key];
      if (isset($child[Refreshing::REFRESH_KEY])) {
        $found[(string) $child[Refreshing::REFRESH_KEY]] = static::drawing($child);
      }
      $found += static::drawnAs($child);
    }
    return $found;
  }

  /**
   * Reads what an element says to a person, as far as a rebuild moves it.
   *
   * The same before Form API processes the element and after, so the
   * form the page was drawn from and the rebuilt one compare: a group's
   * fields are its own children, which processing adds none to, while a
   * select's or a radios' options are compared by optionsSignature().
   *
   * @param array $element
   *   The element.
   *
   * @return array{label: mixed, applies: bool, required: bool, min: mixed, max: mixed, options: string|null, children: array|null}
   *   What it says.
   */
  public static function drawing(array $element): array {
    // The marker a person is shown: a select keeping a stale value is not
    // checked as required, and keeps the marker on its label.
    $marked = in_array('form-required', (array) ($element['#label_attributes']['class'] ?? []), TRUE);
    return [
      'label' => isset($element['#title']) ? (string) $element['#title'] : NULL,
      'applies' => empty($element[SurfaceFormBuilderInterface::PLACEHOLDER_KEY]),
      'required' => !empty($element['#required']) || !empty($element[SurfaceFormBuilderInterface::REQUIRED_KEY]) || $marked,
      'min' => isset($element['#min']) ? (string) $element['#min'] : NULL,
      'max' => isset($element['#max']) ? (string) $element['#max'] : NULL,
      'options' => Refreshing::optionsSignature($element),
      'children' => in_array($element['#type'] ?? NULL, Refreshing::GROUPING_TYPES, TRUE) ? static::elementChildren($element) : NULL,
    ];
  }

  /**
   * Adds one element per group its keys' hints name, at any depth.
   *
   * A group is drawn where its first key is declared: its element, a
   * fieldset or a details, goes into the frame directly before that key,
   * under GROUP_PREFIX and its id, with the group's own weight when it
   * declares one (a key's weight orders it inside its group). It holds
   * nothing yet: the keys stay where their values are posted until the
   * container is drawn (gatherGroups()). A cosmetic layer reaches it by
   * its key, to put it in vertical tabs, say.
   *
   * An object alone in its group (an attached structure, a map of keys
   * from the site) is drawn as one details: the group's, with the group's
   * label and collapse state, the object's description and required
   * marker moved to it, and the object a plain container inside it, so the
   * page does not show a details titled twice.
   *
   * Decision: see docs/decisions.md#arrangement-is-declared-once.
   *
   * @param array $element
   *   The frame: the container, or any element below it.
   *
   * @return array
   *   The frame, with an empty element per group, at any depth.
   */
  public static function withGroups(array $element): array {
    $groups = [];
    $members = [];
    foreach (static::elementChildren($element) as $key) {
      $element[$key] = static::withGroups($element[$key]);
      $group = $element[$key][SurfaceFormBuilderInterface::GROUP_KEY] ?? NULL;
      if (!is_array($group) || !is_string($group['id'] ?? NULL)) {
        continue;
      }
      $groups[$group['id']] ??= ['first' => (string) $key, 'group' => $group];
      $members[$group['id']] = ($members[$group['id']] ?? 0) + 1;
    }
    if ($groups === []) {
      return $element;
    }
    $before = array_column($groups, NULL, 'first');
    $framed = [];
    foreach ($element as $key => $value) {
      if (isset($before[(string) $key])) {
        $group = $before[(string) $key]['group'];
        $drawn = $group['as'] === 'details'
          ? ['#type' => 'details', '#title' => $group['label'], '#open' => empty($group['collapsed'])]
          : ['#type' => 'fieldset', '#title' => $group['label']];
        if (isset($group['weight'])) {
          $drawn['#weight'] = $group['weight'];
        }
        if (isset($group['description'])) {
          $drawn['#description'] = $group['description'];
        }
        if ($members[$group['id']] === 1 && is_array($value) && ($value['#type'] ?? NULL) === 'details' && !isset($value[self::LIST_KEY])) {
          // An object alone in its group: one details, the group's.
          $drawn += array_intersect_key($value, array_flip(['#description', '#required']));
          $own = ['#type', '#title', '#open', '#description', '#required'];
          $value = ['#type' => 'container'] + array_diff_key($value, array_flip($own));
        }
        $framed[SurfaceFormBuilderInterface::GROUP_PREFIX . $group['id']] = $drawn;
      }
      $framed[$key] = $value;
    }
    return $framed;
  }

  /**
   * Draws each grouped element inside its group's element, at any depth.
   *
   * Written into the group's element in place, so a borrowed element that
   * gathers groups by Form API's own `#group` (vertical tabs) draws the
   * group with what it holds. Every moved element keeps its #parents,
   * #name and refresh wrapper, so it posts where it always did and the
   * AJAX callback replaces it by the same id; within the group, Form API
   * sorts the members by weight.
   *
   * @param array $element
   *   The frame.
   *
   * @return array
   *   The frame, its grouped elements inside their groups.
   */
  public static function gatherGroups(array $element): array {
    foreach (static::elementChildren($element) as $key) {
      $group = $element[$key][SurfaceFormBuilderInterface::GROUP_KEY] ?? NULL;
      $into = is_array($group) && is_string($group['id'] ?? NULL) ? SurfaceFormBuilderInterface::GROUP_PREFIX . $group['id'] : NULL;
      if ($into !== NULL && $into !== (string) $key && isset($element[$into]) && is_array($element[$into])) {
        $element[$into][$key] = $element[$key];
        $element[$into]['#sorted'] = FALSE;
        unset($element[$key]);
      }
    }
    foreach (static::elementChildren($element) as $key) {
      $element[$key] = static::gatherGroups($element[$key]);
    }
    return $element;
  }

  /**
   * Moves each placed fieldset after the key it is placed after.
   *
   * A placed fieldset is a module's mount, a child of the grouping
   * container its frame's third-party settings render as
   * (`x-surface.after`); it moves to that frame, directly after the named
   * key, and keeps everything it carries: its #parents and #name, so its
   * inputs post where they always did, and its refresh wrappers, so the
   * AJAX callback replaces a key inside it by the same id.
   *
   * It moves under a key no data surface key can take, so it collides
   * with nothing; with a #weight, when the key it follows has one. A key
   * a group holds is followed inside that group, which the React app
   * draws alike.
   *
   * @param array $element
   *   The frame.
   *
   * @return array
   *   The frame, its placed fieldsets moved, at any depth.
   */
  public static function placeMounts(array $element): array {
    foreach (static::elementChildren($element) as $group) {
      foreach (static::elementChildren($element[$group]) as $mount) {
        $after = $element[$group][$mount][SurfaceFormBuilderInterface::PLACED_AFTER_KEY] ?? NULL;
        $holder = is_string($after) && $after !== (string) $group ? static::holding($element, $after) : NULL;
        if ($holder === NULL) {
          continue;
        }
        $placed = $element[$group][$mount];
        unset($element[$group][$mount]);
        $frame = $holder === '' ? $element : $element[$holder];
        if (isset($frame[$after]['#weight'])) {
          $placed['#weight'] = $frame[$after]['#weight'];
        }
        $reordered = [];
        foreach ($frame as $key => $value) {
          $reordered[$key] = $value;
          if ((string) $key === $after) {
            $reordered['@placed.' . $group . '.' . $mount] = $placed;
          }
        }
        if ($holder === '') {
          $element = $reordered;
        }
        else {
          $element[$holder] = $reordered;
        }
        if (static::elementChildren($element[$group]) === [] && !isset($element[$group][SurfaceFormBuilderInterface::REFRESH_ID_KEY])) {
          // Every fieldset it grouped is drawn elsewhere: a container
          // with nothing in it is drawn as nothing.
          $element[$group]['#access'] = FALSE;
        }
      }
    }
    foreach (static::elementChildren($element) as $key) {
      $element[$key] = static::placeMounts($element[$key]);
    }
    return $element;
  }

  /**
   * Finds which element of a frame holds a key, once groups are gathered.
   *
   * @param array $element
   *   The frame.
   * @param string $key
   *   The key a fieldset is placed after.
   *
   * @return string|null
   *   An empty string when the frame holds it itself, the group's element
   *   when a group drawn there holds it, or NULL when neither does.
   */
  protected static function holding(array $element, string $key): ?string {
    if (isset($element[$key]) && is_array($element[$key])) {
      return '';
    }
    foreach (static::elementChildren($element) as $child) {
      if (str_starts_with((string) $child, SurfaceFormBuilderInterface::GROUP_PREFIX) && isset($element[$child][$key]) && is_array($element[$child][$key])) {
        return (string) $child;
      }
    }
    return NULL;
  }

  /**
   * Wires each list of free values below an element to the rebuild.
   *
   * The list gets a wrapper of its own, which its buttons replace, and
   * each button a name unique in the form (Form API tells which was
   * pressed by name and label, and every "Remove" says the same), the
   * container's AJAX callback, and the path it sits at, which is how a
   * rebuild finds the container's input and what it replaces.
   *
   * @param array $element
   *   The container, or an element inside it.
   * @param string[] $path
   *   The element's path below the container.
   * @param string $wrapper_id
   *   The container's id.
   */
  public static function wireRows(array &$element, array $path, string $wrapper_id): void {
    foreach (static::elementChildren($element) as $key) {
      $at = [...$path, (string) $key];
      if (empty($element[$key][SurfaceFormBuilderInterface::ROWS_KEY])) {
        static::wireRows($element[$key], $at, $wrapper_id);
        continue;
      }
      $dotted = implode('.', $at);
      $element[$key][Refreshing::REFRESH_KEY] = $dotted;
      foreach (static::elementChildren($element[$key]) as $control) {
        if (!isset($element[$key][$control][SurfaceFormBuilderInterface::ROW_CONTROL_KEY])) {
          continue;
        }
        $named = array_map([Html::class, 'getId'], [...$at, (string) $control]);
        $element[$key][$control]['#name'] = 'surface_' . implode('_', $named);
        $element[$key][$control]['#ajax'] = [
          'callback' => [SurfaceFormBuilder::class, 'refreshSurface'],
          'wrapper' => $wrapper_id,
          'submit' => [SurfaceFormBuilderInterface::WRAPPER_INPUT => $wrapper_id],
        ];
        $element[$key][$control][SurfaceFormBuilderInterface::TRIGGER_KEY] = [
          'path' => [...$at, (string) $control],
          'replaces' => [$dotted],
        ];
      }
    }
  }

  /**
   * Reads the container id a refinement trigger sent back.
   *
   * Only an id this wrapper key could have generated is taken back: a
   * value from the request names an id on the page, so anything else is
   * ignored and a fresh one is generated.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the containing form.
   * @param string $wrapper_key
   *   The wrapper key the host builds the container with.
   *
   * @return string|null
   *   The id the browser holds, or NULL when the request sent none.
   */
  protected static function postedWrapperId(FormStateInterface $form_state, string $wrapper_key): ?string {
    $state = $form_state instanceof SubformStateInterface ? $form_state->getCompleteFormState() : $form_state;
    $posted = $state->getUserInput()[SurfaceFormBuilderInterface::WRAPPER_INPUT] ?? NULL;
    $generated = Html::getId($wrapper_key . '-wrapper');
    return is_string($posted)
      && preg_match('/^[A-Za-z0-9_-]+$/', $posted)
      && ($posted === $generated || str_starts_with($posted, $generated . '--'))
      ? $posted
      : NULL;
  }

  /**
   * Names the element a message is about, unless the message does.
   *
   * A constraint's own words name no field ("This value should be between
   * 1 and 1000."), which is enough beside the element and nowhere else:
   * printed at the top of the page, or announced, the message is put
   * after the element's title. One that already begins with the title,
   * and an element with no title, are left as they are.
   *
   * Decision: see docs/decisions.md#an-element-hints-and-the-rule-speaks.
   *
   * @param array $element
   *   The element the message is about.
   * @param string|\Stringable $message
   *   The message.
   *
   * @return string|\Stringable
   *   The message, named.
   */
  public static function named(array $element, string|\Stringable $message): string|\Stringable {
    $title = $element['#title'] ?? NULL;
    if (!is_string($title) && !$title instanceof \Stringable) {
      return $message;
    }
    $plain = trim(strip_tags((string) $title));
    if ($plain === '' || str_starts_with((string) $message, $plain)) {
      return $message;
    }
    return t('@field: @message', ['@field' => $title, '@message' => $message], [
      'context' => 'A form error, after the label of the field it is about',
    ]);
  }

  /**
   * Appends the reason a locked element cannot be changed.
   *
   * A disabled input tells a sighted user it is fixed and tells a screen
   * reader nothing about why, so the reason goes in the description
   * where every user reaches it: the document's `x-surface.locked`, when
   * the answer that locked the key said why, and otherwise a sentence
   * that says only that it is fixed.
   *
   * Decision: see docs/decisions.md#locked-at-the-top.
   *
   * @param array $element
   *   The locked element.
   * @param string|null $reason
   *   Why the access answer locked it, or NULL when none said.
   *
   * @return array
   *   The element, with the reason appended to its description.
   */
  protected static function describeLocked(array $element, ?string $reason = NULL): array {
    $note = $reason ?? (string) t('Fixed for this operation.');
    $element['#description'] = isset($element['#description']) && (string) $element['#description'] !== ''
      ? $element['#description'] . ' ' . $note
      : $note;
    return $element;
  }

  /**
   * Takes the required marker off an element and everything inside it.
   *
   * For a data surface locked whole: nothing on the form can be sent, so
   * nothing is asked for, and a marker would say otherwise.
   *
   * @param array $element
   *   The element.
   *
   * @return array
   *   The element, nothing in it required.
   */
  protected static function withoutRequired(array $element): array {
    unset($element['#required']);
    foreach (static::elementChildren($element) as $key) {
      $element[$key] = static::withoutRequired($element[$key]);
    }
    return $element;
  }

  /**
   * Names the child elements of a render array.
   *
   * Core's Element::children() sorts and reads #weight, which is more
   * than any walk here needs. All that is wanted is "the keys that are
   * elements": never a render key, never a scalar.
   *
   * @param array $element
   *   The render array.
   *
   * @return array<int|string>
   *   The child keys.
   */
  public static function elementChildren(array $element): array {
    $children = [];
    foreach ($element as $key => $child) {
      if (is_array($child) && !(is_string($key) && str_starts_with($key, '#'))) {
        $children[] = $key;
      }
    }
    return $children;
  }

}
