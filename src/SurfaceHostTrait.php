<?php

declare(strict_types=1);

namespace Drupal\surface;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\surface\Form\SurfaceFormBuilder;
use Drupal\surface\Form\SurfaceFormBuilderInterface;
use Drupal\surface\Hook\ResourcePluginHooks;
use Drupal\surface\Pipeline\SurfacePipelineInterface;
use Drupal\surface\Pipeline\ValueState;
use Drupal\surface\Resource\Attribute\UsesStructure;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\ResourcesInterface;

/**
 * The few things every host-side adoption trait needs.
 *
 * Composed into the configuration trait, the plugin form trait and the
 * formatter trait so none of them repeats the other, and so a class that
 * uses two of them at once (an adopting block uses the first two)
 * inherits one copy of each method rather than colliding.
 *
 * Why the services are fetched rather than injected, documented here
 * once because it is the trap that keeps catching adopters: the host
 * base classes call into data surface code from inside their
 * constructors. BlockPluginTrait::__construct() calls setConfiguration(),
 * which asks the structure for its defaults, before any subclass
 * constructor body has run, so a collaborator assigned through
 * constructor promotion or through create() is still unset at the moment
 * it would be needed. A
 * property assigned before parent::__construct() would work, but every
 * adopting plugin would have to know that and write its constructor
 * around it, which is exactly the ceremony this layer exists to delete.
 * Fetching from the container at the point of use is the honest answer
 * for a host whose constructor calls us; it stays a documented exception
 * rather than a habit.
 *
 * The build step is fetched here for the same reason and under the same
 * exception: a base class asked for its structure from inside its own
 * constructor has no injected build step yet.
 *
 * A plugin's configuration is a structure, and every host names it the
 * one way, with #[UsesStructure] on the plugin class. The host supplies
 * the operation and the storage: it builds the structure in a context
 * whose id is the host's own verb (`configure`), since a plugin instance
 * is the whole subject and no declared operation describes it.
 */
trait SurfaceHostTrait {

  /**
   * Gets the data surface pipeline.
   *
   * @return \Drupal\surface\Pipeline\SurfacePipelineInterface
   *   The pipeline.
   */
  protected function surfacePipeline(): SurfacePipelineInterface {
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    return \Drupal::service('surface.pipeline');
  }

  /**
   * Gets the data surface form builder.
   *
   * @return \Drupal\surface\Form\SurfaceFormBuilderInterface
   *   The form builder.
   */
  protected function surfaceFormBuilder(): SurfaceFormBuilderInterface {
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    return \Drupal::service('surface.form_builder');
  }

  /**
   * Gets the build step.
   *
   * Fetched under the documented exception: a block asks for its
   * structure from inside its own constructor.
   *
   * @return \Drupal\surface\ResourceBuild\ResourcesInterface
   *   The build step.
   */
  protected function resources(): ResourcesInterface {
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    return \Drupal::service('surface.resources');
  }

  /**
   * Gets the structure class #[UsesStructure] names on this plugin.
   *
   * Read from the plugin definition, where the plugin type's definition
   * alter copied it, so a plugin constructed with a hand-made definition
   * that carries no such key is taken at its definition's word. For a
   * plugin that is its own structure it is the plugin's class.
   *
   * @return class-string|null
   *   The structure class, or NULL when the definition names none.
   *
   * @see \Drupal\surface\Hook\ResourcePluginHooks
   */
  protected function usedStructure(): ?string {
    if (!$this instanceof PluginInspectionInterface) {
      return NULL;
    }
    $definition = $this->getPluginDefinition();
    $surface = is_array($definition) ? ($definition[UsesStructure::DEFINITION_KEY] ?? NULL) : NULL;
    return is_string($surface) ? $surface : NULL;
  }

  /**
   * Gets the context this host asks for its structure in.
   *
   * A plugin host knows nothing a declared operation would: the instance
   * is the whole subject, and its configuration is loaded by the host, so
   * the context is the host's verb as its id and nothing else.
   *
   * @param string $operation
   *   The host's verb, such as `configure`.
   *
   * @return \Drupal\surface\Resource\OperationContext
   *   The context.
   */
  protected function operationContext(string $operation): OperationContext {
    // Decision: see docs/decisions.md#a-plugin-hosts-context.
    return new OperationContext($operation);
  }

  /**
   * Builds the structure this host's plugin names, in the host's context.
   *
   * @param string $operation
   *   The host's verb, such as `configure`.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The built structure, alters applied.
   *
   * @throws \LogicException
   *   When the plugin names no structure with #[UsesStructure].
   */
  protected function hostedStructure(string $operation = 'configure'): SurfaceInterface {
    $surface = $this->usedStructure() ?? throw new \LogicException(sprintf(
      '%s names no structure: put #[UsesStructure] on the class, naming a #[Structure] class in a module\'s src/Structure, or with no argument on a plugin class that implements DefinesInputsInterface itself.',
      static::class,
    ));
    return $this->resources()->build($surface, $this->operationContext($operation));
  }

  /**
   * Answers whether an account may configure this host's values.
   *
   * The structure's own answer in the host's context: its access class,
   * when it names one, since a host verb is not a declared operation and
   * has no permission tier; neutral when it names none, which leaves the
   * question to whoever asked (a route requirement, an entity access
   * handler, a tool's own check). Forbidden blocks the pipeline's submit
   * before it reads storage; allowed agrees without bypassing the host's
   * own gates.
   *
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account to answer for, or NULL for the current user.
   * @param string $operation
   *   The host's verb the answer is wanted for.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access answer.
   */
  public function surfaceAccess(?AccountInterface $account = NULL, string $operation = 'configure'): AccessResultInterface {
    $surface = $this->usedStructure();
    return $surface === NULL
      ? AccessResult::neutral()
      : $this->resources()->access($surface, $this->operationContext($operation), $this->surfaceAccount($account));
  }

  /**
   * Gets the account an access answer is about.
   *
   * NULL means the current user, everywhere a structure answers for an
   * account, and this is where that is resolved. Fetched from the
   * container under the same documented exception as the services above:
   * a host base class may ask a plugin about access from inside its own
   * construction, and an injected account would still be unset.
   *
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account, or NULL for the current user.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The account to answer for.
   */
  protected function surfaceAccount(?AccountInterface $account = NULL): AccountInterface {
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    return $account ?? \Drupal::currentUser();
  }

  /**
   * Reads the default values the structure a class names declares.
   *
   * Several host protocols ask for their defaults statically (a
   * formatter's defaultSettings(), a field type's defaultFieldSettings()),
   * and a static method cannot consult an instance's structure. The class
   * names its structure statically, with #[UsesStructure], so it can
   * still answer: from that structure's own shape alone, with no alter
   * and no context. The defaults an alter mounts are advertised on the
   * built structure and reach storage under the third party namespace the
   * host protocol already knows about. Kept here rather than in each host's
   * trait so the several static-defaults shims cannot disagree.
   *
   * @param class-string $class
   *   The fully qualified class name.
   *
   * @return array
   *   The declared defaults keyed by structure key.
   *
   * @throws \LogicException
   *   When the class names no structure.
   */
  protected static function surfaceDeclaredDefaults(string $class): array {
    $surface = ResourcePluginHooks::usedStructureOf($class) ?? throw new \LogicException(sprintf(
      '%s names no structure with #[UsesStructure], so it has no declared defaults to read.',
      $class,
    ));
    // Fetched from the container under the documented exception: a
    // static method has no instance to have been handed anything.
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    return \Drupal::service('surface.resources')->defaults($surface);
  }

  /**
   * Reads in-progress input from an AJAX refinement rebuild.
   *
   * When a person changes a value others refine against, the form
   * rebuilds through AJAX and the structure has to refine against what was
   * just chosen rather than against what is stored. That input is not on
   * the host's own form state in any predictable place: a subform
   * state's values are unreadable before processing assigns #parents. So
   * it is located on the complete form state through the triggering
   * element's own position, which is nesting-agnostic.
   *
   * Read from the raw input rather than from the validated values, and
   * that is not a detail. A refinement trigger limits validation to
   * itself, and Form API answers a limit by throwing away every value
   * outside it before the rebuild runs, so the values are, by then, the
   * one key that was touched and nothing else, while the input is still
   * the whole form as the browser sent it. Reading the values instead
   * dropped every other in-progress edit on the way through, and left a
   * chain refining its second link against storage rather than against
   * the choice made one rebuild earlier.
   *
   * Widgets emit definition-shaped trees, so a definition's value sits
   * directly at its own key. (The adapter-era proof of concept had to
   * reach one level deeper, into a 'value' child, which is the kind of
   * coordinate bookkeeping the widget rewrite removed.)
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface being built.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the containing form.
   *
   * @return array
   *   Submitted input keyed by definition key; empty when the form is not
   *   rebuilding.
   */
  protected function surfaceRefinementInput(SurfaceInterface $surface, FormStateInterface $form_state): array {
    $state = static::surfaceCompleteFormState($form_state);
    $path = static::surfaceInputPath($state);
    if ($path === NULL) {
      return [];
    }
    $input = $state->getUserInput();
    $tree = NestedArray::getValue($input, $path);
    if (!is_array($tree)) {
      return [];
    }
    // A browser sends nothing for a checkbox not ticked, which Form API
    // records as NULL: one the page drew, enabled, holding NULL in the
    // input, says "off", as its stored value says it. One whose place in
    // the input is gone, an item removed, is no answer at all.
    foreach (static::uncheckedBoxes($state->getCompleteForm(), $path, $input) as $parents) {
      NestedArray::setValue($tree, $parents, 0, TRUE);
    }
    return array_intersect_key($tree, $surface->getDefinitions()->toArray());
  }

  /**
   * Finds the checkboxes a page drew under the container and not ticked.
   *
   * @param array|null $element
   *   The form as it was drawn, or an element in it; NULL when a form
   *   state was handed no form, as a host's tests may.
   * @param string[] $path
   *   The container's input path.
   * @param array $input
   *   The raw input.
   *
   * @return array<int, string[]>
   *   The input path below the container of each.
   */
  protected static function uncheckedBoxes(?array $element, array $path, array $input): array {
    if ($element === NULL) {
      return [];
    }
    $found = [];
    $parents = $element['#parents'] ?? NULL;
    if (($element['#type'] ?? NULL) === 'checkbox' && is_array($parents) && array_slice($parents, 0, count($path)) === $path && count($parents) > count($path)
      && empty($element['#disabled']) && ($element['#access'] ?? TRUE) !== FALSE && NestedArray::keyExists($input, $parents) && NestedArray::getValue($input, $parents) === NULL) {
      $found[] = array_slice($parents, count($path));
    }
    foreach ($element as $key => $child) {
      if (is_array($child) && !str_starts_with((string) $key, '#')) {
        array_push($found, ...static::uncheckedBoxes($child, $path, $input));
      }
    }
    return $found;
  }

  /**
   * Merges stored values with the input an AJAX rebuild is refining on.
   *
   * Or, on the build a full submission is processed against, with what
   * that submission sent: see surfaceSubmittedInput().
   *
   * The one overlay every host builds its data surface form from, so that
   * the order (stored underneath, in-progress edit on top) and the rule
   * for what the edit invalidates are said once rather than per host.
   *
   * What the builder names as discarded is dropped from two places, not
   * one. From the overlay, so the definitions refine and the elements
   * default against the fall-back value; and from the raw input, because
   * Form API resolves an element's #value from the input before it ever
   * looks at #default_value, so an input left in place would put the
   * orphaned value straight back into the rebuilt select and undo the
   * whole thing. A key that falls back to a stored value the edit
   * orphaned is held unanswered in the overlay, with what it stands for
   * beside it under SurfaceFormBuilderInterface::STANDING_KEY, so
   * nothing below it is refined against a value no longer on the screen.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface being built.
   * @param array $stored
   *   What the host holds for the data surface's keys.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the containing form.
   *
   * @return array
   *   The values to build the data surface form from.
   */
  protected function surfaceFormValues(SurfaceInterface $surface, array $stored, FormStateInterface $form_state): array {
    $state = static::surfaceCompleteFormState($form_state);
    $input = $this->surfaceRefinementInput($surface, $form_state);
    if ($input === []) {
      // Not a rebuild, so either nothing was submitted or this is the
      // build a submission is about to be processed against. In the
      // second case the elements have to be the ones the submitted
      // answers ask for: a select offering the stored venue's rooms
      // refuses the new venue's room as a choice it was never offered,
      // before the data surface is ever asked, and variants rendered as the
      // stored variant have no element for the chosen variant's keys to
      // arrive in.
      // Nothing is discarded: the person pressed the button, so every
      // value was said on purpose and is judged rather than dropped.
      $path = $this->surfaceSubmissionPath($state);
      $submitted = $this->surfaceSubmittedInput($surface, $form_state);
      if ($path !== NULL) {
        // A select left on the empty option it was given in place of a
        // stored value is built standing for that value again, so its
        // element carries the stash extraction reads it back through.
        $submitted = static::withStaleKept($submitted, $stored, static::staleKeptPaths($state, $path, $submitted, $stored));
        // A key drawn as nothing sent nothing: drawn now, it stands for
        // what is stored, which extraction keeps.
        // Decision: see docs/decisions.md#a-key-that-does-not-apply.
        $absent = array_filter(SurfaceFormBuilder::markedAbsent($state->getUserInput(), $path), static fn (string $dotted): bool => NestedArray::keyExists($stored, explode('.', $dotted)));
        $submitted = static::withStaleKept($submitted, $stored, array_values($absent));
      }
      return array_replace($stored, $submitted);
    }
    // A rebuild reads them the same way. The discard rule only looks at
    // refinement targets, so a stale key that is none — a venue the site
    // took away, left empty while the room was touched — would otherwise
    // be overlaid as empty, and rebuilt as a key that holds nothing.
    $path = static::surfaceInputPath($state) ?? [];
    // Variants drawn for what their deciding key held before this edit:
    // the input under them is that variant's, and goes, out of the raw
    // input too, so the rebuilt elements take the chosen one's start.
    // Decision: see
    // docs/decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter.
    [$switched, $input] = SurfaceFormBuilder::switchedVariants($surface, $state->getCompleteForm(), $path, $input, $stored);
    if ($switched !== []) {
      $this->forgetSurfaceInput($switched, $form_state);
    }
    $kept = static::staleKeptPaths($state, $path, $input, $stored);
    if ($kept !== []) {
      // Out of the raw input as well, so that the rebuilt element takes
      // its #default_value: the stored value, if the list offers it
      // again, and the empty option standing for it if not.
      $this->forgetSurfaceInput($kept, $form_state);
      $input = static::withStaleKept($input, $stored, $kept);
    }
    // A programmatic submission is not a rebuild. Its caller said every
    // value on purpose, in one statement, and a value the data surface
    // refuses is refused rather than quietly dropped: the payload rule,
    // and the same line the stale model draws, chosen, therefore judged.
    // Discarding is for the half-finished edit a browser is still in the
    // middle of.
    if ($state->isProgrammed()) {
      return array_replace($stored, $input);
    }
    $builder = $this->surfaceFormBuilder();
    // A key, or a dotted path to one inside an attached child or a variant:
    // the child's own refiners orphan the child's own keys.
    $discarded = $builder->discardedRefinementInput($surface, $stored, $input);
    if ($discarded !== []) {
      $this->forgetSurfaceInput($discarded, $form_state);
    }
    // The overlay itself comes from the same settling, so that a key
    // falling back to a stored value this edit orphaned answers nothing
    // below it: the elements are built from the data surface refined as the
    // person sees it, to the fixed point, not as it was posted.
    return $builder->refinementOverlay($surface, $stored, $input);
  }

  /**
   * Names the stale selects a submission left on their empty option.
   *
   * Read from the marker the container posted beside them, and held to
   * what is true now: the input at the path is still empty, and
   * something is stored there to stand for.
   *
   * @param \Drupal\Core\Form\FormStateInterface $state
   *   The complete form state.
   * @param string[] $path
   *   The input path of the data surface container.
   * @param array $input
   *   The submitted input, keyed by definition key.
   * @param array $stored
   *   What the host holds for the data surface's keys.
   *
   * @return string[]
   *   Dotted paths, relative to the container.
   *
   * @see \Drupal\surface\Form\SurfaceFormBuilderInterface::STALE_MARKER_KEY
   */
  protected static function staleKeptPaths(FormStateInterface $state, array $path, array $input, array $stored): array {
    $marker = NestedArray::getValue($state->getUserInput(), [
      ...$path,
      SurfaceFormBuilderInterface::STALE_MARKER_KEY,
    ]);
    if (!is_string($marker) || $marker === '') {
      return [];
    }
    $kept = [];
    foreach (explode(' ', $marker) as $dotted) {
      $segments = explode('.', $dotted);
      $exists = FALSE;
      $value = NestedArray::getValue($input, $segments, $exists);
      if ($exists && !ValueState::isConfigured($value) && NestedArray::keyExists($stored, $segments)) {
        $kept[] = $dotted;
      }
    }
    return $kept;
  }

  /**
   * Puts the stored value back at each path left on a stale select.
   *
   * @param array $input
   *   The submitted input, keyed by definition key.
   * @param array $stored
   *   What the host holds for the data surface's keys.
   * @param string[] $paths
   *   The dotted paths to put the stored value back at.
   *
   * @return array
   *   The input, standing for the stored values at those paths.
   */
  protected static function withStaleKept(array $input, array $stored, array $paths): array {
    foreach ($paths as $dotted) {
      $segments = explode('.', $dotted);
      NestedArray::setValue($input, $segments, NestedArray::getValue($stored, $segments), TRUE);
    }
    return $input;
  }

  /**
   * Reads what a submission sent for the data surface, before processing.
   *
   * The build a submission is processed against runs before Form API has
   * found the triggering element, so the container cannot be located the
   * way a rebuild locates it. Only a host that knows where its container
   * sits in the input can answer, through surfaceSubmissionPath(); every
   * other host answers nothing, and its elements are built from what is
   * stored as before.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface being built.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the containing form.
   *
   * @return array
   *   Submitted input keyed by definition key; empty when nothing of this
   *   form was submitted.
   */
  protected function surfaceSubmittedInput(SurfaceInterface $surface, FormStateInterface $form_state): array {
    $state = static::surfaceCompleteFormState($form_state);
    $path = $this->surfaceSubmissionPath($state);
    if ($path === NULL) {
      return [];
    }
    $tree = NestedArray::getValue($state->getUserInput(), $path);
    return is_array($tree) ? array_intersect_key($tree, $surface->getDefinitions()->toArray()) : [];
  }

  /**
   * Gets where this form's data surface sits in a submission's input.
   *
   * @param \Drupal\Core\Form\FormStateInterface $state
   *   The complete form state.
   *
   * @return string[]|null
   *   The input path of the data surface container when the form state holds
   *   a submission of this form, NULL otherwise. NULL here: a host
   *   nested inside another form cannot know its position before Form
   *   API assigns it.
   */
  protected function surfaceSubmissionPath(FormStateInterface $state): ?array {
    return NULL;
  }

  /**
   * Takes discarded keys out of the raw input the rebuild will read.
   *
   * @param string[] $keys
   *   The definition keys whose input is discarded, or dotted paths
   *   below them.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the containing form.
   */
  protected function forgetSurfaceInput(array $keys, FormStateInterface $form_state): void {
    $state = static::surfaceCompleteFormState($form_state);
    $path = static::surfaceInputPath($state);
    if ($path === NULL) {
      return;
    }
    $input = $state->getUserInput();
    foreach ($keys as $key) {
      NestedArray::unsetValue($input, [...$path, ...explode('.', $key)]);
    }
    $state->setUserInput($input);
  }

  /**
   * Gets the state the whole form's input and values live on.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state a host was handed.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The complete form state.
   */
  protected static function surfaceCompleteFormState(FormStateInterface $form_state): FormStateInterface {
    return $form_state instanceof SubformStateInterface
      ? $form_state->getCompleteFormState()
      : $form_state;
  }

  /**
   * Locates the data surface container in the raw input, via the trigger.
   *
   * A refinement trigger is one of the data surface's own elements, and
   * it says how far below the container it sits: one level for a key of
   * the data surface, two for a key of an attached child or a
   * variant, which is wired as a dependency of the child's own refiners.
   * Its absolute #parents less that path is the container, wherever the
   * host nested it. A trigger that says nothing is read as a key of the
   * data surface.
   *
   * @param \Drupal\Core\Form\FormStateInterface $state
   *   The complete form state.
   *
   * @return string[]|null
   *   The input path of the data surface container, or NULL when nothing
   *   triggered this build.
   */
  protected static function surfaceInputPath(FormStateInterface $state): ?array {
    $trigger = $state->getTriggeringElement();
    if ($trigger === NULL || !isset($trigger['#parents'])) {
      return NULL;
    }
    $below = $trigger[SurfaceFormBuilderInterface::TRIGGER_KEY]['path'] ?? [NULL];
    return array_slice($trigger['#parents'], 0, -max(1, count($below)));
  }

}
