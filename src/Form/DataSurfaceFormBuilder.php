<?php

declare(strict_types=1);

namespace Drupal\data_surface\Form;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\AnnounceCommand;
use Drupal\Core\Ajax\AppendCommand;
use Drupal\Core\Ajax\PrependCommand;
use Drupal\Core\Ajax\RemoveCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\OptGroup;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\ElementInfoManagerInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Refinement\DoesNotApply;
use Drupal\data_surface\Resource\ArrangementGroup;
use Drupal\data_surface\Options\DataSurfaceOptions;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;
use Drupal\data_surface\Plugin\DataSurfaceWidget\OptionsWidget;
use Drupal\data_surface\Pipeline\ValueState;
use Drupal\data_surface\Pipeline\ViolationSet;
use Drupal\data_surface\Entry;
use Drupal\data_surface\Collection;
use Drupal\data_surface\Widget\DataSurfaceWidgetBase;
use Drupal\data_surface\Widget\DataSurfaceWidgetManager;

/**
 * Generates, extracts, and validates Form API elements from a data surface.
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
class DataSurfaceFormBuilder implements DataSurfaceFormBuilderInterface, TrustedCallbackInterface {

  /**
   * Render key marking the data surface container the AJAX path rebuilds.
   */
  protected const WRAPPER_KEY = '#data_surface_wrapper';

  /**
   * Render key on an element the AJAX callback may replace: its path.
   *
   * Dotted, below the container. The container's #process turns it into
   * a wrapper and REFRESH_ID_KEY once the container's id is final.
   */
  protected const REFRESH_KEY = '#data_surface_refresh';

  /**
   * Render key on the container listing the keys every rebuild replaces.
   *
   * Filled by placeRefreshed().
   */
  protected const REFRESHED_KEY = '#data_surface_refreshed';

  /**
   * Where a refinement request holds the violations on its trigger.
   *
   * Temporary form state, so it lives for the one request: written by
   * flagSurfaceErrors(), read by refreshSurface(), keyed by the
   * container's place in the form and the trigger's dotted path.
   */
  protected const TRIGGER_ERRORS = 'data_surface_trigger_errors';

  /**
   * Where a request keeps the options its triggers were rendered with.
   *
   * Temporary form state: read off the form the request arrived with,
   * which is the page the person sees, before any rebuild, so the AJAX
   * callback can tell a trigger whose options the rebuild changed.
   */
  protected const RENDERED_OPTIONS = 'data_surface_rendered_options';

  /**
   * Where a request keeps what each element it may replace was drawn as.
   *
   * Temporary form state, read off the form the request arrived with as
   * RENDERED_OPTIONS is, so the AJAX callback can say what the rebuild
   * changed: keyed by the container's place in the form, then by each
   * replaceable element's dotted path, each as drawing() reads it.
   */
  protected const DRAWN_AS = 'data_surface_drawn_as';

  /**
   * Render key marking a collection's element: its dotted path.
   */
  protected const LIST_KEY = '#data_surface_list';

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
   * Where a request keeps the delta each extracted item was drawn at.
   *
   * Temporary form state, keyed by the container's place in the form and
   * the collection's dotted path: an empty item is dropped on extraction,
   * so the items after it are accepted at a smaller delta than they were
   * drawn at, and a violation about one is flagged on the one drawn.
   */
  protected const LIST_DELTAS = 'data_surface_list_deltas';

  /**
   * Element types that hold children rather than a value of their own.
   *
   * A refinement dependency rendered as one of these gets no #ajax: see
   * attachRefinementAjax() for why.
   */
  protected const GROUPING_TYPES = ['details', 'fieldset', 'container'];

  /**
   * Element types whose options are each an element that triggers.
   */
  protected const OPTION_GROUP_TYPES = ['radios', 'checkboxes'];

  /**
   * Element types whose change event fires when they lose focus.
   *
   * Core's own list, the types it marks to refocus-blur in
   * RenderElementBase::preRenderAjaxForm(). A trigger of one of these
   * also gets disable-refocus: see attachRefinementAjax() for why.
   */
  protected const BLUR_CHANGE_TYPES = ['password', 'textfield', 'number', 'tel', 'textarea', 'machine_name'];

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
   *   The messenger, which carries the one thing a data surface has to say
   *   that is not an error and not part of an element: that a stored
   *   value went stale and is being kept.
   * @param \Drupal\data_surface\Options\DataSurfaceOptions $options
   *   The options service, which answers the one question the discard
   *   rule turns on: whether the refined definition still offers the
   *   value an in-progress edit left behind.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler, which says whether Inline Form Errors prints
   *   an error beside its element, so the message need not name it.
   */
  public function __construct(
    protected readonly DataSurfaceWidgetManager $widgetManager,
    protected readonly DataSurfacePipelineInterface $pipeline,
    protected readonly ElementInfoManagerInterface $elementInfo,
    protected readonly MessengerInterface $messenger,
    protected readonly DataSurfaceOptions $options,
    protected readonly ModuleHandlerInterface $moduleHandler,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function buildSurfaceForm(DataSurfaceInterface $surface, array $values, FormStateInterface $form_state, string $wrapper_key = 'data-surface'): array {
    // Two views of one overlay. The data surface is refined against the keys
    // as they stand, an orphan held unanswered; each element is rendered
    // with what it holds, an orphan standing for its stored value, so its
    // select comes up on the empty option with that value behind it.
    $shown = $values;
    $orphans = [];
    foreach ($values[self::STANDING_KEY] ?? [] as $dotted => $standing) {
      NestedArray::setValue($shown, explode('.', (string) $dotted), $standing, TRUE);
      $orphans[] = explode('.', (string) $dotted);
    }
    unset($values[self::STANDING_KEY], $shown[self::STANDING_KEY]);
    $surface = $surface->refine($values);
    // One id per built container, not one per plugin. Two placements of
    // the same block on one page, or one block rendered twice by Layout
    // Builder, would otherwise share a wrapper id and each rebuild would
    // replace the other's container. On a rebuild the id is the one the
    // browser holds, which the trigger sent: the rebuild replaces only
    // what moved, so the container and everything around the replaced
    // elements stay on the page with the ids they were rendered with.
    $wrapper_id = static::postedWrapperId($form_state, $wrapper_key) ?? Html::getUniqueId($wrapper_key . '-wrapper');
    $container = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => ['id' => $wrapper_id],
      '#process' => $this->surfaceProcess('container'),
      self::WRAPPER_KEY => $wrapper_id,
    ];
    // Drawn after the type's own, which an explicit list replaces.
    $container['#pre_render'] = $this->surfacePreRender('container');
    $definitions = $surface->getDefinitions();
    // Locked whole, the reason is said once, above the form: no element
    // repeats it, and none is marked required, since nothing is asked.
    // Decision: see docs/decisions.md#locked-at-the-top.
    $whole = $surface->lockedWhole() !== NULL;
    foreach ($definitions as $name => $definition) {
      // Each key is drawn as its arrangement says, whichever widget draws
      // it: read off the key as declared, which a variant's definition
      // below stands in for.
      // Decision: see docs/decisions.md#arrangement-is-declared-once.
      $declared = $definition;
      if (DoesNotApply::marks($definition)) {
        // Not rendered at all, not disabled and not hidden: the key does
        // not apply to these values, so there is nothing to answer. Its
        // wrapper alone is drawn, for the rebuild that makes it apply.
        // Decision: see docs/decisions.md#a-key-that-does-not-apply.
        $container[$name] = DataSurfaceWidgetBase::arranged(['#markup' => '', self::PLACEHOLDER_KEY => TRUE], $declared);
        continue;
      }
      $list = $definitions->entry($name)?->list;
      if ($list !== NULL && $definition instanceof ListDataDefinitionInterface) {
        // Decision: see docs/decisions.md#a-list-of-child-invocations.
        $container[$name] = DataSurfaceWidgetBase::arranged($this->buildCollection(
          $list,
          $definition,
          $values[$name] ?? $surface->getDefault($name),
          $shown[$name] ?? $surface->getDefault($name),
          $definitions->isLocked($name),
          [(string) $name],
          $wrapper_id,
          $definitions->entry($name)->lockedBecause,
          $whole,
        ), $declared);
        continue;
      }
      $variants = $definitions->entry($name)?->variants;
      $locked = $definitions->isLocked($name);
      $reason = $definitions->entry($name)?->lockedBecause;
      if ($variants !== NULL && DefinitionMetadata::variantsBy($definition) !== NULL) {
        // The values said nothing about the deciding key, so its own
        // default is what the person sees chosen, and the key is that
        // variant's.
        $chosen = $variants->chosen($values[$variants->by] ?? $surface->getDefault($variants->by));
        if ($chosen === NULL) {
          // Variants whose deciding key chose nothing have no shape to
          // render. Offering nothing is honest: a key with no element
          // has said nothing, so what it holds is kept, and the variant
          // appears the moment its deciding key is answered, through the
          // rebuild the deciding key is wired to. Nothing but its
          // wrapper is rendered, so that rebuild has a place to put it.
          $container[$name] = DataSurfaceWidgetBase::arranged(['#markup' => '', self::PLACEHOLDER_KEY => TRUE], $declared);
          continue;
        }
        $child = $variants->variant($chosen)->child;
        $held = $values[$name] ?? NULL;
        $definition = $variants->definitionFor($chosen, $child->refine(array_replace(
          $child->getDefaultValues(),
          $variants->fits($chosen, $held) ? $held : [],
        )));
      }
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
        // A locked identity key holds what the operation knows, its
        // default; a locked child, and a key its access locked, what is
        // stored, as given.
        $definitions->isLocked($name) && !$definitions->isHeld($name) && !$definitions->entry($name)?->isNested() => $surface->getDefault($name),
        default => $shown[$name] ?? $surface->getDefault($name),
      };
      if ($variants !== NULL) {
        // Rendered for the variant now chosen, from that variant's
        // defaults when what is held was written for another one.
        $chosen = (string) $variants->chosen($values[$variants->by] ?? $surface->getDefault($variants->by));
        $value = $variants->fits($chosen, $value) ? $value : $variants->defaultsOf($chosen);
        // A variant its access locked is offered, and drawn fixed.
        // Decision: see
        // docs/decisions.md#a-variant-is-open-locked-or-not-offered.
        $variant = $chosen !== '' ? $variants->variant($chosen)->child->lockedWhole() : NULL;
        $locked = $locked || $variant !== NULL;
        $reason ??= $variant;
      }
      $element = DataSurfaceWidgetBase::arranged($this->widgetManager->getWidgetFor($definition)->buildElement($definition, $value), $declared);
      if (isset($element[OptionsWidget::UNKEPT_LABEL_KEY]) && !array_key_exists($name, $shown)) {
        // Nothing stored: a declared default the list does not offer is
        // no value to keep, so the empty option does not say "keep".
        $element['#empty_option'] = $element[OptionsWidget::UNKEPT_LABEL_KEY];
      }
      $inside = $variants === NULL ? $definitions->entry($name)?->attachment?->child : NULL;
      if ($inside !== NULL && !$locked) {
        $element = static::lockedInside($element, $inside);
      }
      if ($locked) {
        // Visible but fixed: the consumer sees the key and its value and
        // cannot change it — the form-side spelling of const + readOnly.
        // A locked child's fieldset passes #disabled on to every element
        // inside it, as the form API does for any parent.
        $element['#disabled'] = TRUE;
        $element = $whole ? static::withoutRequired($element) : static::describeLocked($element, $reason);
      }
      $container[$name] = $element;
    }
    foreach ($orphans as $parents) {
      // An orphan of the edit stands for the stored value as a stale
      // select does, but the save that moves what it refines against
      // refuses it rather than keeping it: its empty option does not
      // say "keep".
      // Decision: see docs/decisions.md#the-empty-option-rule.
      $exists = FALSE;
      $orphan = NestedArray::getValue($container, $parents, $exists);
      if ($exists && is_array($orphan) && isset($orphan[OptionsWidget::UNKEPT_LABEL_KEY])) {
        $orphan['#empty_option'] = $orphan[OptionsWidget::UNKEPT_LABEL_KEY];
        NestedArray::setValue($container, $parents, $orphan);
      }
    }
    $container = $this->wireRefinement($container, $surface, $values, [], $wrapper_id);
    $container = static::withGroups($container);
    $stale = static::stalePaths($container);
    if ($stale !== []) {
      // Fixed to this build: what the person is looking at now, not what
      // the previous build posted.
      $container[self::STALE_MARKER_KEY] = [
        '#type' => 'hidden',
        '#value' => implode(' ', $stale),
      ];
    }
    // What the refined data surface depends on is what this container
    // depends on: the option lists inside it were read from live site
    // state, and the refiners that produced them said so. The widget
    // applies each option set's own metadata to its element; this is the
    // other half, the data surface's, and without it a form holding a
    // state-dependent shape would be cached as though it were static.
    CacheableMetadata::createFromObject($surface)->applyTo($container);
    return $container;
  }

  /**
   * Builds a collection: one details per item, and its two controls.
   *
   * The list's own details holds one details per item, each the child's
   * map refined against that item's values alone and titled with the
   * list's label and the item's number, then an "Add another" button.
   * Each item carries a "Remove" button. Both are submit buttons whose
   * handler rewrites the posted items and rebuilds the form, and whose
   * AJAX callback is the refinement one, replacing the list by its own
   * wrapper and nothing else. Adding is disabled once the list holds its
   * Count's maximum and removing once it holds its minimum, and the list
   * is drawn with at least its minimum, an empty item for each missing
   * one. A locked list is drawn disabled, every item and both controls
   * with it.
   *
   * @param \Drupal\data_surface\Collection $list
   *   The child every item is.
   * @param \Drupal\Core\TypedData\ListDataDefinitionInterface $definition
   *   The list, as the parent's refiners left it.
   * @param mixed $values
   *   The items as the data surface is refined against them, an orphan held
   *   unanswered.
   * @param mixed $shown
   *   The items as their elements show them, an orphan standing for its
   *   stored value.
   * @param bool $locked
   *   Whether the list is locked.
   * @param string[] $path
   *   The list's path below the container.
   * @param string $wrapper_id
   *   The container's id.
   * @param string|\Stringable|null $reason
   *   Why the list is locked, when an access answer said why.
   * @param bool $whole
   *   Whether the data surface is locked whole, its reason said above the
   *   form: the list then carries no note and no required marker.
   *
   * @return array
   *   The list's element.
   */
  protected function buildCollection(Collection $list, ListDataDefinitionInterface $definition, mixed $values, mixed $shown, bool $locked, array $path, string $wrapper_id, string|\Stringable|null $reason = NULL, bool $whole = FALSE): array {
    $child = $list->child();
    $items = Collection::items($values);
    $showing = Collection::items($shown);
    $count = $definition->getConstraints()['Count'] ?? [];
    $min = is_numeric($count['min'] ?? NULL) ? (int) $count['min'] : 0;
    $max = is_numeric($count['max'] ?? NULL) ? (int) $count['max'] : NULL;
    while (count($items) < $min) {
      $items[] = [];
    }
    $label = $definition->getLabel() ?? end($path);
    $dotted = implode('.', $path);
    $element = [
      '#type' => 'details',
      '#title' => $label,
      '#open' => TRUE,
      self::LIST_KEY => $dotted,
      // The list is what adding or removing an item replaces.
      self::REFRESH_KEY => $dotted,
    ];
    if ($definition->getDescription() !== NULL) {
      $element['#description'] = $definition->getDescription();
    }
    if ($definition->isRequired()) {
      $element['#required'] = TRUE;
    }
    $control = fn (string $name, array $at, bool $disabled): array => [
      '#type' => 'submit',
      '#value' => static::controlLabel($name),
      '#name' => 'data_surface_' . implode('_', array_map([Html::class, 'getId'], [...$path, ...$at])),
      '#submit' => [[static::class, $name === self::ADD_KEY ? 'addListItem' : 'removeListItem']],
      // Nothing is judged: the person said only "one more", or "not this
      // one", and every value is still mid-edit.
      '#limit_validation_errors' => [],
      '#disabled' => $disabled || $locked,
      '#ajax' => [
        'callback' => [static::class, 'refreshSurface'],
        'wrapper' => $wrapper_id,
        'submit' => [self::WRAPPER_INPUT => $wrapper_id],
      ],
      self::TRIGGER_KEY => ['path' => [...$path, ...$at], 'replaces' => [$dotted]],
    ];
    foreach ($items as $delta => $item) {
      $held = array_replace($child->getDefaultValues(), is_array($item) ? $item : []);
      $refined = $child->refine($held);
      $showing_item = $showing[$delta] ?? $item;
      $showing_item = array_replace($child->getDefaultValues(), is_array($showing_item) ? $showing_item : []);
      // A collection inside the item is drawn as one, in its place; the
      // item's map draws the rest.
      // Decision: see docs/decisions.md#a-list-of-child-invocations.
      $map = MapDataDefinition::create()->setLabel(static::itemLabel($label, $delta));
      $lists = [];
      foreach ($refined->getDefinitions() as $name => $property) {
        $inner = $refined->getDefinitions()->entry((string) $name)?->list;
        if ($inner !== NULL && $property instanceof ListDataDefinitionInterface) {
          $lists[(string) $name] = $this->buildCollection(
            $inner,
            $property,
            $held[$name] ?? NULL,
            $showing_item[$name] ?? NULL,
            $locked || $refined->isLocked((string) $name),
            [...$path, (string) $delta, (string) $name],
            $wrapper_id,
            $locked ? $reason : $refined->getDefinitions()->entry((string) $name)->lockedBecause,
            $whole,
          );
          continue;
        }
        $map->setPropertyDefinition((string) $name, $property);
      }
      $element[$delta] = $this->widgetManager->getWidgetFor($map)->buildElement($map, $showing_item);
      if ($lists !== []) {
        $drawn = $element[$delta];
        $ordered = [];
        foreach ($refined->getDefinitions()->names() as $name) {
          if (isset($lists[$name]) || isset($drawn[$name])) {
            $ordered[$name] = $lists[$name] ?? $drawn[$name];
            unset($drawn[$name]);
          }
        }
        $element[$delta] = $drawn + $ordered;
      }
      $element[$delta][self::REMOVE_KEY] = $control(self::REMOVE_KEY, [(string) $delta, self::REMOVE_KEY], count($items) <= $min);
      // Every item's button says "Remove"; its accessible name says which.
      $element[$delta][self::REMOVE_KEY]['#attributes']['aria-label'] = static::removeLabel(static::itemLabel($label, $delta));
      $element[$delta][self::REMOVE_KEY]['#weight'] = 1000;
    }
    $element[self::ADD_KEY] = $control(self::ADD_KEY, [self::ADD_KEY], $max !== NULL && count($items) >= $max);
    if ($locked) {
      $element['#disabled'] = TRUE;
      $element = $whole ? static::withoutRequired($element) : static::describeLocked($element, $reason);
    }
    return $element;
  }

  /**
   * Titles one item of a collection: the list's label and its number.
   *
   * @param string|\Stringable $label
   *   The list's label.
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
   * The button shows "Remove", the word it is pressed by, and every item
   * has one, so its accessible name adds the item's title, as core's
   * media library names each of its "Remove" buttons.
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
   * Adds an empty item to a collection: its "Add another" button's submit.
   *
   * The posted items are what the rebuild draws, so one more, holding
   * nothing, is put at their end, and the form is rebuilt from them.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function addListItem(array &$form, FormStateInterface $form_state): void {
    static::rewriteListInput($form_state, static function (array $items): array {
      $items[] = [];
      return $items;
    }, 1);
  }

  /**
   * Takes one item out of a collection: its "Remove" button's submit.
   *
   * The items after it move up one: delta is position.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function removeListItem(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement() ?? [];
    $parents = $trigger['#parents'] ?? [];
    $delta = (int) ($parents[count($parents) - 2] ?? -1);
    static::rewriteListInput($form_state, static function (array $items) use ($delta): array {
      unset($items[$delta]);
      return array_values($items);
    }, 2);
  }

  /**
   * Rewrites the posted items of the collection a button belongs to.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param callable $rewrite
   *   Takes the posted items, in order, and returns the new ones.
   * @param int $depth
   *   How far below the list the button sits.
   */
  protected static function rewriteListInput(FormStateInterface $form_state, callable $rewrite, int $depth): void {
    $state = $form_state instanceof SubformStateInterface ? $form_state->getCompleteFormState() : $form_state;
    $trigger = $state->getTriggeringElement() ?? [];
    $parents = array_slice($trigger['#parents'] ?? [], 0, -$depth);
    if ($parents === []) {
      return;
    }
    $input = $state->getUserInput();
    NestedArray::setValue($input, $parents, $rewrite(Collection::items(NestedArray::getValue($input, $parents))), TRUE);
    $state->setUserInput($input);
    $state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function discardedRefinementInput(DataSurfaceInterface $surface, array $stored, array $input): array {
    return $this->settle($surface, $stored, $input)[0];
  }

  /**
   * {@inheritdoc}
   */
  public function refinementOverlay(DataSurfaceInterface $surface, array $stored, array $input): array {
    [$discarded, $orphaned] = $this->settle($surface, $stored, $input);
    foreach ($discarded as $dotted) {
      NestedArray::unsetValue($input, explode('.', $dotted));
    }
    $values = array_replace($stored, $input);
    foreach ($discarded as $dotted) {
      // A mounted key falls back to what is stored, as a whole key does;
      // a child's key, inside its own frame, to the child's defaults.
      $parents = explode('.', $dotted);
      $entry = $surface->getDefinitions()->entry($parents[0]);
      if (count($parents) > 1 && $entry !== NULL && !$entry->isNested() && $entry->list === NULL) {
        static::restore($values, $stored, $parents);
      }
    }
    foreach (array_keys($orphaned) as $dotted) {
      NestedArray::setValue($values, explode('.', (string) $dotted), NULL, TRUE);
    }
    if ($orphaned !== []) {
      $values[self::STANDING_KEY] = $orphaned;
    }
    return $values;
  }

  /**
   * Puts what is stored back at one path, or nothing when nothing is.
   *
   * @param array $values
   *   The values being settled.
   * @param array $stored
   *   What is stored.
   * @param string[] $parents
   *   The path.
   */
  protected static function restore(array &$values, array $stored, array $parents): void {
    $exists = FALSE;
    $held = NestedArray::getValue($stored, $parents, $exists);
    if ($exists) {
      NestedArray::setValue($values, $parents, $held, TRUE);
      return;
    }
    NestedArray::unsetValue($values, $parents);
  }

  /**
   * Reads a definition by key, or by dotted path to a mounted key.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface.
   * @param string $path
   *   A key, or `third_party_settings.<module>.<key>`.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The definition, or NULL when nothing is declared there.
   */
  protected static function definitionAt(DataSurfaceInterface $surface, string $path): ?DataDefinitionInterface {
    $parents = explode('.', $path);
    $definition = $surface->getDefinition((string) array_shift($parents));
    foreach ($parents as $property) {
      $definition = $definition instanceof ComplexDataDefinitionInterface ? $definition->getPropertyDefinition($property) : NULL;
    }
    return $definition;
  }

  /**
   * Settles a rebuild's input: what it drops, and what it leaves orphaned.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface or built child, as advertised.
   * @param array $stored
   *   What is stored for its keys.
   * @param array $input
   *   The in-progress input for its keys.
   *
   * @return array{0: string[], 1: array<string, mixed>}
   *   The keys, or dotted paths into a child, whose input is dropped; and
   *   the keys, or dotted paths, held unanswered, each mapped to the
   *   stored value its element stands for.
   */
  protected function settle(DataSurfaceInterface $surface, array $stored, array $input): array {
    [$discarded, $orphaned] = $this->settleFrame($surface, $stored, $input);
    // A child refines in its own frame, under its own names, so what its
    // own refiners orphan is asked of the child, against the value the
    // parent's input now holds for it. A key discarded whole above has
    // nothing left inside it to ask about.
    $values = static::frameValues($stored, $input, $discarded, $orphaned);
    foreach ($surface->getDefinitions()->entries() as $name => $entry) {
      $name = (string) $name;
      if ($entry->list !== NULL && is_array($input[$name] ?? NULL)) {
        // Each item is settled by the child in its own frame, against what
        // is stored at the same position.
        $child = $entry->list->child();
        $held = is_array($stored[$name] ?? NULL) ? array_values($stored[$name]) : [];
        foreach ($input[$name] as $delta => $item) {
          if (!is_int($delta) || !is_array($item)) {
            continue;
          }
          $child_stored = array_replace($child->getDefaultValues(), is_array($held[$delta] ?? NULL) ? $held[$delta] : []);
          [$child_discarded, $child_orphaned] = $this->settle($child, $child_stored, $item);
          foreach ($child_discarded as $path) {
            $discarded[] = $name . '.' . $delta . '.' . $path;
          }
          foreach ($child_orphaned as $path => $value) {
            $orphaned[$name . '.' . $delta . '.' . $path] = $value;
          }
        }
        continue;
      }
      if (!$entry->isNested() || in_array($name, $discarded, TRUE) || !is_array($input[$name] ?? NULL)) {
        continue;
      }
      $child = $entry->childFor($values);
      if ($child === NULL) {
        continue;
      }
      $held = $stored[$name] ?? NULL;
      if ($entry->variants !== NULL && !$entry->variants->fits((string) $entry->variants->chosen($values[$entry->variants->by] ?? NULL), $held)) {
        // What is stored was written for another variant, and says
        // nothing about this one.
        $held = NULL;
      }
      $child_stored = array_replace($child->getDefaultValues(), is_array($held) ? $held : []);
      [$child_discarded, $child_orphaned] = $this->settle($child, $child_stored, $input[$name]);
      foreach ($child_discarded as $path) {
        $discarded[] = $name . '.' . $path;
      }
      foreach ($child_orphaned as $path => $value) {
        $orphaned[$name . '.' . $path] = $value;
      }
    }
    return [$discarded, $orphaned];
  }

  /**
   * Settles one frame, to a fixed point.
   *
   * Two moves, each of which changes what the other keys refine against,
   * so neither is asked once. An input the narrowed definition no longer
   * offers, or one whose own dependency moved under it, is dropped, and
   * the key falls back to what is stored. A key that falls back to a
   * stored value the edit orphaned is held unanswered, which moves
   * whatever refines against it in turn. The loop runs until neither
   * finds anything new; each pass adds a key to one of two sets drawn
   * from the frame's refinement targets and never takes one away, so it
   * ends within twice the number of targets, and in practice within the
   * depth of the longest dependency chain.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface or built child, as advertised.
   * @param array $stored
   *   What is stored for its keys.
   * @param array $input
   *   The in-progress input for its keys.
   *
   * @return array{0: string[], 1: array<string, mixed>}
   *   The keys whose input is dropped, in the order they were found; and
   *   the keys held unanswered, each mapped to the stored value it
   *   stands for. A key an alter mounted is named by its dotted path.
   */
  protected function settleFrame(DataSurfaceInterface $surface, array $stored, array $input): array {
    // One mounted key at a time: a moved capacity drops the stewards
    // count it narrows, and not the license beside it.
    $refinements = $surface->getDefinitions()->refinementPaths();
    if ($refinements === []) {
      return [[], []];
    }
    $targets = array_filter(
      $refinements,
      static fn (string $name): bool => NestedArray::keyExists($input, explode('.', $name)),
      ARRAY_FILTER_USE_KEY,
    );
    // What the person has in front of them right now, before anything is
    // taken away. Every "did this move?" question below is asked against
    // this, so a key is only ever judged against the edit it was made
    // under.
    $overlay = array_replace($stored, $input);
    $discarded = [];
    $orphaned = [];
    do {
      $values = static::frameValues($stored, $input, array_values($discarded), $orphaned);
      $refined = $surface->refine($values);
      $again = FALSE;
      foreach ($targets as $name => $dependencies) {
        $name = (string) $name;
        if (isset($discarded[$name]) || $this->stillStands($refined, $name, $dependencies, NestedArray::getValue($input, explode('.', $name)), $overlay, $values)) {
          continue;
        }
        $discarded[$name] = $name;
        // One drop moves the next target's dependency, so the whole
        // question is asked again rather than once per key in whatever
        // order the definitions happen to be declared in. A chain of any
        // length settles inside one rebuild.
        $again = TRUE;
      }
      if ($again) {
        // What a drop falls back to is only known once the data surface is
        // refined against it.
        continue;
      }
      foreach ($refinements as $name => $dependencies) {
        $name = (string) $name;
        // Only a key standing on what is stored: input that is still
        // there was just judged to stand.
        if (isset($orphaned[$name]) || (NestedArray::keyExists($input, explode('.', $name)) && !isset($discarded[$name]))) {
          continue;
        }
        if ($this->isOrphaned($refined, $name, $dependencies, $values, $stored)) {
          $orphaned[$name] = NestedArray::getValue($values, explode('.', $name));
          $again = TRUE;
        }
      }
    } while ($again);
    return [array_values($discarded), $orphaned];
  }

  /**
   * Builds one frame's values as they stand partway through settling.
   *
   * @param array $stored
   *   What is stored for the frame's keys.
   * @param array $input
   *   The in-progress input for them.
   * @param string[] $discarded
   *   The keys, or mounted keys' dotted paths, whose input is dropped
   *   so far.
   * @param array<string, mixed> $orphaned
   *   The keys held unanswered so far.
   *
   * @return array
   *   Stored underneath, the input that still stands on top, nothing at
   *   each orphaned key.
   */
  protected static function frameValues(array $stored, array $input, array $discarded, array $orphaned): array {
    foreach ($discarded as $dotted) {
      NestedArray::unsetValue($input, explode('.', $dotted));
    }
    $values = array_replace($stored, $input);
    foreach ($discarded as $dotted) {
      $parents = explode('.', $dotted);
      if (count($parents) > 1) {
        // A mounted key's input dropped: what is stored for it, as for a
        // whole key.
        static::restore($values, $stored, $parents);
      }
    }
    foreach (array_keys($orphaned) as $dotted) {
      NestedArray::setValue($values, explode('.', (string) $dotted), NULL, TRUE);
    }
    return $values;
  }

  /**
   * Answers whether a key's stored value was orphaned by the edit.
   *
   * The pipeline's line between stale and refused, asked of a form still
   * being edited. A stored value the narrowed list no longer offers while
   * every key it refines against still holds what is stored is stale: the
   * site moved, it is kept, and what refines against it still does. One
   * whose dependency the edit moved is this edit's own orphan: a save
   * from here refuses it, its select is empty, and nothing below it may
   * go on being narrowed by it — a capacity capped by a room no longer on
   * the screen.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $refined
   *   The data surface refined against the values as they now stand.
   * @param string $name
   *   The target key, or a mounted key's dotted path.
   * @param string[] $dependencies
   *   The keys the target refines against.
   * @param array $values
   *   The values as they now stand; the target's is what is stored.
   * @param array $stored
   *   What is stored for the frame's keys.
   *
   * @return bool
   *   TRUE when the key is to be held unanswered.
   */
  protected function isOrphaned(DataSurfaceInterface $refined, string $name, array $dependencies, array $values, array $stored): bool {
    $value = NestedArray::getValue($values, explode('.', $name));
    if ((!is_int($value) && !is_string($value)) || !ValueState::isConfigured($value)) {
      return FALSE;
    }
    $moved = FALSE;
    foreach ($dependencies as $dependency) {
      $parents = explode('.', $dependency);
      $moved = $moved || !static::sameAnswer(NestedArray::getValue($values, $parents), NestedArray::getValue($stored, $parents));
    }
    if (!$moved) {
      return FALSE;
    }
    $definition = static::definitionAt($refined, $name);
    if ($definition === NULL || $definition instanceof ListDataDefinitionInterface || $refined->getDefinitions()->entry($name)?->variants !== NULL) {
      return FALSE;
    }
    $set = $this->options->resolve($definition);
    return $set !== NULL && !$set->allows($value);
  }

  /**
   * Compares an answer with what is stored, as a form posts it.
   *
   * A browser posts every scalar as a string, so a stored 1 and a posted
   * "1" are the same answer, and an unanswered key the same whichever
   * empty spelling it arrives in.
   *
   * @param mixed $answer
   *   The value as it now stands.
   * @param mixed $stored
   *   What is stored.
   *
   * @return bool
   *   TRUE when the two say the same thing.
   */
  protected static function sameAnswer(mixed $answer, mixed $stored): bool {
    if (!ValueState::isConfigured($answer) || !ValueState::isConfigured($stored)) {
      return ValueState::isConfigured($answer) === ValueState::isConfigured($stored);
    }
    if (is_bool($answer) || is_bool($stored)) {
      return (bool) $answer === (bool) $stored;
    }
    if (is_scalar($answer) && is_scalar($stored)) {
      return (string) $answer === (string) $stored;
    }
    return $answer === $stored;
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
   *   The data surface refined against the values as they now stand.
   * @param string $name
   *   The target key, or a mounted key's dotted path.
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
      // A key an alter mounted is named by its dotted path.
      $parents = explode('.', $dependency);
      if (NestedArray::getValue($overlay, $parents) !== NestedArray::getValue($values, $parents)) {
        return FALSE;
      }
    }
    $variants = $refined->getDefinitions()->entry($name)?->variants;
    if ($variants !== NULL) {
      // The input at a key with variants stands while it is shaped for
      // the variant now chosen. Input left by another variant answered a
      // question that is no longer on the form, so it goes, and the
      // rebuilt key starts from the chosen variant's own values.
      $chosen = $variants->chosen($values[$variants->by] ?? NULL);
      return $chosen === NULL || $variants->fits($chosen, $value);
    }
    // An empty answer has nothing to fall out of: it is the person
    // having said "nothing" under a parent that has not moved, and a
    // stale select left empty never reaches here as empty, because the
    // marker it posted has already put the stored value back.
    if (!ValueState::isConfigured($value)) {
      return TRUE;
    }
    // Only a value a select could have offered can be tested for
    // membership at all; anything else is not a choice and is left
    // alone. A list is left alone for the same reason the stale rule
    // leaves one alone: some items kept and others dropped is a shape
    // neither the widget nor this rule has.
    $definition = static::definitionAt($refined, $name);
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
    // The rule, in one line: the data surface fills in what the host left
    // unsaid and overwrites nothing the host said itself. A host form that
    // already declares #type, #tree, #attributes or #process is describing
    // its own element, and the data surface is a guest inside it; taking
    // those keys over is how a fieldset became a container and a host's own
    // wrapper id disappeared. Only the data surface's own children and the
    // marker are the data surface's to place.
    $merged = $form;
    if (isset($form['#attributes']['id']) && $form['#attributes']['id'] !== $container[self::WRAPPER_KEY]) {
      // The host named its element before the data surface arrived, so that
      // id is the wrapper the rebuild replaces and the generated one is
      // discarded, including in the #ajax already attached to children.
      $container = static::retargetWrapper($container, $form['#attributes']['id']);
    }
    foreach ($container as $key => $value) {
      if (!is_string($key) || !str_starts_with($key, '#')) {
        // The data surface's keys are the data surface's own, so they win
        // over a host key of the same name; its render keys, below, do not.
        $merged[$key] = $value;
        continue;
      }
      // Anything the host has not said itself, the data surface says.
      $merged[$key] ??= $value;
    }
    // Attributes merge rather than replace, so a host that set a class
    // keeps it and the data surface adds only what is missing. The type,
    // the tree flag and the rest were settled by the loop above.
    $merged['#attributes'] = ($form['#attributes'] ?? []) + $container['#attributes'];
    $merged['#process'] = isset($form['#process'])
      ? array_merge($form['#process'], [[static::class, 'processSurfaceContainer']])
      : $this->surfaceProcess($merged['#type'] ?? NULL);
    $merged['#pre_render'] = isset($form['#pre_render'])
      ? array_merge($form['#pre_render'], [[static::class, 'preRenderSurfaceContainer']])
      : $this->surfacePreRender($merged['#type'] ?? NULL);
    return $merged;
  }

  /**
   * {@inheritdoc}
   */
  public function placeRefreshed(array $container, string $key, array $element): array {
    $element[self::REFRESH_KEY] = $key;
    $container[$key] = $element;
    $container[self::REFRESHED_KEY] = array_values(array_unique([...($container[self::REFRESHED_KEY] ?? []), $key]));
    return $container;
  }

  /**
   * {@inheritdoc}
   *
   * What is replaced is read off the trigger, which carries the
   * dependent closure it was built with: the dependency edges are fixed
   * when a data surface is sealed and no refinement changes them, so the
   * closure the previous build computed is the closure of this one. Each
   * element is then taken from the rebuilt container, because that is
   * the one built against the new answer.
   */
  public static function refreshSurface(array &$form, FormStateInterface $form_state): AjaxResponse|array {
    $trigger = $form_state->getTriggeringElement() ?? [];
    $container = static::containerOf($form, $trigger['#array_parents'] ?? []);
    $replaces = $trigger[self::TRIGGER_KEY]['replaces'] ?? NULL;
    if ($container === NULL || !is_array($replaces)) {
      return $container ?? $form;
    }
    $own = implode('.', $trigger[self::TRIGGER_KEY]['path'] ?? []);
    $wrapper_id = (string) $container[self::WRAPPER_KEY];
    $own_parents = explode('.', $own);
    $touched = NestedArray::getValue($container, $own_parents);
    $replace_own = FALSE;
    $state = $form_state instanceof SubformStateInterface ? $form_state->getCompleteFormState() : $form_state;
    if (is_array($touched)) {
      $touched = static::withTriggerErrors($touched, $form_state, static::containerKey($container), $own);
      NestedArray::setValue($container, $own_parents, $touched);
      // Decision: see docs/decisions.md#an-error-on-the-trigger-renders-inline.
      $rendered = $state->getTemporaryValue([self::RENDERED_OPTIONS, static::containerKey($container), $own]);
      $replace_own = !empty($touched[self::INLINE_ERROR_KEY])
        || ($state->getUserInput()[self::INVALID_INPUT] ?? NULL) === '1'
        || ($rendered !== NULL && $rendered !== static::optionsSignature($touched));
    }
    $elements = [];
    $drawn = [];
    foreach ([...$replaces, ...($container[self::REFRESHED_KEY] ?? []), ...($replace_own ? [$own] : [])] as $dotted) {
      if ($dotted === $own && !$replace_own) {
        // Not the element that was touched, unless its own value was
        // refused: it already shows what the person chose, and redrawing
        // it is what made the change look like a reload of the field
        // rather than of what it changed.
        continue;
      }
      $exists = FALSE;
      $element = NestedArray::getValue($container, explode('.', (string) $dotted), $exists);
      if (!$exists || !is_array($element) || !isset($element[self::REFRESH_ID_KEY])) {
        // Taken out by a host or a cosmetic layer, or never wrapped:
        // there is no telling where it went, so the container goes
        // whole, which is coarser and never wrong.
        return $container;
      }
      // A member of a group is not rendered on its own, and a replaced
      // element is rendered on its own; core's own AJAX response builder
      // does the same to the element a callback returns.
      unset($element['#group']);
      if ($dotted !== $own && in_array($dotted, $replaces, TRUE)) {
        // What the change moved; a panel every rebuild redraws is not.
        $drawn[(string) $dotted] = $element;
      }
      // Rendered without the container, so a group or a fieldset placed
      // inside it is drawn here, as the container's pre-render would have.
      $elements[$element[self::REFRESH_ID_KEY]] = static::placeMounts(static::gatherGroups($element));
    }
    $response = new AjaxResponse();
    foreach ($elements as $id => $element) {
      $response->addCommand(new ReplaceCommand('#' . $id, $element));
    }
    // Said once, politely, for whoever cannot see what moved: the error on
    // the trigger, then what the rebuild changed.
    $said = static::changesSaid(
      $state->getTemporaryValue([self::DRAWN_AS, static::containerKey($container)]) ?? [],
      $drawn,
      is_array($touched) && !empty($touched[self::INLINE_ERROR_KEY]) ? $touched : NULL,
    );
    if ($said !== '') {
      $response->addCommand(new AnnounceCommand($said));
    }
    // The stale marker names stale selects across the whole container,
    // and whether it exists at all depends on the rebuild, so it is not
    // replaced but taken out and put back as the rebuild left it. A
    // marker left naming a select that now holds a real choice would
    // read that select back as its stored value on the next request.
    $response->addCommand(new RemoveCommand('#' . static::reservedId($wrapper_id, 'stale')));
    if (isset($container[self::STALE_MARKER_KEY])) {
      $response->addCommand(new AppendCommand('#' . $wrapper_id, $container[self::STALE_MARKER_KEY]));
    }
    // What the render-array path printed into the replaced container,
    // printed in the same place, so nothing the request said is held
    // over to the next page: a stale value kept, say. An error on the
    // trigger is not among them; it is printed under the trigger. The
    // previous request's are taken away first, as replacing the
    // container used to.
    $messages = static::reservedId($wrapper_id, 'messages');
    $response->addCommand(new RemoveCommand('#' . $messages));
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    if (\Drupal::messenger()->all() !== []) {
      $response->addCommand(new PrependCommand('#' . $wrapper_id, [
        '#type' => 'container',
        '#attributes' => ['id' => $messages],
        'messages' => ['#type' => 'status_messages'],
      ]));
    }
    return $response;
  }

  /**
   * Says what a rebuild changed, in whole sentences, for an announcement.
   *
   * A sighted person sees a field come or go and a limit move; nobody
   * else is told. Each replaced element is compared with what it was
   * drawn as on the page the request came from: whether it applies,
   * whether it is required, the range it allows, the choices it offers,
   * and, for a group, the fields it holds. The trigger's own refusal,
   * named for it, comes first.
   *
   * Decision: see docs/decisions.md#an-element-hints-and-the-rule-speaks.
   *
   * @param array<string, array> $before
   *   What each replaceable element was drawn as, by dotted path, from
   *   drawnAs().
   * @param array<string, array> $after
   *   The replaced elements as rebuilt, by dotted path, the trigger left
   *   out.
   * @param array|null $refused
   *   The trigger, when it carries an error printed under it.
   *
   * @return string
   *   The sentences, or an empty string when nothing is said.
   */
  protected static function changesSaid(array $before, array $after, ?array $refused): string {
    $sentences = [];
    $errors = $refused['#errors'] ?? NULL;
    foreach (is_array($errors) ? ($errors['#items'] ?? []) : [$errors] as $message) {
      if ($refused !== NULL && (is_string($message) || $message instanceof \Stringable) && (string) $message !== '') {
        $sentences[] = static::named($refused, $message);
      }
    }
    foreach ($after as $dotted => $element) {
      $was = $before[$dotted] ?? NULL;
      if ($was === NULL) {
        continue;
      }
      $now = static::drawing($element);
      $label = $now['label'] ?? $was['label'];
      if ($label === NULL || trim(strip_tags((string) $label)) === '') {
        continue;
      }
      if ($was['applies'] !== $now['applies']) {
        $sentences[] = $now['applies']
          ? t('@label now applies.', ['@label' => $label])
          : t('@label no longer applies.', ['@label' => $label]);
        continue;
      }
      if (!$now['applies']) {
        continue;
      }
      $said = count($sentences);
      if ($was['required'] !== $now['required']) {
        $sentences[] = $now['required']
          ? t('@label is now required.', ['@label' => $label])
          : t('@label is no longer required.', ['@label' => $label]);
      }
      if ([$was['min'], $was['max']] !== [$now['min'], $now['max']]) {
        // Only the bounds there are: a placeholder is never handed NULL.
        $range = array_filter(['@label' => $label, '@min' => $now['min'], '@max' => $now['max']], static fn ($bound): bool => $bound !== NULL);
        $sentences[] = match (TRUE) {
          isset($range['@min'], $range['@max']) => t('@label now allows @min to @max.', $range),
          isset($range['@min']) => t('@label now allows @min or more.', $range),
          isset($range['@max']) => t('@label now allows up to @max.', $range),
          default => t('@label now allows any number.', $range),
        };
      }
      if ($was['options'] !== $now['options']) {
        $sentences[] = t('The choices for @label have changed.', ['@label' => $label]);
      }
      if (count($sentences) === $said && $was['children'] !== $now['children']) {
        $sentences[] = t('@label was updated.', ['@label' => $label]);
      }
    }
    return implode(' ', array_map('strval', $sentences));
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
  protected static function drawnAs(array $element): array {
    $found = [];
    foreach (static::elementChildren($element) as $key) {
      $child = $element[$key];
      if (isset($child[self::REFRESH_KEY])) {
        $found[(string) $child[self::REFRESH_KEY]] = static::drawing($child);
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
  protected static function drawing(array $element): array {
    // The marker a person is shown: a select keeping a stale value is not
    // checked as required, and keeps the marker on its label.
    $marked = in_array('form-required', (array) ($element['#label_attributes']['class'] ?? []), TRUE);
    return [
      'label' => $element['#title'] ?? NULL,
      'applies' => empty($element[self::PLACEHOLDER_KEY]),
      'required' => !empty($element['#required']) || !empty($element[self::REQUIRED_KEY]) || $marked,
      'min' => isset($element['#min']) ? (string) $element['#min'] : NULL,
      'max' => isset($element['#max']) ? (string) $element['#max'] : NULL,
      'options' => static::optionsSignature($element),
      'children' => in_array($element['#type'] ?? NULL, self::GROUPING_TYPES, TRUE) ? static::elementChildren($element) : NULL,
    ];
  }

  /**
   * Finds the data surface container around an element of a built form.
   *
   * The walk reads the form and never writes to it: a walk by reference
   * would create every key it looked for.
   *
   * @param array $form
   *   The form.
   * @param array $array_parents
   *   The element's #array_parents.
   *
   * @return array|null
   *   The nearest container above the element, or NULL when none is.
   */
  protected static function containerOf(array $form, array $array_parents): ?array {
    $parents = $array_parents;
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
    return NULL;
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
    // data surface: the person has said one thing, about one key, and every
    // other key is still mid-edit. Scoped to the container, the rebuild
    // validated the whole data surface, so a dependent holding a value the
    // new choice had just orphaned was flagged as a wrong answer to a
    // question nobody asked, and, because Form API skips the rebuild
    // outright once anything has errored, the container came back
    // refined against the old choice as well. Both halves of the bug
    // were that one line.
    //
    // The data surface's real validation is unaffected: it runs on submit,
    // through the host's validate stage, where every key has been
    // answered on purpose.
    //
    // At any depth: an attached child's own dependency sits inside the
    // child's details, and is as much a trigger as a top level key.
    $wrapper_id = $element[self::WRAPPER_KEY] ?? NULL;
    if (is_string($wrapper_id)) {
      // Each list of free values the list widget drew, at any depth: the
      // widget cannot know where it sits, and this is the first moment
      // anything does.
      static::wireRows($element, [], $wrapper_id);
    }
    // Before any child is processed, so before Form API checks one.
    foreach (static::elementChildren($element) as $key) {
      $element[$key] = static::requiredHints($element[$key], \Drupal::service('element_info'));
    }
    static::limitAjax($element, $element['#parents'] ?? [], !empty($element['#tree']));
    // The wrappers the AJAX callback replaces by. Placed here rather than
    // at build time because the container's id is only final once a host
    // has merged it into its own element, and every wrapper id is derived
    // from it.
    if (is_string($wrapper_id)) {
      $state = $form_state instanceof SubformStateInterface ? $form_state->getCompleteFormState() : $form_state;
      if ($state->getTemporaryValue([self::RENDERED_OPTIONS, static::containerKey($element)]) === NULL) {
        // The first build of a request is the form the page was rendered
        // from; a rebuild later in the same request is not.
        $state->setTemporaryValue([self::RENDERED_OPTIONS, static::containerKey($element)], static::triggerOptions($element));
        $state->setTemporaryValue([self::DRAWN_AS, static::containerKey($element)], static::drawnAs($element));
      }
      static::wrapRefreshed($element, $wrapper_id);
      if (isset($element[self::STALE_MARKER_KEY]) && !isset($element[self::STALE_MARKER_KEY][self::REFRESH_ID_KEY])) {
        $element[self::STALE_MARKER_KEY] = static::wrapped($element[self::STALE_MARKER_KEY], static::reservedId($wrapper_id, 'stale'));
      }
    }
    return $element;
  }

  /**
   * Makes the #required of every value element below one a hint only.
   *
   * A widget draws a required key with #required, which paints the
   * browser's `required` attribute and the label's marker, and which Form
   * API also checks, before any form level handler: a form keeps the
   * first error per element, so its "Name field is required." replaced
   * the data surface's own, "Name is required when Email is set." So the
   * check is taken away and the drawing kept: #required is cleared on
   * each element that holds a value, REQUIRED_KEY says it was set, and
   * preRenderRequired(), first in the element's #pre_render list, puts
   * it back when the element is drawn, on the page and in an AJAX
   * response alike. A details or a fieldset holds no value and is never
   * checked, so it keeps its #required as it is.
   *
   * Decision: see docs/decisions.md#an-element-hints-and-the-rule-speaks.
   *
   * @param array $element
   *   The element.
   * @param \Drupal\Core\Render\ElementInfoManagerInterface $element_info
   *   The element info manager, for each type's own #pre_render list,
   *   which an explicit one replaces.
   *
   * @return array
   *   The element, and every element below it, with #required a hint.
   */
  protected static function requiredHints(array $element, ElementInfoManagerInterface $element_info): array {
    $type = $element['#type'] ?? NULL;
    if (!empty($element['#required']) && is_string($type) && !empty($element_info->getInfo($type)['#input'])) {
      $element['#required'] = FALSE;
      $element[self::REQUIRED_KEY] = TRUE;
      $element['#pre_render'] = [
        [static::class, 'preRenderRequired'],
        ...($element['#pre_render'] ?? $element_info->getInfo($type)['#pre_render'] ?? []),
      ];
    }
    foreach (static::elementChildren($element) as $key) {
      $element[$key] = static::requiredHints($element[$key], $element_info);
    }
    return $element;
  }

  /**
   * Draws an element required that Form API did not check as required.
   *
   * @param array $element
   *   The element.
   *
   * @return array
   *   The element, #required as its widget set it.
   */
  public static function preRenderRequired(array $element): array {
    $element['#required'] = !empty($element[self::REQUIRED_KEY]);
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['preRenderSurfaceContainer', 'preRenderRequired'];
  }

  /**
   * {@inheritdoc}
   */
  public static function preRenderSurfaceContainer(array $element): array {
    return static::placeMounts(static::gatherGroups($element));
  }

  /**
   * Adds one element per group its keys' arrangements name, at any depth.
   *
   * A group is drawn where its first key is declared: its element, a
   * fieldset or a details, goes into the frame directly before that key,
   * under GROUP_PREFIX and its id, with the group's own weight when it
   * declares one (a key's weight orders it inside its group). It holds
   * nothing yet: the keys stay where their values are posted until the
   * container is drawn (gatherGroups()). A cosmetic layer reaches it by
   * its key, to put it in vertical tabs, say. First in render order of
   * its members, so a group a borrowed element gathers by Form API's own
   * `#group` is drawn before what it holds.
   *
   * Decision: see docs/decisions.md#arrangement-is-declared-once.
   *
   * @param array $element
   *   The frame: the container, or any element below it.
   *
   * @return array
   *   The frame, with an empty element per group, at any depth.
   */
  protected static function withGroups(array $element): array {
    $groups = [];
    foreach (static::elementChildren($element) as $key) {
      $element[$key] = static::withGroups($element[$key]);
      $group = $element[$key][self::GROUP_KEY] ?? NULL;
      if (!is_array($group) || !is_string($group['id'] ?? NULL)) {
        continue;
      }
      $groups[$group['id']] ??= ['first' => (string) $key, 'group' => $group];
    }
    if ($groups === []) {
      return $element;
    }
    $before = array_column($groups, NULL, 'first');
    $framed = [];
    foreach ($element as $key => $value) {
      if (isset($before[(string) $key])) {
        $group = $before[(string) $key]['group'];
        $drawn = $group['as'] === ArrangementGroup::DETAILS
          ? ['#type' => 'details', '#title' => $group['label'], '#open' => empty($group['collapsed'])]
          : ['#type' => 'fieldset', '#title' => $group['label']];
        if (isset($group['weight'])) {
          $drawn['#weight'] = $group['weight'];
        }
        $framed[self::GROUP_PREFIX . $group['id']] = $drawn;
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
  protected static function gatherGroups(array $element): array {
    foreach (static::elementChildren($element) as $key) {
      $group = $element[$key][self::GROUP_KEY] ?? NULL;
      $into = is_array($group) && is_string($group['id'] ?? NULL) ? self::GROUP_PREFIX . $group['id'] : NULL;
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
   * container its frame's third-party settings render as; it moves to
   * that frame, directly after the named key, and keeps everything it
   * carries: its #parents and #name, so its inputs post where they
   * always did, and its refresh wrappers, so the AJAX callback replaces
   * a key inside it by the same id. A frame is the container or any
   * element below it, so an attached part's own mounts are placed in
   * the part's details.
   *
   * It moves under a key no data surface key can take, so it collides with
   * nothing; with a #weight, when the key it follows has one, so a
   * frame Form API sorts keeps it there. A key an arrangement group holds
   * is followed inside that group, which the React app draws alike.
   *
   * @param array $element
   *   The frame.
   *
   * @return array
   *   The frame, its placed fieldsets moved, at any depth.
   */
  protected static function placeMounts(array $element): array {
    foreach (static::elementChildren($element) as $group) {
      foreach (static::elementChildren($element[$group]) as $mount) {
        $after = $element[$group][$mount][self::PLACED_AFTER_KEY] ?? NULL;
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
        if (static::elementChildren($element[$group]) === [] && !isset($element[$group][self::REFRESH_ID_KEY])) {
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
   *   when an arrangement group drawn there holds it, or NULL when neither
   *   does.
   */
  protected static function holding(array $element, string $key): ?string {
    if (isset($element[$key]) && is_array($element[$key])) {
      return '';
    }
    foreach (static::elementChildren($element) as $child) {
      if (str_starts_with((string) $child, self::GROUP_PREFIX) && isset($element[$child][$key]) && is_array($element[$child][$key])) {
        return (string) $child;
      }
    }
    return NULL;
  }

  /**
   * Limits every #ajax below an element to that element's own value.
   *
   * @param array $element
   *   The element whose children are walked.
   * @param array $parents
   *   The element's #parents.
   * @param bool $tree
   *   The element's #tree.
   */
  protected static function limitAjax(array &$element, array $parents, bool $tree): void {
    foreach (static::elementChildren($element) as $key) {
      // The child's own value path, computed the way Form API is about
      // to compute it: a child inherits its parent's #tree, and is
      // nested under its parent only when both are trees; anything else
      // is top level.
      $child_tree = (bool) ($element[$key]['#tree'] ?? $tree);
      $child_parents = $element[$key]['#parents'] ?? ($child_tree && $tree ? [...$parents, $key] : [$key]);
      if (isset($element[$key]['#ajax']) && !isset($element[$key]['#limit_validation_errors'])) {
        $element[$key]['#limit_validation_errors'] = [$child_parents];
      }
      static::limitAjax($element[$key], $child_parents, $child_tree);
    }
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
  protected static function wireRows(array &$element, array $path, string $wrapper_id): void {
    foreach (static::elementChildren($element) as $key) {
      $at = [...$path, (string) $key];
      if (empty($element[$key][self::ROWS_KEY])) {
        static::wireRows($element[$key], $at, $wrapper_id);
        continue;
      }
      $dotted = implode('.', $at);
      $element[$key][self::REFRESH_KEY] = $dotted;
      foreach (static::elementChildren($element[$key]) as $control) {
        if (!isset($element[$key][$control][self::ROW_CONTROL_KEY])) {
          continue;
        }
        $named = array_map([Html::class, 'getId'], [...$at, (string) $control]);
        $element[$key][$control]['#name'] = 'data_surface_' . implode('_', $named);
        $element[$key][$control]['#ajax'] = [
          'callback' => [static::class, 'refreshSurface'],
          'wrapper' => $wrapper_id,
          'submit' => [self::WRAPPER_INPUT => $wrapper_id],
        ];
        $element[$key][$control][self::TRIGGER_KEY] = ['path' => [...$at, (string) $control], 'replaces' => [$dotted]];
      }
    }
  }

  /**
   * Gives every element the AJAX callback may replace its wrapper.
   *
   * @param array $element
   *   The element whose children are walked.
   * @param string $wrapper_id
   *   The container's id.
   */
  protected static function wrapRefreshed(array &$element, string $wrapper_id): void {
    foreach (static::elementChildren($element) as $key) {
      if (isset($element[$key][self::REFRESH_KEY]) && !isset($element[$key][self::REFRESH_ID_KEY])) {
        $element[$key] = static::wrapped($element[$key], static::refreshId($wrapper_id, (string) $element[$key][self::REFRESH_KEY]));
      }
      static::wrapRefreshed($element[$key], $wrapper_id);
    }
  }

  /**
   * Wraps an element in a div the AJAX callback can replace by id.
   *
   * A prefix and a suffix rather than a theme wrapper: they sit outside
   * everything the element renders, its own form element wrapper and
   * description included, so the replacement carries all of it, and they
   * do not touch the element's own attributes, which are the input's.
   *
   * @param array $element
   *   The element.
   * @param string $id
   *   The wrapper's id.
   *
   * @return array
   *   The element, wrapped, and carrying the id.
   */
  protected static function wrapped(array $element, string $id): array {
    $element[self::REFRESH_ID_KEY] = $id;
    $element['#prefix'] = '<div id="' . Html::escape($id) . '">' . ($element['#prefix'] ?? '');
    $element['#suffix'] = ($element['#suffix'] ?? '') . '</div>';
    return $element;
  }

  /**
   * Derives the wrapper id of an element from its container's.
   *
   * Unique wherever the container's id is, because no two elements of
   * one container share a path, and the same on every rebuild, because
   * the container's is.
   *
   * @param string $wrapper_id
   *   The container's id.
   * @param string $dotted
   *   The element's dotted path below the container.
   *
   * @return string
   *   The id.
   */
  protected static function refreshId(string $wrapper_id, string $dotted): string {
    return $wrapper_id . '--' . implode('--', array_map([Html::class, 'getId'], explode('.', $dotted)));
  }

  /**
   * Derives the id of one of the container's own wrappers.
   *
   * With an underscore, which Html::getId() never leaves in a key's part
   * of a refreshId(), so no data surface key can collide with it.
   *
   * @param string $wrapper_id
   *   The container's id.
   * @param string $name
   *   What the wrapper holds.
   *
   * @return string
   *   The id.
   */
  protected static function reservedId(string $wrapper_id, string $name): string {
    return $wrapper_id . '__' . $name;
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
    $posted = $state->getUserInput()[self::WRAPPER_INPUT] ?? NULL;
    $generated = Html::getId($wrapper_key . '-wrapper');
    return is_string($posted)
      && preg_match('/^[A-Za-z0-9_-]+$/', $posted)
      && ($posted === $generated || str_starts_with($posted, $generated . '--'))
      ? $posted
      : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function findSurfaceContainer(array $form): array {
    $found = static::searchSurfaceContainer($form);
    if ($found === NULL) {
      throw new \LogicException(sprintf(
        'No data surface container was found: nothing in the given form carries the "%s" marker. The container built by buildSurfaceForm() must reach extraction intact, because a data surface whose container cannot be found collects no values at all and every stored value is replaced by its default.',
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
    $definitions = $surface->getDefinitions();
    $variantKeys = [];
    foreach ($definitions->entries() as $name => $entry) {
      if ($entry->variants !== NULL) {
        $variantKeys[] = $name;
        continue;
      }
      if ($entry->list !== NULL) {
        $raw += $this->extractCollection($entry, $container, $form_state);
        continue;
      }
      $raw += $this->extractKey((string) $name, $entry->definition, $container, $form_state);
    }
    if ($variantKeys !== []) {
      // A key's element is the chosen variant's, so it is read through
      // that variant's shape, chosen by what the deciding key holds in
      // this same submission.
      $resolved = $definitions->withVariantsResolved($raw + $current + $surface->getDefaultValues());
      foreach ($variantKeys as $name) {
        $definition = $resolved->get((string) $name);
        if ($definition !== NULL && DefinitionMetadata::variantsBy($definition) === NULL) {
          $raw += $this->extractKey((string) $name, $definition, $container, $form_state);
        }
      }
    }
    // One coercion path for every caller: the form hands its raw tree to
    // the same accept() a payload goes through, which is also where
    // locked keys take the value the data surface declares.
    return $this->pipeline->accept($surface, $raw, $current);
  }

  /**
   * Reads a collection's items out of the container, if it was rendered.
   *
   * Each item drawn is read through the child's map, in the order drawn.
   * An item that holds nothing at all, a row added and left blank, is
   * dropped here, before the pipeline would drop it, and the delta each
   * item kept was drawn at is remembered for the request, so a violation
   * about it is flagged on the element it came from.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The collection.
   * @param array $container
   *   The data surface container.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string|null $container_key
   *   The data surface container's key, for a list inside an item, whose
   *   container is the item; NULL for the container's own.
   *
   * @return array
   *   The items keyed by data surface key, or nothing for a list with no
   *   element.
   */
  protected function extractCollection(Entry $entry, array $container, FormStateInterface $form_state, ?string $container_key = NULL): array {
    $container_key ??= static::containerKey($container);
    $name = $entry->name;
    $element = $container[$name] ?? NULL;
    $item = $entry->definition instanceof ListDataDefinitionInterface ? $entry->definition->getItemDefinition() : NULL;
    if (!is_array($element) || !isset($element[self::LIST_KEY]) || $item === NULL) {
      return [];
    }
    // A collection inside an item is read as one, and the item's map
    // reads the rest.
    $child = $entry->list?->child();
    $inner = [];
    $map = $item;
    if ($child !== NULL && $item instanceof ComplexDataDefinitionInterface) {
      $map = MapDataDefinition::create();
      foreach ($item->getPropertyDefinitions() as $property => $definition) {
        $nested = $child->getDefinitions()->entry((string) $property);
        if ($nested?->list !== NULL) {
          $inner[] = $nested;
          continue;
        }
        $map->setPropertyDefinition((string) $property, $definition);
      }
    }
    $widget = $this->widgetManager->getWidgetFor($map);
    $items = [];
    $deltas = [];
    foreach (static::elementChildren($element) as $delta) {
      if (!is_int($delta)) {
        continue;
      }
      $value = $widget->extractValue($map, $element[$delta], $form_state, [$name, $delta]);
      foreach ($inner as $nested) {
        $value += $this->extractCollection($nested, $element[$delta], $form_state, $container_key);
      }
      if (Collection::isEmptyItem($value)) {
        continue;
      }
      $items[] = $value;
      $deltas[] = $delta;
    }
    $state = $form_state instanceof SubformStateInterface ? $form_state->getCompleteFormState() : $form_state;
    $state->setTemporaryValue([self::LIST_DELTAS, $container_key, $element[self::LIST_KEY]], $deltas);
    return [$name => $items];
  }

  /**
   * Reads one key's raw value out of the container, if it was rendered.
   *
   * @param string $name
   *   The data surface key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $definition
   *   The definition to read it through.
   * @param array $container
   *   The data surface container.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The raw value keyed by data surface key, or nothing for a key with no
   *   element: it was never offered, so it has nothing to say, and
   *   accept() keeps whatever the key already holds.
   */
  protected function extractKey(string $name, ?DataDefinitionInterface $definition, array $container, FormStateInterface $form_state): array {
    if ($definition === NULL || !isset($container[$name]) || !is_array($container[$name]) || !empty($container[$name][self::PLACEHOLDER_KEY])) {
      // A placeholder is a wrapper and nothing else (variants nothing
      // chose, a key that does not apply): no element was offered, so the
      // key has said nothing.
      return [];
    }
    return [
      $name => $this->widgetManager->getWidgetFor($definition)
        ->extractValue($definition, $container[$name], $form_state, [$name]),
    ];
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
    $trigger = static::triggerPath($container, $form_state);
    $state = $form_state instanceof SubformStateInterface ? $form_state->getCompleteFormState() : $form_state;
    // Printed at the top of the page, a message is named for its element;
    // Inline Form Errors prints it beside the element, and links it from
    // its own summary by the element's title.
    // Decision: see docs/decisions.md#an-element-hints-and-the-rule-speaks.
    $beside = $this->moduleHandler->moduleExists('inline_form_errors')
      && empty($state->getCompleteForm()['#disable_inline_form_errors']);
    foreach ($errors as $violation) {
      $segments = $violation->path === '' ? [] : explode('.', $violation->path);
      $dotted = implode('.', [$violation->key, ...$segments]);
      // Decision: see docs/decisions.md#an-error-on-the-trigger-renders-inline.
      if ($trigger !== NULL && ($dotted === $trigger || str_starts_with($dotted, $trigger . '.'))) {
        $held = $state->getTemporaryValue(self::TRIGGER_ERRORS) ?? [];
        $held[static::containerKey($container)][$trigger][] = $violation->message;
        $state->setTemporaryValue(self::TRIGGER_ERRORS, $held);
        continue;
      }
      $element = $container[$violation->key] ?? NULL;
      foreach ($segments as $position => $segment) {
        if (isset($element[self::LIST_KEY]) && ctype_digit((string) $segment)) {
          // An item is flagged where it was drawn, which an empty item
          // dropped before it may have moved: at any depth, a list inside
          // an item too.
          $list = $element[self::LIST_KEY];
          $deltas = $state->getTemporaryValue([self::LIST_DELTAS, static::containerKey($container), $list]) ?? [];
          $segment = $segments[$position] = (string) ($deltas[(int) $segment] ?? $segment);
        }
        if (!empty($element[self::ROWS_KEY]) && ctype_digit((string) $segment) && isset($element['#array_parents'])) {
          // An item of a free list is flagged on the row it was drawn as,
          // which an empty row dropped before it may have moved.
          $rows = $state->getTemporaryValue([self::ROW_DELTAS, implode('][', $element['#array_parents'])]) ?? [];
          $segment = $segments[$position] = (string) ($rows[(int) $segment] ?? $segment);
        }
        if (!isset($element[$segment])) {
          break;
        }
        $element = $element[$segment];
      }
      if (is_array($element) && isset($element['#parents'])) {
        $form_state->setError($element, $beside ? $violation->message : static::named($element, $violation->message));
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
   * Names the element a message is about, unless the message does.
   *
   * A constraint's own words name no field ("This value should be between
   * 1 and 1000."), which is enough beside the element and nowhere else:
   * printed at the top of the page, or announced, the message is put
   * after the element's title. One that already begins with the title,
   * as a required key's does, and an element with no title are left as
   * they are. The message stays the object it was, a placeholder of the
   * sentence that names it.
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
  protected static function named(array $element, string|\Stringable $message): string|\Stringable {
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
   * Reads which element of this container triggered a refinement request.
   *
   * @param array $container
   *   The data surface container, processed.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return string|null
   *   The trigger's dotted path below the container, or NULL when the
   *   request is not a refinement of this container: a submission, or a
   *   trigger in another container on the same form.
   */
  protected static function triggerPath(array $container, FormStateInterface $form_state): ?string {
    $state = $form_state instanceof SubformStateInterface ? $form_state->getCompleteFormState() : $form_state;
    $triggering = $state->getTriggeringElement() ?? [];
    $path = $triggering[self::TRIGGER_KEY]['path'] ?? NULL;
    if (!is_array($path) || $path === []) {
      return NULL;
    }
    $element = NestedArray::getValue($container, $path);
    $parents = $triggering['#array_parents'] ?? NULL;
    if (is_array($parents) && in_array($element['#type'] ?? NULL, self::OPTION_GROUP_TYPES, TRUE)) {
      // One radio or checkbox of the group triggered.
      array_pop($parents);
    }
    return is_array($element) && isset($element['#array_parents']) && $element['#array_parents'] === $parents
      ? implode('.', $path)
      : NULL;
  }

  /**
   * Names a container by its place in the form.
   *
   * What a request holds for a container is keyed by this rather than
   * by its id: a rebuild that posted no id generates a new one, and the
   * place is the same in every build.
   *
   * @param array $container
   *   The container, processed far enough to know its #array_parents.
   *
   * @return string
   *   The key.
   */
  protected static function containerKey(array $container): string {
    return implode('][', $container['#array_parents'] ?? []);
  }

  /**
   * Lists what every trigger with options below an element offers.
   *
   * @param array $element
   *   The container, or an element inside it.
   *
   * @return array<string, string>
   *   Each trigger's options signature, keyed by its dotted path.
   */
  protected static function triggerOptions(array $element): array {
    $found = [];
    foreach (static::elementChildren($element) as $key) {
      $child = $element[$key];
      $path = $child[self::TRIGGER_KEY]['path'] ?? NULL;
      $signature = is_array($path) ? static::optionsSignature($child) : NULL;
      if ($signature !== NULL) {
        $found[implode('.', $path)] = $signature;
      }
      $found += static::triggerOptions($child);
    }
    return $found;
  }

  /**
   * Says which options an element offers, the empty one included.
   *
   * The same before and after Form API processes a select, which turns
   * its #empty_option into an option keyed by the empty string.
   *
   * @param array $element
   *   The element.
   *
   * @return string|null
   *   The signature, or NULL for an element with no options.
   */
  protected static function optionsSignature(array $element): ?string {
    if (!isset($element['#options']) || !is_array($element['#options'])) {
      return NULL;
    }
    $keys = array_map('strval', array_keys(OptGroup::flattenOptions($element['#options'])));
    $empty = isset($element['#empty_option']) || in_array('', $keys, TRUE);
    return serialize([array_values(array_diff($keys, [''])), $empty]);
  }

  /**
   * Puts the trigger's own errors under it, and takes them off the top.
   *
   * Two sources, one rendering. What flagSurfaceErrors() held for the
   * trigger; and what Form API's own element validation said about it,
   * a maximum length, which stops the rebuild, and which Form API has
   * also put in the messages at the top of the page: it is taken back
   * out of them, so the message is said once.
   *
   * @param array $element
   *   The trigger, as the callback is about to render it.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the request.
   * @param string $container_key
   *   The container's place in the form, from containerKey().
   * @param string $dotted
   *   The trigger's dotted path below the container.
   *
   * @return array
   *   The trigger, carrying its errors when it has any.
   */
  protected static function withTriggerErrors(array $element, FormStateInterface $form_state, string $container_key, string $dotted): array {
    $state = $form_state instanceof SubformStateInterface ? $form_state->getCompleteFormState() : $form_state;
    $messages = $state->getTemporaryValue([self::TRIGGER_ERRORS, $container_key, $dotted]) ?? [];
    $own = $element['#errors'] ?? NULL;
    if ($own !== NULL && $own !== '') {
      // One answer per element, as Form API keeps one: the data surface's own
      // when it has one, a constraint's message being the most exact
      // thing anyone can say about the value; Form API's otherwise.
      $messages = $messages === [] ? [$own] : $messages;
      // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
      $messenger = \Drupal::messenger();
      foreach ($messenger->deleteByType(MessengerInterface::TYPE_ERROR) as $message) {
        if ((string) $message !== (string) $own) {
          $messenger->addError($message);
        }
      }
    }
    if ($messages === []) {
      return $element;
    }
    $element['#errors'] = count($messages) === 1 ? reset($messages) : [
      '#theme' => 'item_list',
      '#items' => $messages,
    ];
    // Printed with an id the input names after its description, so the
    // error is read out with the field; the module's preprocess prints
    // it, and Inline Form Errors, which would print it without one, is
    // told the element's message is printed elsewhere.
    $error_id = isset($element['#id']) && is_string($element['#id']) ? $element['#id'] . '--error' : NULL;
    $element[self::INLINE_ERROR_KEY] = $error_id ?? TRUE;
    if ($error_id !== NULL) {
      $element['#error_no_message'] = TRUE;
      $described = trim(($element['#attributes']['aria-describedby'] ?? '') . ' ' . $error_id);
      $element['#attributes']['aria-describedby'] = $described;
    }
    // What core sets on an element that failed validation, said here for
    // a trigger that was rebuilt rather than failed: the request held its
    // error back so the rebuild could go ahead.
    $element['#attributes']['aria-invalid'] = 'true';
    $classes = $element['#attributes']['class'] ?? [];
    if (!in_array('error', $classes, TRUE)) {
      $element['#attributes']['class'][] = 'error';
    }
    // The trigger's #ajax was processed when the form was built, so its
    // settings are amended where they are attached: its next request
    // says it showed an error, and is answered by replacing it again.
    if (isset($element['#id'], $element['#attached']['drupalSettings']['ajax'][$element['#id']])) {
      $element['#attached']['drupalSettings']['ajax'][$element['#id']]['submit'][self::INVALID_INPUT] = '1';
    }
    return $element;
  }

  /**
   * Wires a refinement dependency to rebuild the data surface over AJAX.
   *
   * A widget that renders a grouping element — the map widget's details
   * — is skipped on purpose. A details is not an input: it emits no
   * change event, so an #ajax on it never fires, which is worse than
   * nothing because the form looks wired and is not. Attaching to each
   * leaf inside it instead was considered and rejected: a refiner
   * depends on the whole value of a key, so a rebuild fired by one leaf
   * would refine against a half-filled map, and the leaves of a map are
   * exactly where a person types rather than chooses. A map dependency
   * is therefore declared and left unwired; the data surface still refines
   * when the form is submitted, or when a scalar dependency is touched.
   *
   * A trigger whose change fires on blur, a number or a text input, is
   * marked disable-refocus. Its rebuild starts when the person leaves the
   * field, and core puts focus back on the trigger after the response
   * unless something else with a selector holds it: a click on blank page
   * space leaves the body focused, so focus snapped back to the field the
   * person had just left, and a refused value made that a loop. Focus now
   * stays where the person put it, and core's refocus-blur, which it sets
   * on these types, still carries it to the field they tabbed to. A
   * select or a checkbox changes while focused and keeps core's refocus,
   * because it is disabled during the request and would otherwise lose
   * focus to the body.
   *
   * The wrapper the #ajax names is the container's. The callback answers
   * with commands that name their own targets, so the wrapper is only
   * used when it falls back to returning the container; and the id is
   * sent back with every request, so the rebuild takes the same one.
   *
   * @param array $element
   *   The element built for the dependency.
   * @param string $wrapper_id
   *   The DOM id of the container.
   * @param string[] $path
   *   The element's path below the container.
   * @param string[] $replaces
   *   The dotted paths of the elements depending on it, transitively.
   *
   * @return array
   *   The element, wired or left as it was.
   */
  protected function attachRefinementAjax(array $element, string $wrapper_id, array $path, array $replaces): array {
    if (!isset($element['#type']) || in_array($element['#type'], self::GROUPING_TYPES, TRUE)) {
      return $element;
    }
    $element['#ajax'] = [
      'callback' => [static::class, 'refreshSurface'],
      'wrapper' => $wrapper_id,
      // Core posts what is under 'submit' with the request, beside the
      // trigger's name, which it puts there itself.
      'submit' => [self::WRAPPER_INPUT => $wrapper_id],
    ];
    if (in_array($element['#type'], self::BLUR_CHANGE_TYPES, TRUE)) {
      // Focus stays where the person put it; core's refocus-blur still
      // carries it to the field they tabbed to.
      $element['#ajax']['disable-refocus'] = TRUE;
    }
    $element[self::TRIGGER_KEY] = ['path' => $path, 'replaces' => $replaces];
    // A wrapper of its own as well: a trigger is replaced when its own
    // value was refused, so the error is printed under it.
    $element[self::REFRESH_KEY] ??= implode('.', $path);
    if (in_array($element['#type'], self::OPTION_GROUP_TYPES, TRUE)) {
      // Each radio or checkbox is what triggers, and Form API copies the
      // #ajax of the group onto it and nothing else of ours.
      $declared = $element['#process'] ?? $this->elementInfo->getInfo($element['#type'])['#process'] ?? [];
      $element['#process'] = array_merge($declared, [[static::class, 'processOptionTrigger']]);
    }
    return $element;
  }

  /**
   * Gives each radio or checkbox of a trigger what the group carries.
   *
   * Which of the group's own triggered is what Form API records, and it
   * runs a trigger's #limit_validation_errors and our path from it: the
   * path the rebuild finds the container by and replaces what depends on
   * it from.
   *
   * @param array $element
   *   The radios or checkboxes, processed into their options.
   *
   * @return array
   *   The same, each option carrying the group's trigger.
   */
  public static function processOptionTrigger(array $element): array {
    foreach (static::elementChildren($element) as $key) {
      foreach ([self::TRIGGER_KEY, '#limit_validation_errors'] as $property) {
        if (isset($element[$property]) && is_array($element[$key])) {
          $element[$key][$property] = $element[$property];
        }
      }
    }
    return $element;
  }

  /**
   * Marks what a rebuild may replace and wires what triggers one.
   *
   * One frame at a time, the data surface's own and then each child's, under
   * the names each frame gives its keys: a child's refiners run in the
   * child's frame, so its dependencies and its targets are the child's
   * keys, and what one of them moves is inside that child and nowhere
   * else.
   *
   * @param array $element
   *   The container, or the element an attached child or a variant is
   *   rendered as.
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface or built child the element renders.
   * @param array $values
   *   The values the frame is rendered with.
   * @param string[] $path
   *   The frame's path below the container.
   * @param string $wrapper_id
   *   The container's id.
   *
   * @return array
   *   The element, marked and wired.
   */
  protected function wireRefinement(array $element, DataSurfaceInterface $surface, array $values, array $path, string $wrapper_id): array {
    $definitions = $surface->getDefinitions();
    // One property at a time inside the mount: a key an alter refines
    // there is a target of its own, and a key it watches there a trigger.
    $refinements = $definitions->refinementPaths();
    foreach (array_keys($refinements) as $target) {
      $parents = explode('.', (string) $target);
      if (count($parents) > 1 && is_array(NestedArray::getValue($element, $parents))) {
        NestedArray::setValue($element, [...$parents, self::REFRESH_KEY], implode('.', [...$path, ...$parents]));
      }
    }
    foreach ($definitions->entries() as $name => $entry) {
      $name = (string) $name;
      if (!isset($element[$name]) || !is_array($element[$name])) {
        continue;
      }
      $at = [...$path, $name];
      if (isset($refinements[$name])) {
        // A target (a key with variants is one too, of its deciding key)
        // has a wrapper of its own, which is what the rebuild replaces.
        $element[$name][self::REFRESH_KEY] = implode('.', $at);
      }
      if ($entry->list !== NULL) {
        // Each item is a frame of its own: what refines inside an item is
        // wired inside it, and replaces what it moves there and nothing
        // else.
        $child = $entry->list->child();
        $held = Collection::items($values[$name] ?? NULL);
        foreach (static::elementChildren($element[$name]) as $delta) {
          if (is_int($delta) && is_array($element[$name][$delta])) {
            $item = $held[$delta] ?? [];
            $element[$name][$delta] = $this->wireRefinement(
              $element[$name][$delta],
              $child,
              array_replace($child->getDefaultValues(), is_array($item) ? $item : []),
              [...$at, (string) $delta],
              $wrapper_id,
            );
          }
        }
        continue;
      }
      if (!$entry->isNested() || !empty($element[$name][self::PLACEHOLDER_KEY])) {
        continue;
      }
      $child = $entry->attachment?->child;
      $held = $values[$name] ?? NULL;
      if ($entry->variants !== NULL) {
        // The variant the key was rendered as, from what it was rendered
        // with: its own defaults when what is held was another's.
        $chosen = $entry->variants->chosen($values[$entry->variants->by] ?? $surface->getDefault($entry->variants->by));
        $child = $chosen === NULL ? NULL : $entry->variants->variant($chosen)->child;
        $held = $chosen !== NULL && $entry->variants->fits($chosen, $held) ? $held : NULL;
      }
      if ($child !== NULL) {
        $element[$name] = $this->wireRefinement(
          $element[$name],
          $child,
          array_replace($child->getDefaultValues(), is_array($held) ? $held : []),
          $at,
          $wrapper_id,
        );
      }
    }
    foreach ($definitions->refinementDependencies() as $dependency) {
      $parents = explode('.', $dependency);
      $trigger = NestedArray::getValue($element, $parents);
      if (!is_array($trigger)) {
        continue;
      }
      NestedArray::setValue($element, $parents, $this->attachRefinementAjax(
        $trigger,
        $wrapper_id,
        [...$path, ...$parents],
        array_map(
          static fn (string $target): string => implode('.', [...$path, $target]),
          static::dependentsOf($dependency, $refinements),
        ),
      ));
    }
    return $element;
  }

  /**
   * Lists the keys depending on one key, directly or through others.
   *
   * The closure the discard cascade walks: a target of the key, then a
   * target of that target, until nothing new is reached. A change to the
   * key may move every one of them and nothing else.
   *
   * @param string $key
   *   The key that changed.
   * @param array<string, string[]> $refinements
   *   The frame's refinement map, one mounted key at a time: target =>
   *   the keys it refines against.
   *
   * @return string[]
   *   The dependents, in declaration order; never the key itself.
   */
  protected static function dependentsOf(string $key, array $refinements): array {
    $reached = [];
    $queue = [$key];
    while ($queue !== []) {
      $moved = array_shift($queue);
      foreach ($refinements as $target => $dependencies) {
        $target = (string) $target;
        if ($target !== $key && !isset($reached[$target]) && in_array($moved, $dependencies, TRUE)) {
          $reached[$target] = TRUE;
          $queue[] = $target;
        }
      }
    }
    return array_values(array_filter(
      array_map('strval', array_keys($refinements)),
      static fn (string $target): bool => isset($reached[$target]),
    ));
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
   * Builds the #pre_render list for the container, keeping the type's own.
   *
   * The same rule as surfaceProcess(), for the same reason: an explicit
   * #pre_render replaces the element type's list, and a container that
   * lost preRenderGroup would draw no #group inside it.
   *
   * @param string|null $type
   *   The effective element type of the container, or NULL when it has
   *   none.
   *
   * @return array
   *   The #pre_render list.
   */
  protected function surfacePreRender(?string $type): array {
    $declared = $type === NULL ? [] : ($this->elementInfo->getInfo($type)['#pre_render'] ?? []);
    return array_merge($declared, [[static::class, 'preRenderSurfaceContainer']]);
  }

  /**
   * Draws fixed what a child locked inside itself.
   *
   * A child's map is drawn by its widget from the child's definitions,
   * which do not say what the child locked: an entry of a map of keys
   * from the site its provider locked, say. Each such element is drawn
   * fixed as a locked key at the top is, and a child inside is walked the
   * same way.
   *
   * Decision: see docs/decisions.md#keys-that-come-from-the-site.
   *
   * @param array $element
   *   The child's element.
   * @param \Drupal\data_surface\DataSurfaceInterface $child
   *   The child.
   *
   * @return array
   *   The element.
   */
  protected static function lockedInside(array $element, DataSurfaceInterface $child): array {
    $definitions = $child->getDefinitions();
    foreach ($definitions->entries() as $name => $entry) {
      $name = (string) $name;
      if (!isset($element[$name]) || !is_array($element[$name])) {
        continue;
      }
      if ($definitions->isLocked($name)) {
        $element[$name]['#disabled'] = TRUE;
        $element[$name] = static::describeLocked($element[$name], $entry->lockedBecause);
        continue;
      }
      if ($entry->attachment !== NULL && $entry->variants === NULL) {
        $element[$name] = static::lockedInside($element[$name], $entry->attachment->child);
      }
    }
    return $element;
  }

  /**
   * Appends the reason a locked element cannot be changed.
   *
   * A disabled input tells a sighted user it is fixed and tells a screen
   * reader nothing about why, so the reason goes in the description
   * where every user reaches it: the access answer's own, when the
   * answer that locked the key said why, and otherwise a sentence that
   * says only that it is fixed.
   *
   * Decision: see docs/decisions.md#locked-at-the-top.
   *
   * @param array $element
   *   The locked element.
   * @param string|\Stringable|null $reason
   *   Why the access answer locked it, or NULL when none said.
   *
   * @return array
   *   The element, with the reason appended to its description.
   */
  protected static function describeLocked(array $element, string|\Stringable|null $reason = NULL): array {
    $note = $reason !== NULL && (string) $reason !== '' ? $reason : t('Fixed for this operation.');
    $element['#description'] = isset($element['#description'])
      ? t('@description @note', [
        '@description' => $element['#description'],
        '@note' => $note,
      ])
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
    return static::retargetAjax($container, $wrapper_id);
  }

  /**
   * Points every refinement #ajax below an element at a container id.
   *
   * At any depth, because an attached child's own dependency is wired
   * too, and the id the request sends back with it as well as the
   * wrapper, so the rebuild takes the id the page holds.
   *
   * @param array $element
   *   The element whose children are walked.
   * @param string $wrapper_id
   *   The id to point at.
   *
   * @return array
   *   The element, re-pointed.
   */
  protected static function retargetAjax(array $element, string $wrapper_id): array {
    foreach (static::elementChildren($element) as $key) {
      if (isset($element[$key]['#ajax']['wrapper'])) {
        $element[$key]['#ajax']['wrapper'] = $wrapper_id;
      }
      if (isset($element[$key]['#ajax']['submit'][self::WRAPPER_INPUT])) {
        $element[$key]['#ajax']['submit'][self::WRAPPER_INPUT] = $wrapper_id;
      }
      $element[$key] = static::retargetAjax($element[$key], $wrapper_id);
    }
    return $element;
  }

  /**
   * Lists the dotted paths of the elements standing for a stale value.
   *
   * @param array $element
   *   The container, or an element inside it.
   * @param string $prefix
   *   The path so far.
   *
   * @return string[]
   *   The paths, relative to the container.
   */
  protected static function stalePaths(array $element, string $prefix = ''): array {
    $paths = [];
    foreach (static::elementChildren($element) as $key) {
      $path = $prefix . $key;
      if (array_key_exists(DataSurfaceWidgetBase::STALE_KEY, $element[$key])) {
        $paths[] = $path;
        continue;
      }
      $paths = array_merge($paths, static::stalePaths($element[$key], $path . '.'));
    }
    return $paths;
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
