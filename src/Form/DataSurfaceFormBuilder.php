<?php

declare(strict_types=1);

namespace Drupal\data_surface\Form;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\AppendCommand;
use Drupal\Core\Ajax\PrependCommand;
use Drupal\Core\Ajax\RemoveCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\ElementInfoManagerInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Options\DataSurfaceOptions;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;
use Drupal\data_surface\Pipeline\ValueState;
use Drupal\data_surface\Pipeline\ViolationSet;
use Drupal\data_surface\Widget\DataSurfaceWidgetBase;
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
   * Render key marking a slot rendered as nothing but its wrapper.
   */
  protected const PLACEHOLDER_KEY = '#data_surface_placeholder';

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
    // Two views of one overlay. The surface is refined against the keys
    // as they stand, an orphan held unanswered; each element is rendered
    // with what it holds, an orphan standing for its stored value, so its
    // select comes up on the empty option with that value behind it.
    $shown = $values;
    foreach ($values[self::STANDING_KEY] ?? [] as $dotted => $standing) {
      NestedArray::setValue($shown, explode('.', (string) $dotted), $standing, TRUE);
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
    $definitions = $surface->getDefinitions();
    foreach ($definitions as $name => $definition) {
      $slot = $definitions->entry($name)?->slot;
      if ($slot !== NULL && DefinitionMetadata::slotOf($definition) !== NULL) {
        // The values said nothing about the deciding key, so its own
        // default is what the person sees chosen, and the slot is that
        // variant's.
        $chosen = $slot->chosen($values[$slot->by] ?? $surface->getDefault($slot->by));
        if ($chosen === NULL) {
          // A slot whose deciding key chose nothing has no shape to
          // render. Offering nothing is honest: a key with no element
          // has said nothing, so what it holds is kept, and the slot
          // appears the moment its deciding key is answered, through the
          // rebuild the deciding key is wired to. Nothing but its
          // wrapper is rendered, so that rebuild has a place to put it.
          $container[$name] = ['#markup' => '', self::PLACEHOLDER_KEY => TRUE];
          continue;
        }
        $child = $slot->variant($chosen)->child;
        $held = $values[$name] ?? NULL;
        $definition = $slot->definitionFor($chosen, $child->refine(array_replace(
          $child->getDefaultValues(),
          $slot->fits($chosen, $held) ? $held : [],
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
        $definitions->isLocked($name) => $surface->getDefault($name),
        default => $shown[$name] ?? $surface->getDefault($name),
      };
      if ($slot !== NULL) {
        // Rendered for the variant now chosen, from that variant's
        // defaults when what is held was written for another one.
        $chosen = (string) $slot->chosen($values[$slot->by] ?? $surface->getDefault($slot->by));
        $value = $slot->fits($chosen, $value) ? $value : $slot->defaultsOf($chosen);
      }
      $element = $this->widgetManager->getWidgetFor($definition)->buildElement($definition, $value);
      if ($definitions->isLocked($name)) {
        // Visible but fixed: the consumer sees the key and its value and
        // cannot change it — the form-side spelling of const + readOnly.
        $element['#disabled'] = TRUE;
        $element = static::describeLocked($element);
      }
      $container[$name] = $element;
    }
    $container = $this->wireRefinement($container, $surface, $values, [], $wrapper_id);
    $stale = static::stalePaths($container);
    if ($stale !== []) {
      // Fixed to this build: what the person is looking at now, not what
      // the previous build posted.
      $container[self::STALE_MARKER_KEY] = [
        '#type' => 'hidden',
        '#value' => implode(' ', $stale),
      ];
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
    foreach (array_keys($orphaned) as $dotted) {
      NestedArray::setValue($values, explode('.', (string) $dotted), NULL, TRUE);
    }
    if ($orphaned !== []) {
      $values[self::STANDING_KEY] = $orphaned;
    }
    return $values;
  }

  /**
   * Settles a rebuild's input: what it drops, and what it leaves orphaned.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface or child surface, as advertised.
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
      if (!$entry->isNested() || in_array($name, $discarded, TRUE) || !is_array($input[$name] ?? NULL)) {
        continue;
      }
      $child = $entry->childFor($values);
      if ($child === NULL) {
        continue;
      }
      $held = $stored[$name] ?? NULL;
      if ($entry->slot !== NULL && !$entry->slot->fits((string) $entry->slot->chosen($values[$entry->slot->by] ?? NULL), $held)) {
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
   *   The surface or child surface, as advertised.
   * @param array $stored
   *   What is stored for its keys.
   * @param array $input
   *   The in-progress input for its keys.
   *
   * @return array{0: string[], 1: array<string, mixed>}
   *   The keys whose input is dropped, in the order they were found; and
   *   the keys held unanswered, each mapped to the stored value it
   *   stands for.
   */
  protected function settleFrame(DataSurfaceInterface $surface, array $stored, array $input): array {
    $refinements = $surface->getDefinitions()->refinements();
    if ($refinements === []) {
      return [[], []];
    }
    $targets = array_intersect_key($refinements, $input);
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
      if ($again) {
        // What a drop falls back to is only known once the surface is
        // refined against it.
        continue;
      }
      foreach ($refinements as $name => $dependencies) {
        $name = (string) $name;
        // Only a key standing on what is stored: input that is still
        // there was just judged to stand.
        if (isset($orphaned[$name]) || (array_key_exists($name, $input) && !isset($discarded[$name]))) {
          continue;
        }
        if ($this->isOrphaned($refined, $name, $dependencies, $values, $stored)) {
          $orphaned[$name] = $values[$name];
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
   *   The keys whose input is dropped so far.
   * @param array<string, mixed> $orphaned
   *   The keys held unanswered so far.
   *
   * @return array
   *   Stored underneath, the input that still stands on top, nothing at
   *   each orphaned key.
   */
  protected static function frameValues(array $stored, array $input, array $discarded, array $orphaned): array {
    return array_replace(
      $stored,
      array_diff_key($input, array_flip($discarded)),
      array_fill_keys(array_map('strval', array_keys($orphaned)), NULL),
    );
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
   *   The surface refined against the values as they now stand.
   * @param string $name
   *   The target key.
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
    $value = $values[$name] ?? NULL;
    if ((!is_int($value) && !is_string($value)) || !ValueState::isConfigured($value)) {
      return FALSE;
    }
    $moved = FALSE;
    foreach ($dependencies as $dependency) {
      $moved = $moved || !static::sameAnswer($values[$dependency] ?? NULL, $stored[$dependency] ?? NULL);
    }
    if (!$moved) {
      return FALSE;
    }
    $definition = $refined->getDefinition($name);
    if ($definition === NULL || $definition instanceof ListDataDefinitionInterface || $refined->getDefinitions()->entry($name)?->slot !== NULL) {
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
    $slot = $refined->getDefinitions()->entry($name)?->slot;
    if ($slot !== NULL) {
      // A slot's input stands while it is shaped for the variant now
      // chosen. Input left by another variant answered a question that
      // is no longer on the form, so it goes, and the rebuilt slot
      // starts from the chosen variant's own values.
      $chosen = $slot->chosen($values[$slot->by] ?? NULL);
      return $chosen === NULL || $slot->fits($chosen, $value);
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
   * when a surface is sealed and no refinement changes them, so the
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
    $elements = [];
    foreach ([...$replaces, ...($container[self::REFRESHED_KEY] ?? [])] as $dotted) {
      if ($dotted === $own) {
        // Never the element that was touched: it already shows what the
        // person chose, and redrawing it is what made the change look
        // like a reload of the field rather than of what it changed.
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
      $elements[$element[self::REFRESH_ID_KEY]] = $element;
    }
    $response = new AjaxResponse();
    foreach ($elements as $id => $element) {
      $response->addCommand(new ReplaceCommand('#' . $id, $element));
    }
    $wrapper_id = (string) $container[self::WRAPPER_KEY];
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
    // printed in the same place: an error on the trigger itself — the
    // one value a refinement request judges — would otherwise be held
    // over to the next page. The previous request's are taken away
    // first, as replacing the container used to.
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
   * Finds the surface container around an element of a built form.
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
    //
    // At any depth: an attached child's own dependency sits inside the
    // child's details, and is as much a trigger as a top level key.
    static::limitAjax($element, $element['#parents'] ?? [], !empty($element['#tree']));
    // The wrappers the AJAX callback replaces by. Placed here rather than
    // at build time because the container's id is only final once a host
    // has merged it into its own element, and every wrapper id is derived
    // from it.
    $wrapper_id = $element[self::WRAPPER_KEY] ?? NULL;
    if (is_string($wrapper_id)) {
      static::wrapRefreshed($element, $wrapper_id);
      if (isset($element[self::STALE_MARKER_KEY]) && !isset($element[self::STALE_MARKER_KEY][self::REFRESH_ID_KEY])) {
        $element[self::STALE_MARKER_KEY] = static::wrapped($element[self::STALE_MARKER_KEY], static::reservedId($wrapper_id, 'stale'));
      }
    }
    return $element;
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
   * of a refreshId(), so no surface key can collide with it.
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
    $definitions = $surface->getDefinitions();
    $slots = [];
    foreach ($definitions->entries() as $name => $entry) {
      if ($entry->slot !== NULL) {
        $slots[] = $name;
        continue;
      }
      $raw += $this->extractKey((string) $name, $entry->definition, $container, $form_state);
    }
    if ($slots !== []) {
      // A slot's element is the chosen variant's, so it is read through
      // that variant's shape, chosen by what the deciding key holds in
      // this same submission.
      $resolved = $definitions->withSlotsResolved($raw + $current + $surface->getDefaultValues());
      foreach ($slots as $name) {
        $definition = $resolved->get((string) $name);
        if ($definition !== NULL && DefinitionMetadata::slotOf($definition) === NULL) {
          $raw += $this->extractKey((string) $name, $definition, $container, $form_state);
        }
      }
    }
    // One coercion path for every caller: the form hands its raw tree to
    // the same accept() a payload goes through, which is also where
    // locked keys take the value the surface declares.
    return $this->pipeline->accept($surface, $raw, $current);
  }

  /**
   * Reads one key's raw value out of the container, if it was rendered.
   *
   * @param string $name
   *   The surface key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $definition
   *   The definition to read it through.
   * @param array $container
   *   The surface container.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The raw value keyed by surface key, or nothing for a key with no
   *   element: it was never offered, so it has nothing to say, and
   *   accept() keeps whatever the key already holds.
   */
  protected function extractKey(string $name, ?DataDefinitionInterface $definition, array $container, FormStateInterface $form_state): array {
    if ($definition === NULL || !isset($container[$name]) || !is_array($container[$name]) || !empty($container[$name][self::PLACEHOLDER_KEY])) {
      // A placeholder is a slot's wrapper and nothing else: no element
      // was offered, so the key has said nothing.
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
    $element[self::TRIGGER_KEY] = ['path' => $path, 'replaces' => $replaces];
    return $element;
  }

  /**
   * Marks what a rebuild may replace and wires what triggers one.
   *
   * One frame at a time, the surface's own and then each child's, under
   * the names each frame gives its keys: a child's refiners run in the
   * child's frame, so its dependencies and its targets are the child's
   * keys, and what one of them moves is inside that child and nowhere
   * else.
   *
   * @param array $element
   *   The container, or the element an attached child or a slot variant
   *   is rendered as.
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface or child surface the element renders.
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
    $refinements = $definitions->refinements();
    foreach ($definitions->entries() as $name => $entry) {
      $name = (string) $name;
      if (!isset($element[$name]) || !is_array($element[$name])) {
        continue;
      }
      $at = [...$path, $name];
      if (isset($refinements[$name])) {
        // A target — a slot is one too, of its deciding key — has a
        // wrapper of its own, which is what the rebuild replaces.
        $element[$name][self::REFRESH_KEY] = implode('.', $at);
      }
      if (!$entry->isNested() || !empty($element[$name][self::PLACEHOLDER_KEY])) {
        continue;
      }
      $child = $entry->attachment?->child;
      $held = $values[$name] ?? NULL;
      if ($entry->slot !== NULL) {
        // The variant the slot was rendered as, from what it was rendered
        // with: its own defaults when what is held was another's.
        $chosen = $entry->slot->chosen($values[$entry->slot->by] ?? $surface->getDefault($entry->slot->by));
        $child = $chosen === NULL ? NULL : $entry->slot->variant($chosen)->child;
        $held = $chosen !== NULL && $entry->slot->fits($chosen, $held) ? $held : NULL;
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
      if (!isset($element[$dependency]) || !is_array($element[$dependency])) {
        continue;
      }
      $element[$dependency] = $this->attachRefinementAjax(
        $element[$dependency],
        $wrapper_id,
        [...$path, $dependency],
        array_map(
          static fn (string $target): string => implode('.', [...$path, $target]),
          static::dependentsOf($dependency, $refinements),
        ),
      );
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
   *   The frame's refinement map: target => the keys it refines against.
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
    $note = t('Fixed for this operation.');
    $element['#description'] = isset($element['#description'])
      ? t('@description @note', [
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
