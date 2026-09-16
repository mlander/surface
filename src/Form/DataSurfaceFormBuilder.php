<?php

declare(strict_types=1);

namespace Drupal\data_surface\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\ElementInfoManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Options\DataSurfaceOptions;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;
use Drupal\data_surface\Pipeline\ViolationSet;
use Drupal\data_surface\Widget\DataSurfaceWidgetManager;

/**
 * Generates, extracts, and validates Form API elements from a surface.
 *
 * Compared to the adapter-era proof of concept this layer carries no
 * masks: widgets own defaults, constraint mapping and empty options
 * natively, elements are not wrapper-nested so submitted trees arrive in
 * the definition's clean shape, and extraction reads by #parents with no
 * subform states anywhere.
 *
 * @see \Drupal\data_surface\Form\DataSurfaceFormBuilderInterface
 *   For the documentation of every method.
 */
class DataSurfaceFormBuilder implements DataSurfaceFormBuilderInterface {

  /**
   * Render key marking the surface container the AJAX path rebuilds.
   */
  protected const WRAPPER_KEY = '#data_surface_wrapper';

  /**
   * Element types that hold children rather than a value of their own.
   *
   * A refinement dependency rendered as one of these gets no #ajax: see
   * attachRefinementAjax() for why.
   */
  protected const GROUPING_TYPES = ['details', 'fieldset', 'container'];

  /**
   * Constructs a DataSurfaceFormBuilder.
   *
   * @param \Drupal\data_surface\Widget\DataSurfaceWidgetManager $widgetManager
   *   The widget manager, which maps definitions to elements.
   * @param \Drupal\data_surface\Pipeline\DataSurfacePipelineInterface $pipeline
   *   The pipeline, which accepts and validates what the form collects.
   * @param \Drupal\Core\Render\ElementInfoManagerInterface $elementInfo
   *   The element info manager. The container carries a #process
   *   callback of its own, and an explicit #process replaces whatever
   *   the element type declares instead of adding to it, so the type's
   *   own callbacks are read from here and kept.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger, which carries the one thing a surface has to say
   *   that is not an error and not part of an element: that a stored
   *   value went stale and is being kept.
   * @param \Drupal\data_surface\Options\DataSurfaceOptions $options
   *   The options service, which answers the one question the discard
   *   rule turns on: whether the refined definition still offers the
   *   value an in-progress edit left behind.
   */
  public function __construct(
    protected readonly DataSurfaceWidgetManager $widgetManager,
    protected readonly DataSurfacePipelineInterface $pipeline,
    protected readonly ElementInfoManagerInterface $elementInfo,
    protected readonly MessengerInterface $messenger,
    protected readonly DataSurfaceOptions $options,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function buildSurfaceForm(DataSurfaceInterface $surface, array $values, FormStateInterface $form_state, string $wrapper_key = 'data-surface'): array {
    $surface = $surface->refine($values);
    // One id per built container, not one per plugin. Two placements of
    // the same block on one page, or one block rendered twice by Layout
    // Builder, would otherwise share a wrapper id and each rebuild would
    // replace the other's container. The id only has to be stable within
    // one build: the AJAX callback finds the container by its marker,
    // not by its id, and the browser replaces whatever the trigger was
    // rendered pointing at.
    $wrapper_id = Html::getUniqueId($wrapper_key . '-wrapper');
    $container = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => ['id' => $wrapper_id],
      '#process' => $this->surfaceProcess('container'),
      self::WRAPPER_KEY => $wrapper_id,
    ];
    $definitions = $surface->getDefinitions();
    $dependencies = $definitions->refinementDependencies();
    foreach ($definitions as $name => $definition) {
      $value = match (TRUE) {
        // A secret is never handed to a widget, whatever the widget
        // would do with it. Being secret means the stored value does not
        // come back out, and a render array is the least private place
        // in Drupal: it is cached, it is serialized into the form cache,
        // it is rendered into the page source, and every element alter
        // hook on the site walks it. So the value stops here, one level
        // above the widget that would place it, rather than only in the
        // widget that knows better than to.
        DefinitionMetadata::isSecret($definition) => NULL,
        $definitions->isLocked($name) => $surface->getDefault($name),
        default => $values[$name] ?? $surface->getDefault($name),
      };
      $element = $this->widgetManager->getWidgetFor($definition)->buildElement($definition, $value);
      if ($definitions->isLocked($name)) {
        // Visible but fixed: the consumer sees the key and its value and
        // cannot change it — the form-side spelling of const + readOnly.
        $element['#disabled'] = TRUE;
        $element = static::describeLocked($element);
      }
      if (in_array($name, $dependencies, TRUE)) {
        $element = $this->attachRefinementAjax($element, $wrapper_id);
      }
      $container[$name] = $element;
    }
    // What the refined surface depends on is what this container
    // depends on: the option lists inside it were read from live site
    // state, and the refiners that produced them said so. The widget
    // applies each option set's own metadata to its element; this is the
    // other half, the surface's, and without it a form holding a
    // state-dependent shape would be cached as though it were static.
    CacheableMetadata::createFromObject($surface)->applyTo($container);
    return $container;
  }

  /**
   * {@inheritdoc}
   */
  public function discardedRefinementInput(DataSurfaceInterface $surface, array $stored, array $input): array {
    $targets = array_intersect_key($surface->getDefinitions()->refinements(), $input);
    if ($targets === []) {
      return [];
    }
    // What the person has in front of them right now, before anything is
    // taken away. Every "did this move?" question below is asked against
    // this, so a key is only ever judged against the edit it was made
    // under.
    $overlay = array_replace($stored, $input);
    $discarded = [];
    do {
      $values = array_replace($stored, array_diff_key($input, $discarded));
      $refined = $surface->refine($values);
      $again = FALSE;
      foreach ($targets as $name => $dependencies) {
        if (isset($discarded[$name]) || $this->stillStands($refined, $name, $dependencies, $input[$name], $overlay, $values)) {
          continue;
        }
        $discarded[$name] = $name;
        // One drop moves the next target's dependency, so the whole
        // question is asked again rather than once per key in whatever
        // order the definitions happen to be declared in. A chain of any
        // length settles inside one rebuild.
        $again = TRUE;
      }
    } while ($again);
    return array_values($discarded);
  }

  /**
   * Answers whether one target's in-progress input survives the rebuild.
   *
   * Two ways it does not. Either the narrowed definition no longer
   * offers the value — the direct case, a bundle orphaned by a new
   * entity type — or a key it refines against has itself just been
   * discarded to something else, which is the same invalidation one
   * link further down the chain: the answer was given under a question
   * that no longer reads the same way.
   *
   * A key with no value list behind it is never discarded. There is
   * nothing to have fallen out of, and an open string that narrowed to
   * another open string is still holding exactly what was typed into it.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $refined
   *   The surface refined against the values as they now stand.
   * @param string $name
   *   The target key.
   * @param string[] $dependencies
   *   The keys the target refines against.
   * @param mixed $value
   *   The in-progress input the target holds.
   * @param array $overlay
   *   The values as the person left them, before any discard.
   * @param array $values
   *   The values as they now stand.
   *
   * @return bool
   *   TRUE when the input is still the person's own answer to the
   *   question in front of them.
   */
  protected function stillStands(DataSurfaceInterface $refined, string $name, array $dependencies, mixed $value, array $overlay, array $values): bool {
    foreach ($dependencies as $dependency) {
      if (($overlay[$dependency] ?? NULL) !== ($values[$dependency] ?? NULL)) {
        return FALSE;
      }
    }
    // Only a value a select could have offered can be tested for
    // membership at all; anything else is not a choice and is left
    // alone. A list is left alone for the same reason the stale rule
    // leaves one alone: some items kept and others dropped is a shape
    // neither the widget nor this rule has.
    $definition = $refined->getDefinition($name);
    if ($definition === NULL || $definition instanceof ListDataDefinitionInterface || (!is_int($value) && !is_string($value))) {
      return TRUE;
    }
    $set = $this->options->resolve($definition);
    return $set === NULL || $set->allows($value);
  }

  /**
   * {@inheritdoc}
   */
  public function mergeSurfaceContainer(array $container, array $form): array {
    // The rule, in one line: the surface fills in what the host left
    // unsaid and overwrites nothing the host said itself. A host form
    // that already declares #type, #tree, #attributes or #process is
    // describing its own element, and the surface is a guest inside it;
    // taking those keys over is how a fieldset became a container and a
    // host's own wrapper id disappeared. Only the surface's own children
    // and the marker are the surface's to place.
    $merged = $form;
    if (isset($form['#attributes']['id']) && $form['#attributes']['id'] !== $container[self::WRAPPER_KEY]) {
      // The host named its element before the surface arrived, so that
      // id is the wrapper the rebuild replaces and the generated one is
      // discarded — including in the #ajax already attached to children.
      $container = static::retargetWrapper($container, $form['#attributes']['id']);
    }
    foreach ($container as $key => $value) {
      if (!is_string($key) || !str_starts_with($key, '#')) {
        // The surface's keys are the surface's own, so they win over a
        // host key of the same name; its render keys, below, do not.
        $merged[$key] = $value;
        continue;
      }
      // Anything the host has not said itself, the surface says.
      $merged[$key] ??= $value;
    }
    // Attributes merge rather than replace, so a host that set a class
    // keeps it and the surface adds only what is missing. The type, the
    // tree flag and the rest were settled by the loop above.
    $merged['#attributes'] = ($form['#attributes'] ?? []) + $container['#attributes'];
    $merged['#process'] = isset($form['#process'])
      ? array_merge($form['#process'], [[static::class, 'processSurfaceContainer']])
      : $this->surfaceProcess($merged['#type'] ?? NULL);
    return $merged;
  }

  /**
   * {@inheritdoc}
   *
   * The walk reads the form and never writes to it: a walk by reference
   * would create every key it looked for.
   */
  public static function refreshSurface(array &$form, FormStateInterface $form_state): array {
    $parents = $form_state->getTriggeringElement()['#array_parents'];
    while ($parents !== []) {
      array_pop($parents);
      $candidate = $form;
      foreach ($parents as $parent) {
        if (!is_array($candidate) || !array_key_exists($parent, $candidate)) {
          continue 2;
        }
        $candidate = $candidate[$parent];
      }
      if (isset($candidate[self::WRAPPER_KEY])) {
        return $candidate;
      }
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public static function processSurfaceContainer(array &$element, FormStateInterface $form_state, array &$complete_form): array {
    // The container knows its own #parents by now — Form API assigns
    // them before it runs an element's #process — and its children do
    // not yet know they triggered anything, because the triggering
    // element is captured while each child is built, which happens after
    // this. That ordering is the only moment the limit can be written:
    // Form API keeps a copy of the triggering element, so anything added
    // to a child later is never seen.
    //
    // The limit is the triggering element and nothing else, not the
    // container around it. Touching a select is not a submission of the
    // surface: the person has said one thing, about one key, and every
    // other key is still mid-edit. Scoped to the container, the rebuild
    // validated the whole surface — so a dependent holding a value the
    // new choice had just orphaned was flagged as a wrong answer to a
    // question nobody asked, and, because Form API skips the rebuild
    // outright once anything has errored, the container came back
    // refined against the old choice as well. Both halves of the bug
    // were that one line.
    //
    // The surface's real validation is unaffected: it runs on submit,
    // through the host's validate stage, where every key has been
    // answered on purpose.
    $parents = $element['#parents'] ?? [];
    $tree = !empty($element['#tree']);
    foreach (static::elementChildren($element) as $key) {
      if (isset($element[$key]['#ajax']) && !isset($element[$key]['#limit_validation_errors'])) {
        // The child's own value path, computed the way Form API is about
        // to compute it: children of a tree container are nested under
        // it, and children of anything else are top level.
        $element[$key]['#limit_validation_errors'] = [
          $element[$key]['#parents'] ?? ($tree ? [...$parents, $key] : [$key]),
        ];
      }
    }
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function findSurfaceContainer(array $form): array {
    $found = static::searchSurfaceContainer($form);
    if ($found === NULL) {
      throw new \LogicException(sprintf(
        'No surface container was found: nothing in the given form carries the "%s" marker. The container built by buildSurfaceForm() must reach extraction intact, because a surface whose container cannot be found collects no values at all and every stored value is replaced by its default.',
        self::WRAPPER_KEY,
      ));
    }
    return $found;
  }

  /**
   * {@inheritdoc}
   */
  public function extractSurfaceValues(DataSurfaceInterface $surface, array $container, FormStateInterface $form_state, array $current = []): array {
    $container = static::findSurfaceContainer($container);
    $raw = [];
    foreach ($surface->getDefinitions() as $name => $definition) {
      // A key with no element was never offered to the user, so it has
      // nothing to say: accept() keeps whatever the key already holds.
      if (!isset($container[$name]) || !is_array($container[$name])) {
        continue;
      }
      $raw[$name] = $this->widgetManager->getWidgetFor($definition)
        ->extractValue($definition, $container[$name], $form_state, [$name]);
    }
    // One coercion path for every caller: the form hands its raw tree to
    // the same accept() a payload goes through, which is also where
    // locked keys take the value the surface declares.
    return $this->pipeline->accept($surface, $raw, $current);
  }

  /**
   * {@inheritdoc}
   */
  public function validateSurfaceForm(DataSurfaceInterface $surface, array $values, array $container, FormStateInterface $form_state, array $current = []): bool {
    $errors = $this->pipeline->validate($surface, $values, $current);
    $this->flagSurfaceErrors($errors, $container, $form_state);
    return $errors->isEmpty();
  }

  /**
   * {@inheritdoc}
   *
   * A violation inside a nested map carries a property path; without
   * wrapper nesting the walk is direct: one child per path segment.
   */
  public function flagSurfaceErrors(ViolationSet $errors, array $container, FormStateInterface $form_state): void {
    $container = static::findSurfaceContainer($container);
    foreach ($errors as $violation) {
      $segments = $violation->path === '' ? [] : explode('.', $violation->path);
      $element = $container[$violation->key] ?? NULL;
      foreach ($segments as $segment) {
        if (!isset($element[$segment])) {
          break;
        }
        $element = $element[$segment];
      }
      if (is_array($element) && isset($element['#parents'])) {
        $form_state->setError($element, $violation->message);
      }
      else {
        $form_state->setErrorByName(implode('][', array_merge([$violation->key], $segments)), $violation->message);
      }
    }
    foreach ($errors->stale() as $reference) {
      // Deliberately not setError(): a stale reference is not something
      // the person did, nothing is wrong with the submission, and the
      // save is going ahead. Saying it out loud each time is the nag the
      // model asks for — the value keeps working, and it will not start
      // working again on its own.
      $this->messenger->addWarning($reference->message);
    }
  }

  /**
   * Wires a refinement dependency to rebuild the surface over AJAX.
   *
   * A widget that renders a grouping element — the map widget's details
   * — is skipped on purpose. A details is not an input: it emits no
   * change event, so an #ajax on it never fires, which is worse than
   * nothing because the form looks wired and is not. Attaching to each
   * leaf inside it instead was considered and rejected: a refiner
   * depends on the whole value of a key, so a rebuild fired by one leaf
   * would refine against a half-filled map, and the leaves of a map are
   * exactly where a person types rather than chooses. A map dependency
   * is therefore declared and left unwired; the surface still refines
   * when the form is submitted, or when a scalar dependency is touched.
   *
   * @param array $element
   *   The element built for the dependency.
   * @param string $wrapper_id
   *   The DOM id of the container the rebuild replaces.
   *
   * @return array
   *   The element, wired or left as it was.
   */
  protected function attachRefinementAjax(array $element, string $wrapper_id): array {
    if (!isset($element['#type']) || in_array($element['#type'], self::GROUPING_TYPES, TRUE)) {
      return $element;
    }
    $element['#ajax'] = [
      'callback' => [static::class, 'refreshSurface'],
      'wrapper' => $wrapper_id,
    ];
    return $element;
  }

  /**
   * Builds the #process list for the container, keeping the type's own.
   *
   * An explicit #process replaces the element type's default list rather
   * than adding to it, so the type's callbacks are read and kept — a
   * container that lost processGroup would break every #group inside it.
   *
   * @param string|null $type
   *   The effective element type of the container, or NULL when it has
   *   none, in which case the type declares no callbacks to keep.
   *
   * @return array
   *   The #process list.
   */
  protected function surfaceProcess(?string $type): array {
    $declared = $type === NULL ? [] : ($this->elementInfo->getInfo($type)['#process'] ?? []);
    return array_merge($declared, [[static::class, 'processSurfaceContainer']]);
  }

  /**
   * Appends the reason a locked element cannot be changed.
   *
   * A disabled input tells a sighted user it is fixed and tells a screen
   * reader nothing about why, so the reason goes in the description
   * where every user reaches it.
   *
   * @param array $element
   *   The locked element.
   *
   * @return array
   *   The element, with the reason appended to its description.
   */
  protected static function describeLocked(array $element): array {
    $note = new TranslatableMarkup('Fixed for this operation.');
    $element['#description'] = isset($element['#description'])
      ? new TranslatableMarkup('@description @note', [
        '@description' => $element['#description'],
        '@note' => $note,
      ])
      : $note;
    return $element;
  }

  /**
   * Points a built container's AJAX at a different wrapper id.
   *
   * @param array $container
   *   The container built by buildSurfaceForm().
   * @param string $wrapper_id
   *   The id to point at.
   *
   * @return array
   *   The container, re-pointed.
   */
  protected static function retargetWrapper(array $container, string $wrapper_id): array {
    $container[self::WRAPPER_KEY] = $wrapper_id;
    $container['#attributes']['id'] = $wrapper_id;
    foreach (static::elementChildren($container) as $key) {
      if (isset($container[$key]['#ajax']['wrapper'])) {
        $container[$key]['#ajax']['wrapper'] = $wrapper_id;
      }
    }
    return $container;
  }

  /**
   * Walks a form for the marker, without a depth limit.
   *
   * @param array $form
   *   The form or form fragment to search.
   *
   * @return array|null
   *   The container, or NULL when nothing in the tree carries the marker.
   */
  protected static function searchSurfaceContainer(array $form): ?array {
    if (isset($form[self::WRAPPER_KEY])) {
      return $form;
    }
    foreach (static::elementChildren($form) as $key) {
      if (($found = static::searchSurfaceContainer($form[$key])) !== NULL) {
        return $found;
      }
    }
    return NULL;
  }

  /**
   * Names the child elements of a render array.
   *
   * Core's Element::children() sorts and reads #weight, which is more
   * than any walk here needs and more than an unprocessed host fragment
   * can be relied on to survive. All that is wanted is "the keys that
   * are elements": never a render key, never a scalar.
   *
   * @param array $element
   *   The render array.
   *
   * @return array<int|string>
   *   The child keys.
   */
  protected static function elementChildren(array $element): array {
    $children = [];
    foreach ($element as $key => $child) {
      if (is_array($child) && !(is_string($key) && str_starts_with($key, '#'))) {
        $children[] = $key;
      }
    }
    return $children;
  }

}
