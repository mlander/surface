<?php

declare(strict_types=1);

namespace Drupal\data_surface\Form;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\Computed;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Options\DataSurfaceOptions;
use Drupal\data_surface\Pipeline\Accepting;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;
use Drupal\data_surface\Pipeline\TypeMismatchException;
use Drupal\data_surface\Pipeline\ValueState;
use Drupal\data_surface\Pipeline\ViolationSet;
use Drupal\data_surface\Entry;
use Drupal\data_surface\Variants;
use Drupal\data_surface\Collection;
use Drupal\data_surface\Widget\DataSurfaceWidgetManager;

/**
 * Reads what a form posted back into the data surface's shape.
 *
 * Extraction reads each key's element through its widget, an attached
 * child and a variant a level at a time and a collection item by item,
 * and hands the raw tree to the pipeline's accept()
 * (extractSurfaceValues()). Validation puts each violation on the element
 * it belongs to (flagSurfaceErrors()); one on the trigger of a refinement
 * request is held for the request and printed under the trigger
 * (withTriggerErrors()). On a refinement request, the overlay reads the
 * in-progress input against what is stored: which answers still stand,
 * which are dropped, and which keys are held unanswered
 * (refinementOverlay(), settle()).
 *
 * Decision: see
 * docs/decisions.md#the-form-builder-draws-extracts-and-refreshes.
 *
 * @internal
 *
 * @see \Drupal\data_surface\Form\DataSurfaceFormBuilder
 */
final class Extracting {

  /**
   * Where a refinement request holds the violations on its trigger.
   *
   * Temporary form state, so it lives for the one request: written by
   * flagSurfaceErrors(), read by refreshSurface(), keyed by the
   * container's place in the form and the trigger's dotted path.
   */
  protected const TRIGGER_ERRORS = 'data_surface_trigger_errors';

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
   * Constructs the reading of what a form posted.
   *
   * @param \Drupal\data_surface\Widget\DataSurfaceWidgetManager $widgetManager
   *   The widget manager, whose widgets read each element's value.
   * @param \Drupal\data_surface\Pipeline\DataSurfacePipelineInterface $pipeline
   *   The pipeline, which accepts and validates what the form collects.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger, which says that a stored value went stale and is
   *   being kept.
   * @param \Drupal\data_surface\Options\DataSurfaceOptions $options
   *   The options service, which answers whether the refined definition
   *   still offers the value an in-progress edit left behind.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler, which says whether Inline Form Errors prints
   *   an error beside its element, so the message need not name it.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service, which words a value of the wrong
   *   shape refused.
   */
  public function __construct(
    protected readonly DataSurfaceWidgetManager $widgetManager,
    protected readonly DataSurfacePipelineInterface $pipeline,
    protected readonly MessengerInterface $messenger,
    protected readonly DataSurfaceOptions $options,
    protected readonly ModuleHandlerInterface $moduleHandler,
    protected readonly TranslationInterface $stringTranslation,
  ) {
  }

  /**
   * Names the in-progress input a rebuild has just invalidated.
   *
   * @see \Drupal\data_surface\Form\DataSurfaceFormBuilderInterface::discardedRefinementInput()
   */
  public function discardedRefinementInput(DataSurfaceInterface $surface, array $stored, array $input): array {
    return $this->settle($surface, $stored, $input)[0];
  }

  /**
   * Builds the values a refinement rebuild's container is built from.
   *
   * @see \Drupal\data_surface\Form\DataSurfaceFormBuilderInterface::refinementOverlay()
   */
  public function refinementOverlay(DataSurfaceInterface $surface, array $stored, array $input): array {
    [$discarded, $orphaned] = $this->settle($surface, $stored, $input);
    foreach ($discarded as $dotted) {
      NestedArray::unsetValue($input, explode('.', $dotted));
    }
    $values = static::overlaid($surface, $stored, $input);
    foreach ($discarded as $dotted) {
      // A mounted key falls back to what is stored, as a whole key does;
      // a child's key, inside its own frame, to the child's defaults.
      $parents = explode('.', $dotted);
      $entry = $surface->getDefinitions()->entry($parents[0]);
      if (count($parents) > 1 && $entry !== NULL && !$entry->isNested() && $entry->list === NULL) {
        static::restore($values, $stored, $parents);
      }
      elseif (count($parents) > 1 && $entry?->attachment !== NULL) {
        NestedArray::unsetValue($values, $parents);
      }
    }
    foreach (array_keys($orphaned) as $dotted) {
      NestedArray::setValue($values, explode('.', (string) $dotted), NULL, TRUE);
    }
    // A computed key shows what a write of these values would store.
    // Decision: see
    // docs/decisions.md#a-computed-key-is-a-pure-function-of-its-sources.
    $values = Computed::apply($surface, $values, $stored);
    if ($orphaned !== []) {
      $values[DataSurfaceFormBuilderInterface::STANDING_KEY] = $orphaned;
    }
    return $values;
  }

  /**
   * Lays input over what is stored, into each attached child too.
   *
   * A key the input leaves out keeps what is stored, inside an attached
   * child as at the top: a key that did not apply, so the page sent
   * nothing for it, holds its stored value again once it applies.
   *
   * Decision: see docs/decisions.md#a-key-that-does-not-apply.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface or child, as advertised.
   * @param array $stored
   *   What is stored for its keys.
   * @param array $input
   *   The input for them.
   *
   * @return array
   *   The input over what is stored.
   */
  protected static function overlaid(DataSurfaceInterface $surface, array $stored, array $input): array {
    $values = array_replace($stored, $input);
    foreach ($input as $name => $held) {
      $child = $surface->getDefinitions()->entry((string) $name)?->attachment?->child;
      if ($child !== NULL && is_array($held) && is_array($stored[$name] ?? NULL)) {
        $values[$name] = static::overlaid($child, $stored[$name], $held);
      }
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
      if ($entry->variants !== NULL) {
        // What is stored was written for another variant, and says
        // nothing about this one.
        $held = $entry->variants->held((string) $entry->variants->chosen($values[$entry->variants->by] ?? NULL), $held);
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
   * Extracts data surface values from a built container via the widgets.
   *
   * @see \Drupal\data_surface\Form\DataSurfaceFormBuilderInterface::extractSurfaceValues()
   */
  public function extractSurfaceValues(DataSurfaceInterface $surface, array $container, FormStateInterface $form_state, array $current = []): array {
    $container = DataSurfaceFormBuilder::findSurfaceContainer($container);
    $raw = [];
    $definitions = $surface->getDefinitions();
    $variantKeys = [];
    foreach ($definitions->entries() as $name => $entry) {
      if (!$entry->locked && Computed::of($entry->definition)?->takesSent() === FALSE) {
        // Drawn as an item, which posts nothing: accept() computes it. One
        // computed unless sent is drawn as its widget, and read back as
        // any key: left empty, accept() computes it.
        continue;
      }
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
        if ($definition === NULL || DefinitionMetadata::variantsBy($definition) !== NULL) {
          continue;
        }
        $variants = $definitions->entry((string) $name)->variants;
        $chosen = $variants->chosen($raw[$variants->by] ?? $current[$variants->by] ?? $surface->getDefault($variants->by));
        if (static::drawnForAnother($container[$name] ?? NULL, $chosen)) {
          // Drawn for the variant the deciding key chose before this
          // submission: what it posted is that variant's, and says nothing.
          continue;
        }
        $child = $chosen === NULL ? NULL : $variants->variant($chosen)->child;
        if ($child !== NULL && Drawing::nestsVariants($child) && is_array($container[$name] ?? NULL) && empty($container[$name][DataSurfaceFormBuilderInterface::PLACEHOLDER_KEY])) {
          // Variants inside the variant (a component's slots, each holding
          // a component that chooses its own props): each level is read
          // through what its own deciding key chose in this submission.
          $raw[$name] = $this->extractFrame($child, $container[$name], $form_state);
          continue;
        }
        $raw += $this->extractKey((string) $name, $definition, $container, $form_state);
      }
    }
    // A key drawn as nothing when the page was drawn sent nothing, even
    // where this build draws it: left out, it keeps what is stored.
    // Decision: see docs/decisions.md#a-key-that-does-not-apply.
    foreach (DataSurfaceFormBuilder::markedAbsent($form_state->getUserInput(), $container['#parents'] ?? []) as $dotted) {
      NestedArray::unsetValue($raw, explode('.', $dotted));
    }
    // One coercion path for every caller: the form hands its raw tree to
    // the same accept() a payload goes through, which is also where
    // locked keys take the value the data surface declares.
    return $this->accepted($surface, $raw, $current, $container, $form_state);
  }

  /**
   * Accepts what a form posted, a value of the wrong shape refused.
   *
   * The pipeline's accept() throws on a value its key cannot hold at all,
   * an array for a string; through a payload that is a refusal, and
   * through a form it is one too: an error on the element, the value left
   * out and the rest accepted, never an exception.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface.
   * @param array $raw
   *   What the form posted, by key.
   * @param array $current
   *   What is stored.
   * @param array $container
   *   The data surface container.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The accepted values.
   *
   * @throws \Drupal\data_surface\Pipeline\TypeMismatchException
   *   When the value refused is not one the form posted.
   */
  protected function accepted(DataSurfaceInterface $surface, array $raw, array $current, array $container, FormStateInterface $form_state): array {
    $refused = [];
    while (TRUE) {
      try {
        $values = $this->pipeline->accept($surface, $raw, $current);
        break;
      }
      catch (TypeMismatchException $e) {
        // Left out where it was posted; a list's items are counted again
        // by accept(), so a path into one leaves out the whole list.
        $parents = explode('.', $e->getPath());
        while (count($parents) > 1 && !NestedArray::keyExists($raw, $parents)) {
          array_pop($parents);
        }
        if (!NestedArray::keyExists($raw, $parents)) {
          throw $e;
        }
        NestedArray::unsetValue($raw, $parents);
        $refused[] = Accepting::typeMismatchViolation($e, $this->stringTranslation);
      }
    }
    $state = $form_state instanceof SubformStateInterface ? $form_state->getCompleteFormState() : $form_state;
    if ($refused !== [] && !$state->isValidationComplete()) {
      $this->flagSurfaceErrors(new ViolationSet($refused), $container, $form_state);
    }
    return $values;
  }

  /**
   * Starts afresh each variant whose deciding key moved since it was drawn.
   *
   * Read from the form as drawn: each variant key's element says the
   * variant it was drawn for (DataSurfaceFormBuilderInterface::DRAWN_FOR_KEY).
   * Where the deciding key's input now chooses another, the input under
   * the key was drawn for the variant no longer chosen, and is replaced by
   * what the chosen one starts from, as a reload would draw it: what is
   * stored, when what is stored was written for it, and its defaults
   * otherwise. Keys of the same variant at another depth go with it.
   *
   * Decision: see
   * docs/decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface, as advertised.
   * @param array|null $form
   *   The form as it was drawn, or NULL when there is none.
   * @param string[] $path
   *   The container's input path.
   * @param array $input
   *   The in-progress input, by key.
   * @param array $stored
   *   What is stored, by key.
   *
   * @return array{0: string[], 1: array}
   *   The dotted paths whose posted input is dropped, and the input with
   *   what each starts from in its place.
   */
  public static function switchedVariants(DataSurfaceInterface $surface, ?array $form, array $path, array $input, array $stored): array {
    $switched = [];
    foreach (static::drawnVariants($form ?? [], $path) as [$parents, $drawn]) {
      foreach ($switched as $done) {
        if (array_slice($parents, 0, count($done)) === $done) {
          continue 2;
        }
      }
      $by = [...array_slice($parents, 0, -1), (string) ($drawn['by'] ?? '')];
      $exists = FALSE;
      $now = NestedArray::getValue($input, $by, $exists);
      if (!$exists || !is_scalar($now) || (string) $now === (string) ($drawn['for'] ?? '')) {
        continue;
      }
      $switched[] = $parents;
      $variants = static::variantsAt($surface, $parents, $input, $stored);
      $chosen = $variants?->chosen($now);
      if ($chosen === NULL) {
        NestedArray::unsetValue($input, $parents);
        continue;
      }
      $was = NestedArray::getValue($stored, $by);
      $held = is_scalar($was) && (string) $was === (string) $now ? $variants->held($chosen, NestedArray::getValue($stored, $parents)) : NULL;
      NestedArray::setValue($input, $parents, $held ?? $variants->defaultsOf($chosen), TRUE);
    }
    return [array_map(static fn (array $parents): string => implode('.', $parents), $switched), $input];
  }

  /**
   * Finds the variant keys drawn under a container, and what each was for.
   *
   * @param array $element
   *   The form as drawn, or an element in it.
   * @param string[] $path
   *   The container's input path.
   *
   * @return list<array{0: string[], 1: array}>
   *   Each one's input path below the container, and its mark, the
   *   shallowest first.
   */
  protected static function drawnVariants(array $element, array $path): array {
    $found = [];
    $parents = $element['#parents'] ?? NULL;
    if (isset($element[DataSurfaceFormBuilderInterface::DRAWN_FOR_KEY]) && is_array($parents) && count($parents) > count($path) && array_slice($parents, 0, count($path)) === $path) {
      $found[] = [array_slice($parents, count($path)), $element[DataSurfaceFormBuilderInterface::DRAWN_FOR_KEY]];
    }
    foreach ($element as $key => $child) {
      if (is_array($child) && !str_starts_with((string) $key, '#')) {
        array_push($found, ...static::drawnVariants($child, $path));
      }
    }
    return $found;
  }

  /**
   * Finds the variants at a path, each level read through what it chose.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface, as advertised.
   * @param string[] $parents
   *   The path of the key with variants.
   * @param array $input
   *   The in-progress input, by key.
   * @param array $stored
   *   What is stored, by key.
   *
   * @return \Drupal\data_surface\Variants|null
   *   The variants, or NULL when nothing holds variants there.
   */
  protected static function variantsAt(DataSurfaceInterface $surface, array $parents, array $input, array $stored): ?Variants {
    $frame = $surface;
    foreach ($parents as $position => $name) {
      $entry = $frame->getDefinitions()->entry((string) $name);
      if ($position === count($parents) - 1 || $entry === NULL) {
        return $entry?->variants;
      }
      if ($entry->variants !== NULL) {
        $by = [...array_slice($parents, 0, $position), $entry->variants->by];
        $exists = FALSE;
        $value = NestedArray::getValue($input, $by, $exists);
        $chosen = $entry->variants->chosen($exists ? $value : NestedArray::getValue($stored, $by));
        if ($chosen === NULL) {
          return NULL;
        }
        $frame = $entry->variants->variant($chosen)->child;
      }
      elseif ($entry->attachment !== NULL) {
        $frame = $entry->attachment->child;
      }
      else {
        return NULL;
      }
    }
    return NULL;
  }

  /**
   * Answers whether a variant key's element was drawn for another variant.
   *
   * @param mixed $element
   *   The element.
   * @param string|null $chosen
   *   The variant its deciding key chose in this submission.
   *
   * @return bool
   *   TRUE when the element says it was drawn for another.
   */
  protected static function drawnForAnother(mixed $element, ?string $chosen): bool {
    $drawn = is_array($element) ? ($element[DataSurfaceFormBuilderInterface::DRAWN_FOR_KEY]['for'] ?? NULL) : NULL;
    return $drawn !== NULL && $drawn !== $chosen;
  }

  /**
   * Reads a child's keys out of its element, a level at a time.
   *
   * A key is read through its widget; an attached child through its own
   * keys; variants through the variant their deciding key chose in what
   * was just read, so an element drawn for another variant gives nothing
   * of its own. A collection below the top is not drawn, so nothing is
   * read for it, and what it holds is kept.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The child.
   * @param array $element
   *   Its element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The raw values read, by key.
   */
  protected function extractFrame(DataSurfaceInterface $surface, array $element, FormStateInterface $form_state): array {
    $raw = [];
    foreach ($surface->getDefinitions()->entries() as $name => $entry) {
      $name = (string) $name;
      $at = $element[$name] ?? NULL;
      if (!is_array($at) || !empty($at[DataSurfaceFormBuilderInterface::PLACEHOLDER_KEY]) || $entry->list !== NULL) {
        continue;
      }
      if ($entry->variants !== NULL) {
        $chosen = $entry->variants->chosen($raw[$entry->variants->by] ?? $surface->getDefault($entry->variants->by));
        if ($chosen !== NULL && !static::drawnForAnother($at, $chosen)) {
          $raw[$name] = $this->extractFrame($entry->variants->variant($chosen)->child, $at, $form_state);
        }
        continue;
      }
      if ($entry->attachment !== NULL) {
        $raw[$name] = $this->extractFrame($entry->attachment->child, $at, $form_state);
        continue;
      }
      $raw += $this->extractKey($name, $entry->definition, $element, $form_state);
    }
    return $raw;
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
    $container_key ??= Refreshing::containerKey($container);
    $name = $entry->name;
    $element = $container[$name] ?? NULL;
    $item = $entry->definition instanceof ListDataDefinitionInterface ? $entry->definition->getItemDefinition() : NULL;
    if (!is_array($element) || !isset($element[Drawing::LIST_KEY]) || $item === NULL) {
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
    // An item holding variants (a placed block's visibility conditions,
    // each one's settings chosen by its plugin id) is read a level at a
    // time, each variant through what its deciding key chose in the item,
    // as the top of the form reads its own: the item's plain map knows
    // none of the variants' keys.
    $frame = $child !== NULL && Drawing::nestsVariants($child);
    $items = [];
    $deltas = [];
    foreach (Drawing::elementChildren($element) as $delta) {
      if (!is_int($delta)) {
        continue;
      }
      $value = $frame
        ? $this->extractFrame($child, $element[$delta], $form_state)
        : $widget->extractValue($map, $element[$delta], $form_state, [$name, $delta]);
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
    $state->setTemporaryValue([self::LIST_DELTAS, $container_key, $element[Drawing::LIST_KEY]], $deltas);
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
    if ($definition === NULL || !isset($container[$name]) || !is_array($container[$name]) || !empty($container[$name][DataSurfaceFormBuilderInterface::PLACEHOLDER_KEY])) {
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
   * Validates extracted values against the refined data surface.
   *
   * @see \Drupal\data_surface\Form\DataSurfaceFormBuilderInterface::validateSurfaceForm()
   */
  public function validateSurfaceForm(DataSurfaceInterface $surface, array $values, array $container, FormStateInterface $form_state, array $current = []): bool {
    $errors = $this->pipeline->validate($surface, $values, $current);
    $this->flagSurfaceErrors($errors, $container, $form_state);
    return $errors->isEmpty();
  }

  /**
   * Attaches data surface violations to the exact elements they belong to.
   *
   * A violation inside a nested map carries a property path; without
   * wrapper nesting the walk is direct: one child per path segment.
   *
   * @see \Drupal\data_surface\Form\DataSurfaceFormBuilderInterface::flagSurfaceErrors()
   */
  public function flagSurfaceErrors(ViolationSet $errors, array $container, FormStateInterface $form_state): void {
    $container = DataSurfaceFormBuilder::findSurfaceContainer($container);
    $trigger = Refreshing::triggerPath($container, $form_state);
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
        $held[Refreshing::containerKey($container)][$trigger][] = $violation->message;
        $state->setTemporaryValue(self::TRIGGER_ERRORS, $held);
        continue;
      }
      $element = $container[$violation->key] ?? NULL;
      foreach ($segments as $position => $segment) {
        if (isset($element[Drawing::LIST_KEY]) && ctype_digit((string) $segment)) {
          // An item is flagged where it was drawn, which an empty item
          // dropped before it may have moved: at any depth, a list inside
          // an item too.
          $list = $element[Drawing::LIST_KEY];
          $deltas = $state->getTemporaryValue([self::LIST_DELTAS, Refreshing::containerKey($container), $list]) ?? [];
          $segment = $segments[$position] = (string) ($deltas[(int) $segment] ?? $segment);
        }
        if (!empty($element[DataSurfaceFormBuilderInterface::ROWS_KEY]) && ctype_digit((string) $segment) && isset($element['#array_parents'])) {
          // An item of a free list is flagged on the row it was drawn as,
          // which an empty row dropped before it may have moved.
          $rows = $state->getTemporaryValue([
            DataSurfaceFormBuilderInterface::ROW_DELTAS,
            implode('][', $element['#array_parents']),
          ]) ?? [];
          $segment = $segments[$position] = (string) ($rows[(int) $segment] ?? $segment);
        }
        if (!isset($element[$segment])) {
          break;
        }
        $element = $element[$segment];
      }
      if (is_array($element) && isset($element['#parents'])) {
        $form_state->setError($element, $beside ? $violation->message : Drawing::named($element, $violation->message));
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
  public static function withTriggerErrors(array $element, FormStateInterface $form_state, string $container_key, string $dotted): array {
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
    $element[DataSurfaceFormBuilderInterface::INLINE_ERROR_KEY] = $error_id ?? TRUE;
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
      $element['#attached']['drupalSettings']['ajax'][$element['#id']]['submit'][DataSurfaceFormBuilderInterface::INVALID_INPUT] = '1';
    }
    return $element;
  }

}
