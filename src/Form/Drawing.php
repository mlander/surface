<?php

declare(strict_types=1);

namespace Drupal\data_surface\Form;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\Computed;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Refinement\DoesNotApply;
use Drupal\data_surface\SettledCacheability;
use Drupal\data_surface\Resource\ArrangementGroup;
use Drupal\data_surface\Resource\Compute;
use Drupal\data_surface\Plugin\DataSurfaceWidget\OptionsWidget;
use Drupal\data_surface\Pipeline\Sentences;
use Drupal\data_surface\Collection;
use Drupal\data_surface\Widget\DataSurfaceWidgetBase;
use Drupal\data_surface\Widget\DataSurfaceWidgetManager;

/**
 * Draws a data surface as Form API elements.
 *
 * DataSurfaceFormBuilder::buildSurfaceForm() hands this class the data
 * surface and the values to draw it with. Each key is drawn by its widget
 * as its arrangement says; a collection as one details per item, with its
 * "Add another" and "Remove" buttons; a computed key as an item; a key
 * that does not apply, and variants nothing chose, as a wrapper alone; a
 * locked key fixed, with the reason. When the container is rendered, each
 * grouped element is drawn inside its group and each placed fieldset
 * after the key it follows (gatherGroups(), placeMounts()). Refreshing
 * wires what triggers a rebuild and what the rebuild replaces.
 *
 * Decision: see
 * docs/decisions.md#the-form-builder-draws-extracts-and-refreshes.
 *
 * @internal
 *
 * @see \Drupal\data_surface\Form\DataSurfaceFormBuilder
 */
final class Drawing {

  /**
   * Render key marking a collection's element: its dotted path.
   */
  public const LIST_KEY = '#data_surface_list';

  /**
   * Marks the item a computed key is drawn as.
   */
  protected const COMPUTED_KEY = '#data_surface_computed';

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
   * Constructs the drawing of a data surface.
   *
   * @param \Drupal\data_surface\Widget\DataSurfaceWidgetManager $widgetManager
   *   The widget manager, which maps definitions to elements.
   * @param \Drupal\data_surface\Form\Refreshing $refreshing
   *   What wires the drawn container to the rebuild.
   */
  public function __construct(
    protected readonly DataSurfaceWidgetManager $widgetManager,
    protected readonly Refreshing $refreshing,
  ) {
  }

  /**
   * Builds a container of form elements for a data surface.
   *
   * @see \Drupal\data_surface\Form\DataSurfaceFormBuilderInterface::buildSurfaceForm()
   */
  public function buildSurfaceForm(DataSurfaceInterface $surface, array $values, FormStateInterface $form_state, string $wrapper_key = 'data-surface'): array {
    // Two views of one overlay. The data surface is refined against the keys
    // as they stand, an orphan held unanswered; each element is rendered
    // with what it holds, an orphan standing for its stored value, so its
    // select comes up on the empty option with that value behind it.
    $shown = $values;
    $orphans = [];
    foreach ($values[DataSurfaceFormBuilderInterface::STANDING_KEY] ?? [] as $dotted => $standing) {
      NestedArray::setValue($shown, explode('.', (string) $dotted), $standing, TRUE);
      $orphans[] = explode('.', (string) $dotted);
    }
    unset($values[DataSurfaceFormBuilderInterface::STANDING_KEY], $shown[DataSurfaceFormBuilderInterface::STANDING_KEY]);
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
      '#process' => $this->refreshing->surfaceProcess('container'),
      Refreshing::WRAPPER_KEY => $wrapper_id,
    ];
    // Drawn after the type's own, which an explicit list replaces.
    $container['#pre_render'] = $this->refreshing->surfacePreRender('container');
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
        $ruled_out = [
          '#markup' => '',
          DataSurfaceFormBuilderInterface::PLACEHOLDER_KEY => TRUE,
          DataSurfaceFormBuilderInterface::RULED_OUT_KEY => TRUE,
        ];
        $container[$name] = DataSurfaceWidgetBase::arranged($ruled_out, $declared);
        continue;
      }
      $computed = Computed::of($definition);
      if ($computed !== NULL && !$computed->takesSent() && !$definitions->isLocked($name)) {
        // Shown and fixed: nobody types it, and nothing is posted for it.
        // One computed unless sent is drawn by its widget, below.
        // Decision: see
        // docs/decisions.md#a-computed-key-is-a-pure-function-of-its-sources.
        $container[$name] = DataSurfaceWidgetBase::arranged(static::computedItem($definition, $computed, $surface, $shown[$name] ?? NULL), $declared);
        continue;
      }
      // A list of maps no collection was attached for is drawn as one.
      $list = $definitions->entry($name)->list ?? Collection::forList($definition);
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
          $container[$name] = DataSurfaceWidgetBase::arranged([
            '#markup' => '',
            DataSurfaceFormBuilderInterface::PLACEHOLDER_KEY => TRUE,
          ], $declared);
          continue;
        }
        $child = $variants->variant($chosen)->child;
        $held = $values[$name] ?? NULL;
        $definition = $variants->definitionFor($chosen, $child->refine(array_replace(
          $child->getDefaultValues(),
          $variants->held($chosen, $held) ?? [],
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
        $value = $variants->held($chosen, $value) ?? $variants->defaultsOf($chosen);
        // A variant its access locked is offered, and drawn fixed.
        // Decision: see
        // docs/decisions.md#a-variant-is-open-locked-or-not-offered.
        if ($chosen !== '' && static::nestsVariants($variants->variant($chosen)->child)) {
          // Each variant inside it drawn from what it holds, or from its
          // own defaults where its deciding key chose it afresh.
          $value = static::nestedHeld($variants->variant($chosen)->child, $value);
        }
        $variant = $chosen !== '' ? $variants->variant($chosen)->child->lockedWhole() : NULL;
        $locked = $locked || $variant !== NULL;
        $reason ??= $variant;
      }
      $element = DataSurfaceWidgetBase::arranged($this->widgetManager->getWidgetFor($definition)->buildElement($definition, $value), $declared);
      if ($definition instanceof ComplexDataDefinitionInterface) {
        // A list of maps inside the map, at any depth, is a collection in
        // its place: a variant's, an attached child's, or a plain map's.
        $frame = $variants !== NULL ? $variants->variant($chosen)->child : $definitions->entry($name)?->attachment?->child;
        $element = $this->collectionsInside($element, $definition, $frame, $value, [(string) $name], $wrapper_id, $whole);
      }
      if ($variants !== NULL && $chosen !== '') {
        // What the elements were drawn for, read back by extraction and
        // by the rebuild: input drawn for another variant is dropped.
        // Decision: see
        // docs/decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter.
        $element = static::drawnFor($element, $variants->by, $chosen, $variants->variant($chosen)->child, $value);
      }
      if (isset($element[OptionsWidget::UNKEPT_LABEL_KEY]) && !array_key_exists($name, $shown)) {
        // Nothing stored: a declared default the list does not offer is
        // no value to keep, so the empty option does not say "keep".
        $element['#empty_option'] = $element[OptionsWidget::UNKEPT_LABEL_KEY];
      }
      if (!$locked && $computed?->takesSent() && DefinitionMetadata::configTypeOf($definition) === 'machine_name' && $surface->getDefinition($computed->from[0]) !== NULL) {
        // A machine name computed from a title: core's element, suggesting
        // it as the title is typed.
        // Decision: see
        // docs/decisions.md#a-machine-name-is-a-computed-identity.
        $element = MachineNameElement::draw($element, $computed->from[0], $definition);
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
    $container = $this->refreshing->wireRefinement($container, $surface, $values, [], $wrapper_id);
    $absent = Refreshing::ruledOutPaths($container);
    if ($absent !== []) {
      // Decision: see docs/decisions.md#a-key-that-does-not-apply.
      $container[DataSurfaceFormBuilderInterface::ABSENT_MARKER_KEY] = [
        '#type' => 'hidden',
        '#value' => implode(' ', $absent),
      ];
    }
    $container = static::withGroups($container);
    $stale = Refreshing::stalePaths($container);
    if ($stale !== []) {
      // Fixed to this build: what the person is looking at now, not what
      // the previous build posted.
      $container[DataSurfaceFormBuilderInterface::STALE_MARKER_KEY] = [
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
    // A variant built to be drawn adds what it read.
    SettledCacheability::of($surface)->applyTo($container);
    return $container;
  }

  /**
   * Draws a computed key: its value, fixed, and what it is computed from.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key.
   * @param \Drupal\data_surface\Computed $computed
   *   What it is computed from.
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface it belongs to, which labels its sources.
   * @param mixed $value
   *   What it holds for the values shown.
   *
   * @return array
   *   An item, which posts nothing.
   */
  protected static function computedItem(DataDefinitionInterface $definition, Computed $computed, DataSurfaceInterface $surface, mixed $value): array {
    $sources = implode(', ', array_map(static fn (string $source): string => (string) ($surface->getDefinition($source)?->getLabel() ?? $source), $computed->from));
    $note = $computed->when === Compute::ON_CHANGE
      ? t('Computed from @sources, again whenever they change.', ['@sources' => $sources])
      : t('Computed from @sources when this is created, and kept as it is after.', ['@sources' => $sources]);
    $description = $definition->getDescription();
    return [
      '#type' => 'item',
      '#title' => $definition->getLabel(),
      '#plain_text' => is_scalar($value) ? (string) $value : '',
      '#description' => $description !== NULL && (string) $description !== '' ? new Sentences($description, $note) : $note,
      self::COMPUTED_KEY => TRUE,
    ];
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
      Refreshing::REFRESH_KEY => $dotted,
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
      '#submit' => [[DataSurfaceFormBuilder::class, $name === self::ADD_KEY ? 'addListItem' : 'removeListItem']],
      // Nothing is judged: the person said only "one more", or "not this
      // one", and every value is still mid-edit.
      '#limit_validation_errors' => [],
      '#disabled' => $disabled || $locked,
      '#ajax' => [
        'callback' => [DataSurfaceFormBuilder::class, 'refreshSurface'],
        'wrapper' => $wrapper_id,
        'submit' => [DataSurfaceFormBuilderInterface::WRAPPER_INPUT => $wrapper_id],
      ],
      DataSurfaceFormBuilderInterface::TRIGGER_KEY => ['path' => [...$path, ...$at], 'replaces' => [$dotted]],
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
        $inner = $refined->getDefinitions()->entry((string) $name)->list ?? Collection::forList($property);
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
      $drawn = $this->widgetManager->getWidgetFor($map)->buildElement($map, $showing_item);
      $at = [...$path, (string) $delta];
      $element[$delta] = $this->collectionsInside($drawn, $map, $refined, $showing_item, $at, $wrapper_id, $whole);
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
   * Draws each list of maps inside a drawn map as a collection, in its place.
   *
   * The map's widget draws a list of maps as an item standing in for it,
   * since a widget knows neither the list's place in the form nor the
   * container its buttons rebuild; here it is drawn as a collection is at
   * the top of a frame (buildCollection()), at any depth: inside a
   * variant, an attached child, or a plain map. The collection is the
   * frame's, where the list is one attachList() declared, and one sealed
   * from the item's keys otherwise (Collection::forList()).
   *
   * Decision: see
   * docs/decisions.md#the-form-never-refuses-to-draw-a-declared-key.
   *
   * @param array $element
   *   The map's element, as its widget drew it.
   * @param \Drupal\Core\TypedData\ComplexDataDefinitionInterface $definition
   *   The map, as drawn.
   * @param \Drupal\data_surface\DataSurfaceInterface|null $frame
   *   The child the map is drawn from, or NULL for a plain map.
   * @param mixed $value
   *   What the map holds, as drawn.
   * @param string[] $path
   *   The map's path below the container.
   * @param string $wrapper_id
   *   The container's id.
   * @param bool $whole
   *   Whether the data surface is locked whole.
   *
   * @return array
   *   The element, each list of maps in it a collection.
   */
  protected function collectionsInside(array $element, ComplexDataDefinitionInterface $definition, ?DataSurfaceInterface $frame, mixed $value, array $path, string $wrapper_id, bool $whole): array {
    foreach ($definition->getPropertyDefinitions() as $name => $property) {
      $name = (string) $name;
      if (!is_array($element[$name] ?? NULL) || DoesNotApply::marks($property)) {
        continue;
      }
      $entry = $frame?->getDefinitions()->entry($name);
      $held = is_array($value) ? ($value[$name] ?? NULL) : NULL;
      $list = $entry->list ?? Collection::forList($property);
      if ($list !== NULL && $property instanceof ListDataDefinitionInterface) {
        $locked = $frame !== NULL && $frame->getDefinitions()->isLocked($name);
        $at = [...$path, $name];
        $collection = $this->buildCollection($list, $property, $held, $held, $locked, $at, $wrapper_id, $entry?->lockedBecause, $whole);
        $element[$name] = DataSurfaceWidgetBase::arranged($collection, $property);
        continue;
      }
      if (!$property instanceof ComplexDataDefinitionInterface || !empty($element[$name][DataSurfaceFormBuilderInterface::PLACEHOLDER_KEY])) {
        continue;
      }
      $inner = $entry?->attachment?->child;
      if ($entry?->variants !== NULL) {
        $chosen = $entry->variants->chosen(is_array($value) ? ($value[$entry->variants->by] ?? NULL) : NULL);
        $inner = $chosen === NULL ? NULL : $entry->variants->variant($chosen)->child;
      }
      $element[$name] = $this->collectionsInside($element[$name], $property, $inner, $held, [...$path, $name], $wrapper_id, $whole);
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
      'label' => $element['#title'] ?? NULL,
      'applies' => empty($element[DataSurfaceFormBuilderInterface::PLACEHOLDER_KEY]),
      'required' => !empty($element['#required']) || !empty($element[DataSurfaceFormBuilderInterface::REQUIRED_KEY]) || $marked,
      'min' => isset($element['#min']) ? (string) $element['#min'] : NULL,
      'max' => isset($element['#max']) ? (string) $element['#max'] : NULL,
      'options' => Refreshing::optionsSignature($element),
      'children' => in_array($element['#type'] ?? NULL, Refreshing::GROUPING_TYPES, TRUE) ? static::elementChildren($element) : NULL,
    ];
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
   * A map alone in its group (an attached structure, a map of keys from
   * the site) is drawn as one details: the group's, with the group's label
   * and collapse state, the map's description and required marker moved to
   * it, and the map a plain container inside it, so the page does not show
   * a details titled twice.
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
    $members = [];
    foreach (static::elementChildren($element) as $key) {
      $element[$key] = static::withGroups($element[$key]);
      $group = $element[$key][DataSurfaceFormBuilderInterface::GROUP_KEY] ?? NULL;
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
        $drawn = $group['as'] === ArrangementGroup::DETAILS
          ? ['#type' => 'details', '#title' => $group['label'], '#open' => empty($group['collapsed'])]
          : ['#type' => 'fieldset', '#title' => $group['label']];
        if (isset($group['weight'])) {
          $drawn['#weight'] = $group['weight'];
        }
        if ($members[$group['id']] === 1 && is_array($value) && ($value['#type'] ?? NULL) === 'details' && !isset($value[self::LIST_KEY])) {
          // A map alone in its group: one details, the group's.
          $drawn += array_intersect_key($value, array_flip(['#description', '#required']));
          $own = ['#type', '#title', '#open', '#description', '#required'];
          $value = ['#type' => 'container'] + array_diff_key($value, array_flip($own));
        }
        $framed[DataSurfaceFormBuilderInterface::GROUP_PREFIX . $group['id']] = $drawn;
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
      $group = $element[$key][DataSurfaceFormBuilderInterface::GROUP_KEY] ?? NULL;
      $into = is_array($group) && is_string($group['id'] ?? NULL) ? DataSurfaceFormBuilderInterface::GROUP_PREFIX . $group['id'] : NULL;
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
  public static function placeMounts(array $element): array {
    foreach (static::elementChildren($element) as $group) {
      foreach (static::elementChildren($element[$group]) as $mount) {
        $after = $element[$group][$mount][DataSurfaceFormBuilderInterface::PLACED_AFTER_KEY] ?? NULL;
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
        if (static::elementChildren($element[$group]) === [] && !isset($element[$group][DataSurfaceFormBuilderInterface::REFRESH_ID_KEY])) {
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
      if (str_starts_with((string) $child, DataSurfaceFormBuilderInterface::GROUP_PREFIX) && isset($element[$child][$key]) && is_array($element[$child][$key])) {
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
      if (empty($element[$key][DataSurfaceFormBuilderInterface::ROWS_KEY])) {
        static::wireRows($element[$key], $at, $wrapper_id);
        continue;
      }
      $dotted = implode('.', $at);
      $element[$key][Refreshing::REFRESH_KEY] = $dotted;
      foreach (static::elementChildren($element[$key]) as $control) {
        if (!isset($element[$key][$control][DataSurfaceFormBuilderInterface::ROW_CONTROL_KEY])) {
          continue;
        }
        $named = array_map([Html::class, 'getId'], [...$at, (string) $control]);
        $element[$key][$control]['#name'] = 'data_surface_' . implode('_', $named);
        $element[$key][$control]['#ajax'] = [
          'callback' => [DataSurfaceFormBuilder::class, 'refreshSurface'],
          'wrapper' => $wrapper_id,
          'submit' => [DataSurfaceFormBuilderInterface::WRAPPER_INPUT => $wrapper_id],
        ];
        $element[$key][$control][DataSurfaceFormBuilderInterface::TRIGGER_KEY] = [
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
    $posted = $state->getUserInput()[DataSurfaceFormBuilderInterface::WRAPPER_INPUT] ?? NULL;
    $generated = Html::getId($wrapper_key . '-wrapper');
    return is_string($posted)
      && preg_match('/^[A-Za-z0-9_-]+$/', $posted)
      && ($posted === $generated || str_starts_with($posted, $generated . '--'))
      ? $posted
      : NULL;
  }

  /**
   * Answers whether a child holds variants below a key of its own.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $child
   *   The child.
   *
   * @return bool
   *   TRUE when a key of it, or of a child attached inside it, holds
   *   variants.
   */
  public static function nestsVariants(DataSurfaceInterface $child): bool {
    foreach ($child->getDefinitions()->entries() as $entry) {
      if ($entry->variants !== NULL || ($entry->attachment !== NULL && static::nestsVariants($entry->attachment->child))) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Gives each variant inside a child what it is drawn from.
   *
   * What the variant holds when it fits the variant its deciding key
   * chose, and that variant's defaults when it does not (chosen afresh, or
   * written for another), at every depth, as the top of a form does.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The child.
   * @param mixed $value
   *   What it holds.
   *
   * @return mixed
   *   The same, each variant inside it drawn from what fits it.
   */
  protected static function nestedHeld(DataSurfaceInterface $surface, mixed $value): mixed {
    if (!is_array($value)) {
      return $value;
    }
    foreach ($surface->getDefinitions()->entries() as $name => $entry) {
      $held = $value[$name] ?? NULL;
      if ($entry->variants !== NULL) {
        $chosen = $entry->variants->chosen($value[$entry->variants->by] ?? NULL);
        if ($chosen !== NULL) {
          $held = $entry->variants->held($chosen, $held) ?? $entry->variants->defaultsOf($chosen);
          $value[$name] = static::nestedHeld($entry->variants->variant($chosen)->child, $held);
        }
      }
      elseif ($entry->attachment !== NULL && is_array($held)) {
        $value[$name] = static::nestedHeld($entry->attachment->child, $held);
      }
    }
    return $value;
  }

  /**
   * Marks a variant key's element with the variant it was drawn for.
   *
   * At every depth: each variant key inside the chosen variant, or inside
   * a child attached in it, is marked with what its own deciding key held
   * when it was drawn. A rebuild draws the element again, so the mark
   * always says what the values posted under it were drawn for.
   *
   * @param array $element
   *   The variant key's element.
   * @param string $by
   *   Its deciding key.
   * @param string $chosen
   *   The variant drawn.
   * @param \Drupal\data_surface\DataSurfaceInterface $child
   *   That variant.
   * @param mixed $value
   *   What it was drawn from.
   *
   * @return array
   *   The element, marked.
   */
  protected static function drawnFor(array $element, string $by, string $chosen, DataSurfaceInterface $child, mixed $value): array {
    $element[DataSurfaceFormBuilderInterface::DRAWN_FOR_KEY] = ['by' => $by, 'for' => $chosen];
    return static::drawnForInside($element, $child, $value);
  }

  /**
   * Marks each variant key inside a frame's element.
   *
   * @param array $element
   *   The frame's element.
   * @param \Drupal\data_surface\DataSurfaceInterface $frame
   *   The frame: a variant, or a child attached in one.
   * @param mixed $value
   *   What it was drawn from.
   *
   * @return array
   *   The element, each variant key inside it marked.
   */
  protected static function drawnForInside(array $element, DataSurfaceInterface $frame, mixed $value): array {
    foreach ($frame->getDefinitions()->entries() as $name => $entry) {
      $name = (string) $name;
      if (!is_array($element[$name] ?? NULL) || !empty($element[$name][DataSurfaceFormBuilderInterface::PLACEHOLDER_KEY])) {
        continue;
      }
      $held = is_array($value) ? ($value[$name] ?? NULL) : NULL;
      if ($entry->variants !== NULL) {
        $chosen = $entry->variants->chosen(is_array($value) ? ($value[$entry->variants->by] ?? NULL) : NULL);
        if ($chosen !== NULL) {
          $element[$name] = static::drawnFor($element[$name], $entry->variants->by, $chosen, $entry->variants->variant($chosen)->child, $held);
        }
      }
      elseif ($entry->attachment !== NULL) {
        $element[$name] = static::drawnForInside($element[$name], $entry->attachment->child, $held);
      }
    }
    return $element;
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
