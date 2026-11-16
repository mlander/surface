<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\Validation\ConstraintManager;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Plugin\SurfaceWidget\AutocompleteWidget;
use Drupal\surface\Plugin\SurfaceWidget\CheckboxesWidget;
use Drupal\surface\Plugin\SurfaceWidget\OptionsWidget;
use Drupal\surface\Plugin\SurfaceWidget\RadiosWidget;
use Drupal\surface\Options\SurfaceOptions;
use Drupal\surface\Options\OptionSet;
use Drupal\surface\Pipeline\Outbound;
use Drupal\surface\Pipeline\ValueState;
use Drupal\surface\Reference\References;
use Drupal\surface\Resource\Arrangement;
use Drupal\surface\Plugin\SurfaceWidget\StringWidget;
use Drupal\surface\Widget\SurfaceWidgetBase;
use Drupal\surface\Widget\SurfaceWidgetInterface;
use PortableSchema\Builder\Schema;

/**
 * Describes one key as a JSON Schema property, with its `x-surface`.
 *
 * ContractEmitter walks a document's frames and asks this class for each
 * key that is not a child (property()): the projection of its built node
 * (the node its entry holds, Entry::schema(), projected by
 * DefinitionSchema::publishedNode() for the request), which says
 * its type, the keywords its constraints state and what only the server
 * checks, decorated with what the request adds: its allowed values as
 * `oneOf` with their labels, read from the options service, its widget
 * hints (`x-portable-ui`), its `x-surface`, a lock and what is computed;
 * ContractEmitter adds its `lookup` link (`x-portable-links`). A key
 * declared with a
 * shape is written whole, and named() files the shape's def once and
 * turns the key into a reference to it. A child's site is a reference to
 * its def with only what differs from it (reference(), narrowing()).
 *
 * It holds no state of a document: what a description reads of one comes
 * in the Emission it is handed.
 *
 * Decision: see
 * docs/decisions.md#the-emitter-is-one-walk-and-three-writers.
 *
 * @internal
 *
 * @see \Drupal\surface\Contract\ContractEmitter
 */
final class PropertySchema {

  use StringTranslationTrait;

  /**
   * The extension keyword, ContractEmitter's.
   */
  protected const EXTENSION = ContractEmitter::EXTENSION;

  /**
   * The keyword a key declared with a shape carries until it refers to it.
   *
   * Each such key is written whole by property(), and beside it the
   * shape's id and its def; named() then files the def once under `$defs`
   * and turns the key into a reference to it with what the key says
   * differently. Never in a document.
   */
  public const SHAPED = self::EXTENSION . '-shape';

  /**
   * Constructs a PropertySchema.
   *
   * @param \Drupal\surface\Options\SurfaceOptions $options
   *   The options service, the one place a constraint is read as a list.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service, for the empty option's labels.
   * @param \Drupal\Core\Validation\ConstraintManager|null $constraints
   *   The constraint plugin manager, for what a constraint only the server
   *   checks says it checks (its label); without it, its id.
   * @param \Drupal\surface\Contract\WidgetChoice|null $widgets
   *   The widget choice, which says what a module's widget claims or
   *   draws; without it, the stock rule alone names the widget.
   * @param \Drupal\surface\Contract\DefinitionSchema|null $definitions
   *   The projection of a built node to a document; without it, one of
   *   its own.
   */
  public function __construct(
    protected readonly SurfaceOptions $options,
    TranslationInterface $string_translation,
    protected readonly ?ConstraintManager $constraints,
    protected readonly ?WidgetChoice $widgets,
    ?DefinitionSchema $definitions = NULL,
  ) {
    $this->stringTranslation = $string_translation;
    $this->definitions = $definitions ?? new DefinitionSchema($options, $constraints);
  }

  /**
   * Projects each built node to the document form.
   */
  protected readonly DefinitionSchema $definitions;

  /**
   * Reads a collection's own definition into its node, without its items.
   *
   * A collection's item is the child every item is, which its own entries
   * describe, so the list's node is read alone (DefinitionSchema::head()):
   * its facts and keywords, never the child's whole tree.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The list, as the parent says it.
   *
   * @return \PortableSchema\Builder\Schema
   *   Its built node, with no `items`.
   */
  public function listNode(DataDefinitionInterface $definition): Schema {
    return DefinitionSchema::head($definition);
  }

  /**
   * States what a collection's own definition says, without its items.
   *
   * @param \PortableSchema\Builder\Schema $head
   *   The list's node (listNode()).
   *
   * @return array{0: \PortableSchema\Builder\Schema, 1: array}
   *   Its title, description, `type` and keywords (`minItems`,
   *   `maxItems`); and the constraints no keyword states, as
   *   `portable-check` and their messages, which go right before its
   *   `x-surface` (PortableKeywords::before()).
   */
  public function listKeywords(Schema $head): array {
    $schema = $this->definitions->publishedNode($head, FALSE);
    $checks = array_intersect_key($schema->all(), array_flip([PortableKeywords::CHECK, PortableKeywords::MESSAGES]));
    foreach (array_keys($checks) as $keyword) {
      $schema = $schema->without($keyword);
    }
    return [$schema, $checks];
  }

  /**
   * Writes the reference a site makes to a def, narrowed where it must be.
   *
   * A site whose instance says what its def says refers to it and nothing
   * more. One that differs (a key the site's context locked, a key
   * refined for the values in this document) composes the def with what
   * differs: `allOf: [{$ref}, {narrowing}]`, the narrowing holding only
   * the keywords that changed. Refinement only narrows, so a validator
   * applying both accepts what the instance accepts.
   *
   * @param string $key
   *   The def's key.
   * @param \PortableSchema\Builder\Schema|null $instance
   *   The child's schema at this site, or NULL to refer to the def alone.
   * @param \Drupal\surface\Contract\Subschemas|null $subschemas
   *   The document's defs, to compare the instance with.
   *
   * @return \PortableSchema\Builder\Schema
   *   `$ref`, or `allOf` of it and the narrowing.
   */
  public function reference(string $key, ?Schema $instance = NULL, ?Subschemas $subschemas = NULL): Schema {
    $reference = Schema::any()->with('$ref', ContractEmitter::DEFS . ContractEmitter::pointer($key));
    // A def written open, for an owner to mix it in, is closed where it is
    // used whole. Decision: see docs/decisions.md#a-mixin-is-merged-flat.
    $closed = $subschemas !== NULL && $subschemas->isOpen($key) ? ['unevaluatedProperties' => FALSE] : [];
    $def = $subschemas?->get($key);
    if ($instance === NULL || $def === NULL) {
      return Schemas::defaults($reference, $closed);
    }
    if ($closed !== []) {
      // The site closes it: the narrowing says only what differs.
      $instance = $instance->without('additionalProperties')->without('unevaluatedProperties');
    }
    $narrowing = $this->narrowing($instance, $def, $subschemas->transient, $subschemas->ui);
    $composed = $narrowing->all() === [] ? $reference : Schema::any()->with('allOf', [$reference, $narrowing]);
    return Schemas::defaults($composed, $closed);
  }

  /**
   * Keeps only what an instance says differently from its def.
   *
   * The same reading as Conditionals::narrowed(), for a whole frame: a
   * frame's
   * properties are compared one by one; its conditionals keep only those
   * the def does not already state; and a property's `x-surface` and
   * `x-portable-ui`, which a renderer reads whole, are written whole
   * wherever either differs, less what the document strips anyway, with
   * its `portable-check` and `x-portable-messages`. Its
   * `x-portable-links` are compared on their own and written where they
   * differ: a def holds none, a site's key its own.
   *
   * @param \PortableSchema\Builder\Schema $instance
   *   The instance.
   * @param \PortableSchema\Builder\Schema $def
   *   The def.
   * @param string[] $transient
   *   The `x-surface` keywords not compared.
   * @param string[] $ui
   *   The `x-portable-ui` members and options not compared.
   *
   * @return \PortableSchema\Builder\Schema
   *   The keywords that differ; the empty schema when none do.
   */
  public function narrowing(Schema $instance, Schema $def, array $transient, array $ui = []): Schema {
    $narrowing = [];
    // A property's `x-surface`, its hints, and what it names only the
    // server checks and the messages it gives, are one: written whole
    // where any differs.
    $said = array_merge(PortableKeywords::KEYWORDS, [self::EXTENSION]);
    $checked = array_flip([PortableKeywords::CHECK, PortableKeywords::MESSAGES]);
    $whole = static fn (Schema $schema): array => array_intersect_key($schema->all(), $checked) + [
      PortableUi::KEYWORD => PortableUi::without((array) ($schema->get(PortableUi::KEYWORD) ?? []), $ui),
      self::EXTENSION => array_diff_key((array) ($schema->get(self::EXTENSION) ?? []), array_flip($transient)),
    ];
    $differs = !Schemas::same($whole($instance), $whole($def));
    // A link names a site, so it is the site's alone: no BC reason ties it
    // to the rest. Decision: see
    // docs/decisions.md#a-reference-and-a-deferred-list-are-the-dialects-check-options-and-links.
    $linked = !Schemas::same($instance->get(PortableKeywords::LINKS), $def->get(PortableKeywords::LINKS));
    $declared = $def->get('properties');
    foreach ($instance->all() as $keyword => $value) {
      if ($keyword === PortableKeywords::LINKS) {
        if ($linked) {
          $narrowing[$keyword] = $value;
        }
        continue;
      }
      if (in_array($keyword, $said, TRUE)) {
        if ($differs) {
          $narrowing[$keyword] = $value;
        }
        continue;
      }
      // A def whose frame has no key compares its properties whole.
      if ($keyword === 'properties' && is_array($value) && is_array($declared) && $declared !== []) {
        $properties = [];
        foreach ($declared + $value as $name => $property) {
          if (!array_key_exists($name, $value)) {
            // Ruled out at this site: it must not be there.
            $properties[$name] = FALSE;
            continue;
          }
          $stated = $declared[$name] ?? NULL;
          if ($value[$name]->isBoolean() || $stated?->isBoolean()) {
            // A mixed-in structure's member, `true` or `false`, said whole.
            if (!Schemas::same($value[$name], $stated)) {
              $properties[$name] = $value[$name];
            }
            continue;
          }
          $changed = $this->narrowing($value[$name], $stated ?? Schema::any(), $transient, $ui);
          if ($changed->all() !== []) {
            $properties[$name] = $changed;
          }
        }
        if ($properties !== []) {
          $narrowing['properties'] = $properties;
        }
        continue;
      }
      if ($keyword === 'allOf' && is_array($value) && is_array($def->get('allOf'))) {
        $stated = $def->get('allOf');
        $added = array_values(array_filter($value, static fn (Schema $condition): bool => array_filter($stated, static fn (Schema $said): bool => Schemas::same($said, $condition)) === []));
        if ($added !== []) {
          $narrowing['allOf'] = $added;
        }
        continue;
      }
      if ($keyword === 'required' && is_array($value) && is_array($def->get('required'))) {
        // JSON Schema holds a document to every `required` it meets: the
        // def's already holds, so the narrowing says what it adds.
        $added = array_values(array_diff($value, $def->get('required')));
        if ($added !== []) {
          $narrowing['required'] = $added;
        }
        continue;
      }
      if (!$def->has($keyword) || !Schemas::same($def->get($keyword), $value)) {
        $narrowing[$keyword] = $value;
      }
    }
    return Schema::keywords(self::withoutStatedTwins($narrowing, $instance, $def));
  }

  /**
   * Names the checks a frame's schema holds, where it holds nothing else.
   *
   * A schema read into a frame that is only `portable-check` naming this
   * site's checks (an author's check on the items of a collection) is
   * written as those checks, each as a map's own is said
   * (DefinitionSchema::entry()).
   *
   * @param array $fragment
   *   The frame's schema.
   *
   * @return list<array>|null
   *   The entries, for PortableKeywords::write(); NULL where the schema
   *   says anything else.
   */
  public function namedFrameChecks(array $fragment): ?array {
    $checks = $fragment[PortableKeywords::CHECK] ?? NULL;
    if (array_keys($fragment) !== [PortableKeywords::CHECK] || !is_array($checks) || $checks === []) {
      return NULL;
    }
    $entries = [];
    foreach ($checks as $name => $options) {
      $name = (string) $name;
      if (!str_starts_with($name, PortableKeywords::PROVIDER) || $name === PortableKeywords::PROVIDER . 'JsonSchema') {
        return NULL;
      }
      $entries[] = $this->definitions->entry(substr($name, strlen(PortableKeywords::PROVIDER)), json_decode((string) json_encode($options), TRUE));
    }
    return $entries;
  }

  /**
   * Drops a format's twin from a narrowing that does not write the format.
   *
   * A twin travels with its format (ConstraintKeywords::FORMAT_TWINS):
   * where the def states the format and the narrowing drops it, the def
   * names the twin too, so the narrowing drops the twin and its messages
   * where the def holds the same.
   *
   * @param array $narrowing
   *   The keywords that differ.
   * @param \PortableSchema\Builder\Schema $instance
   *   The instance.
   * @param \PortableSchema\Builder\Schema $def
   *   The def.
   *
   * @return array
   *   The same, less a twin the def names beside its format.
   */
  protected static function withoutStatedTwins(array $narrowing, Schema $instance, Schema $def): array {
    $format = $instance->get('format');
    $checks = $narrowing[PortableKeywords::CHECK] ?? NULL;
    if (array_key_exists('format', $narrowing) || $format === NULL || $def->get('format') !== $format || !is_array($checks)) {
      return $narrowing;
    }
    $stated = (array) ($def->get(PortableKeywords::CHECK) ?? []);
    $messages = (array) ($narrowing[PortableKeywords::MESSAGES] ?? []);
    $said = (array) ($def->get(PortableKeywords::MESSAGES) ?? []);
    foreach ($checks as $name => $options) {
      $name = (string) $name;
      $twin = str_starts_with($name, PortableKeywords::PROVIDER) && ConstraintKeywords::isFormatTwin(substr($name, strlen(PortableKeywords::PROVIDER)), $format);
      if (!$twin || !array_key_exists($name, $stated) || !Schemas::same($stated[$name], $options)) {
        continue;
      }
      unset($checks[$name]);
      foreach ($messages as $code => $message) {
        $code = (string) $code;
        if (($code === PortableKeywords::CHECK_CODE . $name || str_starts_with($code, PortableKeywords::CHECK_CODE . $name . '.')) && array_key_exists($code, $said) && Schemas::same($said[$code], $message)) {
          unset($messages[$code]);
        }
      }
    }
    if ($checks === []) {
      unset($narrowing[PortableKeywords::CHECK]);
    }
    else {
      $narrowing[PortableKeywords::CHECK] = $checks;
    }
    if (array_key_exists(PortableKeywords::MESSAGES, $narrowing)) {
      if ($messages === []) {
        unset($narrowing[PortableKeywords::MESSAGES]);
      }
      else {
        $narrowing[PortableKeywords::MESSAGES] = $messages;
      }
    }
    return $narrowing;
  }

  /**
   * Describes one key that is not a child, recursing through maps.
   *
   * Every fact of the key is read from its node, and the options service
   * is handed the node (optionSet(), signature()). The definition is
   * walked beside the node, in step, for what still takes one until slice
   * 32: the widget claims (WidgetChoice, a widget's drawsDefinition() and
   * its stock rules).
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key as it stands, refined, for the widget claims.
   * @param \PortableSchema\Builder\Schema $node
   *   The definition's built node: its entry's (Entry::schema()), the node
   *   of the property or item below it that its parent's node holds, or,
   *   for a definition no entry holds (a conditional's answer), the read
   *   its caller made (DefinitionSchema::of()). Nothing is read here.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $advertised
   *   The key as advertised, to tell whether it is narrowed now; NULL
   *   when there is nothing to compare with.
   * @param \PortableSchema\Builder\Schema|null $advertised_node
   *   The advertised definition's built node; NULL with no $advertised.
   * @param mixed $value
   *   The value the key's element would be built with.
   * @param string $path
   *   The key's dotted path.
   * @param bool $locked
   *   Whether the key is locked.
   * @param string[] $depends
   *   The sibling keys its refiners watch; a mounted one by its dotted
   *   path in the frame.
   * @param \Drupal\surface\Contract\Emission $emission
   *   The walk: its cacheability collects what the description depends
   *   on, and its stale paths the dotted paths of stale values.
   *
   * @return array{0: \PortableSchema\Builder\Schema, 1: mixed}
   *   The property schema, and its value as shown: a secret below the key
   *   as nothing held, as the frame shows a secret key, and left out of
   *   a list's items, a list of secrets as nothing held.
   */
  public function property(DataDefinitionInterface $definition, Schema $node, ?DataDefinitionInterface $advertised, ?Schema $advertised_node, mixed $value, string $path, bool $locked, array $depends, Emission $emission): array {
    $cacheability = $emission->cacheability;
    $shown = $value;
    $list = DefinitionSchema::isList($node);
    $item = $list ? static::itemDefinition($definition, $path) : NULL;
    $set = $this->optionSet($item === NULL ? $node : DefinitionSchema::itemNode($node) ?? $item, $cacheability);
    // A list of no values is still a list: a select offering nothing.
    // Decision: see docs/decisions.md#a-key-that-does-not-apply.
    $listed = $set !== NULL;
    $refined = $advertised !== NULL && $advertised_node !== NULL && $this->signature($advertised_node) !== $this->signature($node);
    // The node as the document says it; its properties and items are each
    // described below as their own key, and what the request adds goes
    // between what the definition says, where it always stood.
    // The read is the definition's alone; whether the key offers a list,
    // and so what its list states, is the projection's. Below the key, the
    // node is walked in step with the definition, never read again.
    // Decision: see
    // docs/decisions.md#a-definition-is-read-by-a-pure-function-the-projection-holds-the-request,
    // docs/decisions.md#an-entry-holds-its-node-the-memo-is-gone.
    $schema = $this->definitions->publishedNode($node, $listed);
    $held = [];
    foreach (['writeOnly', 'default', 'examples', PortableKeywords::CHECK, PortableKeywords::MESSAGES] as $keyword) {
      if ($schema->has($keyword)) {
        $held[$keyword] = $schema->get($keyword);
        $schema = $schema->without($keyword);
      }
    }

    if ($item !== NULL) {
      $item_node = DefinitionSchema::itemNode($node) ?? throw new \LogicException(sprintf('The node at "%s" holds no item node where its definition holds an item.', $path));
      [$items] = $this->property($item, $item_node, NULL, NULL, NULL, $path . '.*', FALSE, [], $emission->withoutStale());
      $schema = $schema->with('items', static::neverNull($items));
      // Decision: see docs/decisions.md#a-widget-is-chosen-from-the-definition.
      $checkboxes = $set !== NULL && CheckboxesWidget::drawsList($definition, $set);
      $extension = $this->extension($depends, $refined);
      // A module's widget that claims the list, or draws it by a setting
      // of its own: the word it answers to.
      $module = $this->widgets?->module($definition);
      // A list the list widget does not draw, its items lists or of any
      // type, is the JSON widget's, which no document names: a list of
      // maps is a collection, the form builder's.
      $json = $module === NULL && !$listed && !is_a(DefinitionSchema::classOf($item_node), ComplexDataDefinitionInterface::class, TRUE) && $this->widgets?->wordFor($definition) === 'json';
      $ui = match (TRUE) {
        $module !== NULL => PortableUi::write($this->widgets->wordOf($module), $this->published($module, $node)),
        $checkboxes => PortableUi::write($this->word('checkboxes')),
        $listed => PortableUi::write($this->word('select'), ['multiple' => TRUE]),
        $json => [],
        default => PortableUi::write($this->word('list')),
      };
      // A list's items are shown whole: a secret inside them is left out,
      // and a list of secrets holds nothing shown.
      // Decision: see docs/decisions.md#a-secret-is-not-served-at-any-depth.
      $secrets = is_array($value) ? Outbound::secretsOf($node) : [];
      $shown = match (TRUE) {
        !is_array($value) => $value,
        $secrets === TRUE => NULL,
        default => array_values($secrets === [] ? $value : Outbound::withoutSecrets($value, $secrets)),
      };
    }
    elseif (!$listed && DefinitionSchema::nodeIsMap($node)) {
      $properties = [];
      $nested = [];
      $before = $advertised_node === NULL ? [] : NodeFacts::properties($advertised_node);
      foreach (NodeFacts::properties($node) as $name => $property_node) {
        $name = (string) $name;
        if (NodeFacts::doesNotApply($property_node)) {
          // A key an alter mounted, ruled out: as at the top of a frame.
          continue;
        }
        $was = isset($before[$name]) && $advertised !== NULL ? static::propertyDefinition($advertised, $name, $path) : NULL;
        // A secret below the key is shown as nothing held, as the frame
        // shows a secret key.
        // Decision: see docs/decisions.md#a-secret-is-not-served-at-any-depth.
        $inner = is_array($value) && !Secrets::marked($property_node) ? ($value[$name] ?? NULL) : NULL;
        [$properties[$name], $nested[$name]] = $this->property(static::propertyDefinition($definition, $name, $path), $property_node, $was, $was === NULL ? NULL : $before[$name], $inner, $path . '.' . $name, FALSE, [], $emission);
      }
      $schema = $schema->with('properties', $properties);
      $extension = $this->extension($depends, $refined || $this->anyRefined($schema));
      $ui = PortableUi::write($this->asked(NodeFacts::setting($node, 'widget')) ?? $this->word('fieldset'));
      if (NodeFacts::isGrouping($node)) {
        // Only groups the fieldsets inside it, as on the form: no title
        // and no description to draw, and `group` says to draw none.
        $schema = $schema->without('title')->without('description');
        $extension['group'] = TRUE;
      }
      $after = NodeFacts::placedAfter($node);
      if ($after !== NULL) {
        // A module's fieldset its alter placed: drawn after that key of
        // the frame its group sits in. Only drawn there; its properties
        // stay where its values are posted.
        $extension['after'] = $after;
      }
      // A map whose every key is ruled out is still a map.
      $shown = $nested === [] ? new \stdClass() : $nested;
    }
    else {
      $radios = $listed && RadiosWidget::drawsOne($definition);
      $fixed = $listed && OptionsWidget::drawsFixed($definition, $set, $value);
      // A reference with too many candidates to list is looked up.
      // Decision: see
      // docs/decisions.md#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up.
      // A module's widget that claims the key, unless its owner asked for
      // one by name: the word it answers to.
      // Decision: see docs/decisions.md#a-widget-is-chosen-from-the-definition.
      $claimed = $this->widgets?->module($definition);
      $widget = match (TRUE) {
        $claimed !== NULL => $this->widgets->wordOf($claimed),
        // A reference that asks for the autocomplete by its word is drawn
        // with it, listed or not (SurfaceWidgetManager::getWidgetFor()).
        AutocompleteWidget::draws($definition) && NodeFacts::setting($node, 'widget') === 'autocomplete' => $this->word('autocomplete'),
        $fixed => $this->word('fixed'),
        $listed => $this->word($radios ? 'radios' : 'select'),
        // A bare reference's id is drawn once its entity type is chosen:
        // listed, or looked up.
        AutocompleteWidget::draws($definition), NodeFacts::setting($node, References::TARGETS) !== NULL => $this->word('autocomplete'),
        default => $this->word(WidgetChoice::valueWord($definition)),
      };
      $extension = $this->extension($depends, $refined);
      $ui = PortableUi::write($widget, $claimed !== NULL ? $this->published($claimed, $node) : $this->stringSettings($widget, $node));
      if ($fixed) {
        // A deciding key whose variants offer one value, or a key its
        // context fixed to one: that value, and the label it is offered
        // as, which the form draws fixed.
        // Decision: see
        // docs/decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter.
        // Decision: see
        // docs/decisions.md#a-route-may-fill-or-fix-what-an-operation-asks-for.
        $only = $this->choices($set, $node)[0];
        $schema = $schema->with('const', $only->get('const'));
        $extension['fixed'] = $only->get('title');
      }
      elseif ($listed) {
        $schema = $schema->with('oneOf', $this->choices($set, $node));
        // Decision: see docs/decisions.md#the-empty-option-rule.
        $configured = ValueState::isConfigured($value);
        $chosen = $configured && $set->allows($value);
        $holds = NodeFacts::holdsValue($node);
        // Radios show nothing checked where a select shows its empty
        // option, so their "- None -" is there only where choosing
        // nothing is an answer.
        // A select standing for a stale value says it keeps it, as the
        // form's does; ContractEmitter::frame() words an orphan's as it was.
        $ui['options']['emptyOption'] = [
          'show' => $radios ? !$holds : !($chosen && $holds),
          'label' => (string) match (TRUE) {
            !$radios && $configured && !$chosen => OptionsWidget::keepLabel($value),
            $holds && !$radios => $this->t('- Select -'),
            default => $this->t('- None -'),
          },
        ];
        if ($configured && !$chosen) {
          // Shown on the empty option, standing for the stored value,
          // which stays on the server: the caller sends the path back
          // and an empty answer there keeps what is stored.
          $extension['stale'] = TRUE;
          $emission->markStale($path);
          $shown = NULL;
        }
      }
    }

    // What a value holds by default, or that it is a secret, as the
    // definition says it.
    foreach (['writeOnly', 'default', 'examples'] as $keyword) {
      if (array_key_exists($keyword, $held)) {
        $schema = $schema->with($keyword, $held[$keyword]);
      }
    }
    if ($locked) {
      $schema = $schema->with('readOnly', TRUE);
      if (ValueState::isConfigured($value)) {
        $schema = $schema->with('const', $value);
      }
    }
    $computed = NodeFacts::compute($node);
    if ($computed !== NULL && !$locked) {
      // Shown, never offered: a value nobody types, with no `const`, since
      // what it holds follows the keys it is computed from. One computed
      // unless sent is offered, and computed when left out.
      // Decision: see
      // docs/decisions.md#a-computed-key-is-a-pure-function-of-its-sources.
      if (!$computed->takesSent()) {
        $schema = $schema->with('readOnly', TRUE);
      }
      $extension['computed'] = $computed->marker();
    }
    // What only the server checks, and the messages, in the dialect's own
    // keywords, after the standard ones and before `x-surface`.
    // Decision: see
    // docs/decisions.md#the-served-document-is-a-portable-schema-document.
    $checks = array_flip([PortableKeywords::CHECK, PortableKeywords::MESSAGES]);
    $schema = Schemas::defaults($schema, array_intersect_key($held, $checks));
    // The widget hints, how the key is drawn and never what it accepts,
    // in the dialect's keyword, before `x-surface`: its widget, its
    // arrangement and its empty option. Decision: see
    // docs/decisions.md#the-ui-hints-are-x-portable-ui.
    $schema = PortableUi::set($schema, $this->hints($ui, NodeFacts::arrangement($node)));
    // Written where nothing is said, so it stands where it did before the
    // hints moved; PortableUi::settled() leaves an empty one out.
    $schema = $schema->with(self::EXTENSION, $extension);
    $id = NodeFacts::shape($node);
    if ($id !== NULL && !$schema->has('properties') && !$schema->has('items')) {
      // Written whole here, so a conditional, a site and a def compare it
      // keyword by keyword as any key; named() refers to the shape at the
      // end. Decision: see docs/decisions.md#a-shape-is-a-named-definition.
      // The shape's definition is walked beside its node, as every
      // property's is, for the options service and the widget claims.
      $shape = DefinitionMetadata::shapeDefinition($definition) ?? throw new \LogicException(sprintf('The node at "%s" holds a shape its definition does not: the two are out of step.', $path));
      $schema = $schema->with(self::SHAPED, [
        'id' => $id,
        'def' => $this->shapeDef($this->property($shape, DefinitionSchema::shapeNode($node) ?? DefinitionSchema::of($shape), NULL, NULL, NULL, $path, FALSE, [], $emission->withoutStale())[0]),
      ]);
    }
    return [$schema, $shown];
  }

  /**
   * Gets a list's item definition, the step a node's walk takes beside it.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The list's definition.
   * @param string $path
   *   The list's dotted path, for the exception.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   Its item's definition.
   *
   * @throws \LogicException
   *   When the definition is no list: the node was read from it, so the
   *   two are out of step.
   */
  protected static function itemDefinition(DataDefinitionInterface $definition, string $path): DataDefinitionInterface {
    return $definition instanceof ListDataDefinitionInterface ? $definition->getItemDefinition() : throw new \LogicException(sprintf('The node at "%s" is a list its definition is not: the two are out of step.', $path));
  }

  /**
   * Gets a property's definition, the step a node's walk takes beside it.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The map's definition.
   * @param string $name
   *   The property, as the map's node names it.
   * @param string $path
   *   The map's dotted path, for the exception.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   Its definition.
   *
   * @throws \LogicException
   *   When the definition holds no such property: the node was read from
   *   it, so the two are out of step.
   */
  protected static function propertyDefinition(DataDefinitionInterface $definition, string $name, string $path): DataDefinitionInterface {
    $property = $definition instanceof ComplexDataDefinitionInterface ? $definition->getPropertyDefinition($name) : NULL;
    return $property ?? throw new \LogicException(sprintf('The node at "%s" holds a property "%s" its definition does not: the two are out of step.', $path, $name));
  }

  /**
   * Keeps what a shape's def says: the shape's own, not a key's.
   *
   * The shape is described as an optional key is, so its def allows null,
   * since whether a key may hold nothing is the key's; a required key's
   * site says `type` without it. What the shape checks stays: the
   * message of its pattern and what only the server checks
   * (`x-portable-messages` and `portable-check`). Its `x-surface` and
   * `x-portable-ui` go: the widget, the lock and what a key depends on are
   * each key's.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The shape, described as a key.
   *
   * @return \PortableSchema\Builder\Schema
   *   Its def.
   */
  protected function shapeDef(Schema $schema): Schema {
    return $schema->without(self::EXTENSION)->without(PortableUi::KEYWORD)->without(PortableKeywords::LINKS);
  }

  /**
   * Refers every key declared with a shape to the shape's def.
   *
   * Each shape is written once, under `$defs` by its id, as its alters
   * left it, and each key declared with it becomes `$ref` to it first,
   * then the key's title, then what the key says differently from the
   * def (its type without null where it is required, a default, a
   * constraint it narrows, a description of its own), its
   * `portable-check` and `x-portable-messages`, and its whole
   * `x-surface`, as every property's. `$ref` first, so a reader that lays
   * the def where the reference stood lays the key over it. A key a
   * refinement or a conditional narrows is compared as written, so a
   * `then` says only what it narrows and refers to nothing.
   *
   * Decision: see docs/decisions.md#a-shape-is-a-named-definition.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The document's schema, its `$defs` written.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema, each shape a def.
   */
  public function named(Schema $schema): Schema {
    $defs = $schema->get('$defs');
    $taken = is_array($defs) ? array_map('strval', array_keys($defs)) : [];
    $named = [];
    $schema = Schemas::map($schema, static function (Schema $node) use (&$named, $taken): Schema {
      return self::referToShape($node, $named, $taken);
    });
    if ($named !== []) {
      $defs = $schema->get('$defs');
      $schema = $schema->with('$defs', (is_array($defs) ? $defs : []) + $named);
    }
    return $schema;
  }

  /**
   * Refers one key declared with a shape to the shape's def.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A schema in the document.
   * @param array<string, \PortableSchema\Builder\Schema> $named
   *   The shapes' defs filed so far, by key.
   * @param string[] $taken
   *   The keys the document's other defs hold.
   *
   * @return \PortableSchema\Builder\Schema
   *   The same, or the reference it becomes.
   */
  protected static function referToShape(Schema $node, array &$named, array $taken): Schema {
    $shape = $node->get(self::SHAPED);
    if (!is_array($shape) || !is_string($shape['id'] ?? NULL) || !($shape['def'] ?? NULL) instanceof Schema) {
      return $node;
    }
    ['id' => $id, 'def' => $def] = $shape;
    $node = $node->without(self::SHAPED);
    $key = $id;
    for ($n = 2; in_array($key, $taken, TRUE) || (isset($named[$key]) && !Schemas::same($named[$key], $def)); $n++) {
      $key = $id . '@' . $n;
    }
    $named[$key] ??= $def;
    $site = ['$ref' => ContractEmitter::DEFS . ContractEmitter::pointer($key)];
    foreach ($node->all() as $keyword => $value) {
      // The key's own `x-surface`, and what it checks and says, stay
      // with it, as they stood before the dialect spelled the last two.
      if ($keyword === 'title' || $keyword === self::EXTENSION || in_array($keyword, PortableKeywords::KEYWORDS, TRUE) || !$def->has($keyword) || !Schemas::same($def->get($keyword), $value)) {
        $site[$keyword] = $value;
      }
    }
    return Schema::keywords($site);
  }

  /**
   * Builds a key's `x-surface` keyword, each part only where it says something.
   *
   * A locked key says it with `readOnly`, and a key with nothing to say
   * of a part leaves it out: no `dependsOn: []`, no `refined: false`, and
   * `stale` only where it is stale (ContractEmitter::frame(), property()).
   *
   * The widget a key is drawn with is a hint, in `x-portable-ui`
   * (PortableUi::write()).
   *
   * Decision: see
   * docs/decisions.md#contract-4-fewer-keywords-nothing-new-on-the-wire.
   *
   * @param string[] $depends
   *   The sibling keys its refiners watch; a mounted one by its dotted
   *   path in the frame.
   * @param bool $refined
   *   Whether it is narrowed right now.
   *
   * @return array
   *   The keyword.
   */
  public function extension(array $depends, bool $refined): array {
    $extension = [];
    if ($depends !== []) {
      $extension['dependsOn'] = array_values($depends);
    }
    if ($refined) {
      $extension['refined'] = TRUE;
    }
    return $extension;
  }

  /**
   * Gets the widget a map asks to be drawn with, by its word.
   *
   * A map's `widget` setting, which an owner sets on the key or a
   * structure asks for with #[Structure(widget:)]: a hint, as every
   * widget word is.
   *
   * Decision: see
   * docs/decisions.md#a-structure-may-ask-for-its-widget-and-a-widget-never-validates.
   *
   * The setting is read by the caller: from the key's node where the walk
   * holds it (NodeFacts::setting()); from the definition of a key that
   * holds a child, whose node would be its child's whole tree.
   *
   * @param mixed $setting
   *   The map's `widget` setting.
   *
   * @return string|null
   *   The word, or NULL for none.
   */
  public function asked(mixed $setting): ?string {
    return is_string($setting) && $setting !== '' ? $setting : NULL;
  }

  /**
   * Reads a key's title and description, as plain text.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The key's built node; for a key that holds a child, whose node would
   *   be its child's whole tree, what DefinitionSchema::describe() reads
   *   of its definition.
   *
   * @return \PortableSchema\Builder\Schema
   *   `title` and `description`, each only where the node holds one, as
   *   plain text (projected).
   */
  public function describe(Schema $node): Schema {
    $described = Schema::any();
    foreach (['title', 'description'] as $keyword) {
      if (!$node->isBoolean() && $node->has($keyword)) {
        $described = $described->with($keyword, $node->get($keyword));
      }
    }
    return DefinitionSchema::publishedTexts($described);
  }

  /**
   * Answers whether a key is in its object's `required`.
   *
   * Stands for DefinitionSchema::required(), rule for rule: required, and
   * neither a secret nor computed.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The key's built node.
   *
   * @return bool
   *   Whether the schema requires it.
   */
  public static function required(Schema $node): bool {
    return NodeFacts::isRequired($node) && !Secrets::marked($node) && NodeFacts::compute($node) === NULL;
  }

  /**
   * Says an item of a list as never null.
   *
   * An item is not a key a caller clears: accept() drops an empty item
   * before anything reads the list, so null never reaches the accepted
   * document, and a schema that offered it would offer a value no list
   * holds. The item's type loses null, and a list of choices its untitled
   * null entry, unless that entry is all there is: a list of no values,
   * whose items nothing satisfies.
   *
   * Decision: see docs/decisions.md#an-item-is-never-null.
   *
   * @param \PortableSchema\Builder\Schema $item
   *   The item, as a key would be said.
   *
   * @return \PortableSchema\Builder\Schema
   *   The same, never null.
   */
  protected static function neverNull(Schema $item): Schema {
    $type = $item->get('type');
    if (is_array($type)) {
      $types = array_values(array_diff($type, ['null']));
      $item = $item->with('type', count($types) === 1 ? $types[0] : $types);
    }
    $choices = $item->get('oneOf');
    if (is_array($choices)) {
      $choices = array_values(array_filter($choices, static fn (Schema $choice): bool => $choice->all() !== ['const' => NULL]));
      if ($choices !== []) {
        $item = $item->with('oneOf', $choices);
      }
    }
    return $item;
  }

  /**
   * Reads the presentation settings a module's widget publishes.
   *
   * Each setting the widget lists (SurfaceWidgetBase::publishedSettings())
   * that the definition holds, under its name in lowerCamel, as the
   * widget's options: a hint, how the key is drawn.
   *
   * Decision: see docs/decisions.md#the-form-is-drawn-from-the-document.
   *
   * @param \Drupal\surface\Widget\SurfaceWidgetInterface $widget
   *   The widget.
   * @param \PortableSchema\Builder\Schema $node
   *   The key's built node.
   *
   * @return array
   *   The options, by name; empty where none is held.
   */
  protected function published(SurfaceWidgetInterface $widget, Schema $node): array {
    $options = [];
    foreach ($widget instanceof SurfaceWidgetBase ? $widget->publishedSettings() : [] as $setting) {
      $held = NodeFacts::setting($node, $setting);
      if ($held !== NULL) {
        $options[PortableUi::optionName($setting)] = $held;
      }
    }
    return $options;
  }

  /**
   * Reads the size or the rows a text input is drawn with.
   *
   * The string widget's presentation settings (StringWidget::SETTINGS),
   * which a form display's component hands over: `size` on a one line
   * input, `rows` on a text area, each a whole number above nothing.
   *
   * @param string|null $widget
   *   The widget word.
   * @param \PortableSchema\Builder\Schema $node
   *   The key's built node.
   *
   * @return array
   *   The options, by name; empty where none is held.
   */
  protected function stringSettings(?string $widget, Schema $node): array {
    if (!in_array($widget, ['text', 'email', 'textarea'], TRUE)) {
      return [];
    }
    [$size, $rows] = StringWidget::SETTINGS;
    $setting = $widget === 'textarea' ? $rows : $size;
    $held = NodeFacts::setting($node, $setting);
    return is_int($held) && $held > 0 ? [$setting => $held] : [];
  }

  /**
   * Checks a word the emitter writes against the table of widget words.
   *
   * @param string|null $word
   *   The word, or NULL for none.
   *
   * @return string|null
   *   The same word (WidgetChoice::word()).
   */
  protected function word(?string $word): ?string {
    return $word === NULL || $this->widgets === NULL ? $word : $this->widgets->word($word);
  }

  /**
   * Spells an option set as `oneOf` entries, labels and help included.
   *
   * A key that may be cleared allows null besides, as its own entry with
   * no title: the empty option is a presentation, said under
   * `x-portable-ui.options.emptyOption`, and never an option with a
   * label.
   *
   * @param \Drupal\surface\Options\OptionSet $set
   *   The values the key allows.
   * @param \PortableSchema\Builder\Schema $node
   *   The key's built node.
   *
   * @return list<\PortableSchema\Builder\Schema>
   *   The entries.
   */
  protected function choices(OptionSet $set, Schema $node): array {
    $choices = [];
    foreach ($set->options as $value => $label) {
      // A Choice of numbers is labeled by them, as numbers; a Choice of
      // booleans as the document writes the value, "true" or "false".
      // Decision: see docs/decisions.md#the-remaining-readers-read-nodes.
      $title = PlainText::of(match (TRUE) {
        is_bool($label) => $label ? 'true' : 'false',
        is_string($label), $label instanceof \Stringable => $label,
        default => (string) $label,
      });
      $choice = Schema::any()->with('const', $this->cast($value, $node))->with('title', $title);
      if (isset($set->descriptions[$value])) {
        $choice = $choice->with('description', PlainText::of($set->descriptions[$value]));
      }
      $choices[] = $choice;
    }
    if ($choices === [] || NodeFacts::isNullable($node)) {
      // Null clears an optional key that may be cleared. A list of no
      // values offers it alone, which a required key's type refuses too:
      // nothing is allowed.
      $choices[] = Schema::any()->with('const', NULL);
    }
    return $choices;
  }

  /**
   * Writes an option value in the type its key declares.
   *
   * An option list is keyed by value, and PHP turns a numeric string key
   * into an integer, so the type a value arrives in says nothing; the
   * definition says what it is.
   *
   * @param int|string $value
   *   The value.
   * @param \PortableSchema\Builder\Schema $node
   *   The built node of the key it is a value of, whose type's row says
   *   its type (DefinitionSchema::typeOf()).
   *
   * @return int|float|string|bool
   *   The value in the key's type.
   */
  public function cast(int|string $value, Schema $node): int|float|string|bool {
    return match (DefinitionSchema::typeOf($node)['type'] ?? NULL) {
      'integer' => is_numeric($value) ? (int) $value : $value,
      'number' => is_numeric($value) ? $value + 0 : $value,
      'boolean' => (bool) $value,
      default => (string) $value,
    };
  }

  /**
   * Resolves the values a key offers, and notes what they rest on.
   *
   * @param \PortableSchema\Builder\Schema|\Drupal\Core\TypedData\DataDefinitionInterface $key
   *   The key's node, which the options service is handed; or its
   *   definition, where the caller holds no node.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the list's cacheability.
   *
   * @return \Drupal\surface\Options\OptionSet|null
   *   The set, or NULL when the key names no list.
   */
  public function optionSet(Schema|DataDefinitionInterface $key, CacheableMetadata $cacheability): ?OptionSet {
    $set = $this->options->resolve($key);
    if ($set !== NULL) {
      $cacheability->addCacheableDependency($set);
    }
    return $set;
  }

  /**
   * Sums up what a key allows, to tell a narrowed one apart.
   *
   * Every fact is read from the node: its data type, its own `required`
   * flag, its constraints as the document would state them for its type's
   * row (DefinitionSchema::keywordsOf(), typeOf()), and each property's
   * signature, and so are the values it offers: the options service is
   * handed the node, its item's for a list. A collection's own node
   * (listNode()) holds no item, so its item's values are asked of the
   * definition the caller hands.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The key's built node; a collection's own (PropertySchema::listNode()).
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $definition
   *   For a collection's own node, the collection's definition; NULL for
   *   any node that holds its item.
   *
   * @return string
   *   A fingerprint of its requiredness, its stated constraints and the
   *   values it offers.
   */
  public function signature(Schema $node, ?DataDefinitionInterface $definition = NULL): string {
    $list = DefinitionSchema::isList($node);
    $item = $list ? DefinitionSchema::itemNode($node) : NULL;
    $set = $this->options->resolve(match (TRUE) {
      !$list => $node,
      $item !== NULL => $item,
      default => static::itemDefinition($definition ?? throw new \LogicException('A collection\'s own node holds no item: its definition is handed for the values its item offers.'), ''),
    });
    $properties = [];
    if (is_a(DefinitionSchema::classOf($node), ComplexDataDefinitionInterface::class, TRUE)) {
      foreach (NodeFacts::properties($node) as $name => $property) {
        $properties[$name] = $this->signature($property);
      }
    }
    $type = DefinitionSchema::typeOf($node);
    // What the document would say of the constraints: their keywords, the
    // checks with their published options, and the messages as plain text.
    $keywords = Schema::any();
    foreach (DefinitionSchema::keywordsOf($node, $type['type'] ?? NULL, $type) as $keyword => $said) {
      $keywords = $keywords->with((string) $keyword, $said);
    }
    return serialize([
      NodeFacts::dataType($node),
      NodeFacts::isRequired($node),
      Schemas::decoded($this->definitions->publishedKeywords($keywords, $set !== NULL && !$list)),
      $set === NULL ? NULL : array_keys($set->options),
      $properties,
    ]);
  }

  /**
   * Answers whether anything inside an object schema is narrowed now.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   An object schema this emitter wrote.
   *
   * @return bool
   *   TRUE when a property says it is refined.
   */
  public function anyRefined(Schema $schema): bool {
    $properties = $schema->get('properties');
    foreach (is_array($properties) ? $properties : [] as $property) {
      // A mixed-in structure's member the owner says nothing more of is
      // `true`, and one ruled out `false`, which say nothing.
      $extension = $property->get(self::EXTENSION);
      if (is_array($extension) && !empty($extension['refined'])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * States how a key is arranged where it is drawn, as plain text.
   *
   * @param \Drupal\surface\Resource\Arrangement|null $arrangement
   *   The key's arrangement, or NULL for none.
   *
   * @return array
   *   Its arrangement as `x-portable-ui` members: `options` with each
   *   text not empty as plain text, `group` (PortableUi::declaration()) and
   *   `weight`, each only where it is set; the group's label and every
   *   text read as plain text; empty for none.
   */
  protected function arrangement(?Arrangement $arrangement): array {
    if ($arrangement === NULL) {
      return [];
    }
    $options = [];
    foreach (Arrangement::TEXTS as $text) {
      // Only a text that is not empty as plain text: markup alone says
      // nothing to a client that renders none.
      $plain = $arrangement->{$text} === NULL ? '' : PlainText::of((string) $arrangement->{$text});
      if ($plain !== '') {
        $options[$text] = $plain;
      }
    }
    $group = $arrangement->group;
    return ($options === [] ? [] : ['options' => $options])
      + ($group === NULL ? [] : ['group' => PortableUi::declaration($group->id, PlainText::of($group->label), $group->as, $group->collapsed, $group->weight, $group->description === NULL || (string) $group->description === '' ? NULL : PlainText::of((string) $group->description))])
      + ($arrangement->weight === NULL ? [] : ['weight' => $arrangement->weight]);
  }

  /**
   * Adds a key's arrangement to its widget's hints.
   *
   * Every key's, whichever way it is described: a value, a map, a list,
   * an attached structure, variants, a collection. Its options follow the
   * widget's own.
   *
   * Decision: see docs/decisions.md#arrangement-is-declared-once.
   *
   * @param array $ui
   *   The widget's hints (PortableUi::write()).
   * @param \Drupal\surface\Resource\Arrangement|null $arrangement
   *   The key's arrangement: read from its node where the walk holds it
   *   (NodeFacts::arrangement()); from the definition of a key that holds
   *   a child, whose node would be its child's whole tree.
   *
   * @return array
   *   The hints, arranged.
   */
  public function hints(array $ui, ?Arrangement $arrangement): array {
    $arranged = $this->arrangement($arrangement);
    if (isset($arranged['options'])) {
      $ui['options'] = ($ui['options'] ?? []) + $arranged['options'];
      unset($arranged['options']);
    }
    return $ui + $arranged;
  }

  /**
   * Strips the widget hints from a schema, at every depth.
   *
   * The hints are written while the schema is built, because the widget
   * a key gets is read from the same definition and option list as its
   * shape; taking them out afterwards keeps that one pass.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A schema this emitter wrote.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema, its `x-portable-ui` without the hints (PortableUi::HINTS:
   *   all but `options.emptyOption`), and with no `x-portable-ui` and no
   *   `x-surface` where nothing else was said.
   */
  public function withoutWidgets(Schema $schema): Schema {
    return Schemas::map($schema, static function (Schema $node): Schema {
      $ui = $node->get(PortableUi::KEYWORD);
      if (is_array($ui)) {
        $ui = PortableUi::without($ui, PortableUi::HINTS);
        $node = $ui === [] ? $node->without(PortableUi::KEYWORD) : $node->with(PortableUi::KEYWORD, $ui);
      }
      return $node->get(self::EXTENSION) === [] ? $node->without(self::EXTENSION) : $node;
    });
  }

  /**
   * Strips what a schema says beside what a value must hold, at every depth.
   *
   * `x-surface`, and the dialect's `portable-check`,
   * `x-portable-messages` and `x-portable-ui`: what a conditional, an
   * output and a narrowing compared through this say only of the keys
   * themselves.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema in plain JSON Schema.
   */
  public function withoutExtension(Schema $schema): Schema {
    return Schemas::map($schema, static function (Schema $node): Schema {
      foreach ([self::EXTENSION, self::SHAPED, ...PortableKeywords::KEYWORDS] as $keyword) {
        if ($node->has($keyword)) {
          $node = $node->without($keyword);
        }
      }
      return $node;
    }, FALSE);
  }

}
