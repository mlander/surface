<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\Form\DataSurfaceFormBuilderInterface;
use Drupal\data_surface\Hook\SurfacePluginHooks;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;
use Drupal\data_surface\Pipeline\ValueState;
use Drupal\data_surface\Surface\Attribute\UsesSurface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\SurfaceBuild\SurfacesInterface;

/**
 * The few things every host-side adoption trait needs.
 *
 * Composed into the configuration trait, the plugin form trait and the
 * formatter trait so none of them repeats the other, and so a class that
 * uses two of them at once (a surface block uses the first two) inherits
 * one copy of each method rather than colliding.
 *
 * Why the services are fetched rather than injected, documented here
 * once because it is the trap that keeps catching adopters: the host
 * base classes call into surface code from inside their constructors.
 * BlockPluginTrait::__construct() calls setConfiguration(), which asks
 * the surface for its defaults, before any subclass constructor body has
 * run — so a collaborator assigned through constructor promotion or
 * through create() is still unset at the moment it would be needed. A
 * property assigned before parent::__construct() would work, but every
 * adopting plugin would have to know that and write its constructor
 * around it, which is exactly the ceremony this layer exists to delete.
 * Fetching from the container at the point of use is the honest answer
 * for a host whose constructor calls us; it stays a documented exception
 * rather than a habit.
 *
 * The build step is fetched here for the same reason and under the same
 * exception: a base class asked for its surface from inside its own
 * constructor has no injected build step yet.
 *
 * Every host names its surface the one way, with #[UsesSurface] on the
 * plugin class, and builds it in the context the host supplies: the
 * host's own verb as the operation, since a plugin instance is the whole
 * subject and no situation describes it.
 */
trait DataSurfaceHostTrait {

  /**
   * Gets the surface pipeline.
   *
   * @return \Drupal\data_surface\Pipeline\DataSurfacePipelineInterface
   *   The pipeline.
   */
  protected function surfacePipeline(): DataSurfacePipelineInterface {
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    return \Drupal::service('data_surface.pipeline');
  }

  /**
   * Gets the surface form builder.
   *
   * @return \Drupal\data_surface\Form\DataSurfaceFormBuilderInterface
   *   The form builder.
   */
  protected function surfaceFormBuilder(): DataSurfaceFormBuilderInterface {
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    return \Drupal::service('data_surface.form_builder');
  }

  /**
   * Gets the build step.
   *
   * Fetched under the documented exception: a block asks for its surface
   * from inside its own constructor.
   *
   * @return \Drupal\data_surface\SurfaceBuild\SurfacesInterface
   *   The build step.
   */
  protected function surfaces(): SurfacesInterface {
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    return \Drupal::service('data_surface.surfaces');
  }

  /**
   * Gets the surface class #[UsesSurface] names on this plugin.
   *
   * Read from the plugin definition, where the plugin type's definition
   * alter copied it, so a plugin constructed with a hand-made definition
   * that carries no such key is taken at its definition's word.
   *
   * @return class-string|null
   *   The surface class, or NULL when the definition names none.
   *
   * @see \Drupal\data_surface\Hook\SurfacePluginHooks
   */
  protected function usedSurface(): ?string {
    if (!$this instanceof PluginInspectionInterface) {
      return NULL;
    }
    $definition = $this->getPluginDefinition();
    $surface = is_array($definition) ? ($definition[UsesSurface::DEFINITION_KEY] ?? NULL) : NULL;
    return is_string($surface) ? $surface : NULL;
  }

  /**
   * Gets the context this host asks for its surface in.
   *
   * A plugin host knows nothing a situation would: the instance is the
   * whole subject, and its configuration is loaded by the host, so the
   * context is the operation and nothing else.
   *
   * @param string $operation
   *   The host operation.
   *
   * @return \Drupal\data_surface\Surface\SurfaceContext
   *   The context.
   */
  protected function surfaceContext(string $operation): SurfaceContext {
    // Decision: see docs/decisions.md#a-plugin-hosts-context.
    return new SurfaceContext($operation);
  }

  /**
   * Builds the surface this host's plugin names, in the host's context.
   *
   * @param string $operation
   *   The host operation.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface, alters applied.
   *
   * @throws \LogicException
   *   When the plugin names no surface with #[UsesSurface].
   */
  protected function hostedSurface(string $operation = 'configure'): DataSurfaceInterface {
    $surface = $this->usedSurface() ?? throw new \LogicException(sprintf(
      '%s names no surface: put #[UsesSurface] on the class, naming a #[Surface] class in a module\'s src/Surface.',
      static::class,
    ));
    return $this->surfaces()->build($surface, $this->surfaceContext($operation));
  }

  /**
   * Answers whether an account may configure this host's values.
   *
   * The surface's own answer in the host's context: its access class,
   * when it names one, since a host verb is no situation and has no
   * permission tier; neutral when it names none, which leaves the
   * question to whoever asked — a route requirement, an entity access
   * handler, a tool's own check. Forbidden blocks the pipeline's submit
   * before it reads storage; allowed agrees without bypassing the host's
   * own gates.
   *
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account to answer for, or NULL for the current user.
   * @param string $operation
   *   The host operation the answer is wanted for.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access answer.
   */
  public function surfaceAccess(?AccountInterface $account = NULL, string $operation = 'configure'): AccessResultInterface {
    $surface = $this->usedSurface();
    return $surface === NULL
      ? AccessResult::neutral()
      : $this->surfaces()->access($surface, $this->surfaceContext($operation), $this->surfaceAccount($account));
  }

  /**
   * Gets the account an access answer is about.
   *
   * NULL means the current user, everywhere a surface answers for an
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
   * Reads the default values the surface a class names declares.
   *
   * Several host protocols ask for their defaults statically — a
   * formatter's defaultSettings(), a field type's defaultFieldSettings()
   * — and a static method cannot consult an instance surface. The class
   * names its surface statically, with #[UsesSurface], so it can still
   * answer: from that surface's own shape alone, with no alter and no
   * context. The defaults an alter mounts are advertised on the built
   * surface and reach storage under the third party namespace the host
   * protocol already knows about. Kept here rather than in each host's
   * trait so the several static-defaults shims cannot disagree.
   *
   * @param class-string $class
   *   The fully qualified class name.
   *
   * @return array
   *   The declared defaults keyed by surface key.
   *
   * @throws \LogicException
   *   When the class names no surface.
   */
  protected static function surfaceDeclaredDefaults(string $class): array {
    $surface = SurfacePluginHooks::usedSurfaceOf($class) ?? throw new \LogicException(sprintf(
      '%s names no surface with #[UsesSurface], so it has no declared defaults to read.',
      $class,
    ));
    // Fetched from the container under the documented exception: a
    // static method has no instance to have been handed anything.
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    return \Drupal::service('data_surface.surfaces')->defaults($surface);
  }

  /**
   * Reads in-progress input from an AJAX refinement rebuild.
   *
   * When a person changes a value others refine against, the form
   * rebuilds through AJAX and the surface has to refine against what was
   * just chosen rather than against what is stored. That input is not on
   * the host's own form state in any predictable place: a subform
   * state's values are unreadable before processing assigns #parents. So
   * it is located on the complete form state through the triggering
   * element's own position, which is nesting-agnostic.
   *
   * Read from the raw input rather than from the validated values, and
   * that is not a detail. A refinement trigger limits validation to
   * itself, and Form API answers a limit by throwing away every value
   * outside it before the rebuild runs — so the values are, by then, the
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
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface being built.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the containing form.
   *
   * @return array
   *   Submitted input keyed by surface key; empty when the form is not
   *   rebuilding.
   */
  protected function surfaceRefinementInput(DataSurfaceInterface $surface, FormStateInterface $form_state): array {
    $state = static::surfaceCompleteFormState($form_state);
    $path = static::surfaceInputPath($state);
    if ($path === NULL) {
      return [];
    }
    $input = $state->getUserInput();
    $tree = NestedArray::getValue($input, $path);
    return is_array($tree) ? array_intersect_key($tree, $surface->getDefinitions()->toArray()) : [];
  }

  /**
   * Merges stored values with the input an AJAX rebuild is refining on.
   *
   * Or, on the build a full submission is processed against, with what
   * that submission sent: see surfaceSubmittedInput().
   *
   * The one overlay every host builds its surface form from, so that the
   * order — stored underneath, in-progress edit on top — and the rule
   * for what the edit invalidates are said once rather than per host.
   *
   * What the builder names as discarded is dropped from two places, not
   * one. From the overlay, so the definitions refine and the elements
   * default against the fall-back value; and from the raw input, because
   * Form API resolves an element's #value from the input before it ever
   * looks at #default_value, so an input left in place would put the
   * orphaned value straight back into the rebuilt select and undo the
   * whole thing.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface being built.
   * @param array $stored
   *   What the host holds for the surface's keys.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the containing form.
   *
   * @return array
   *   The values to build the surface form from.
   */
  protected function surfaceFormValues(DataSurfaceInterface $surface, array $stored, FormStateInterface $form_state): array {
    $state = static::surfaceCompleteFormState($form_state);
    $input = $this->surfaceRefinementInput($surface, $form_state);
    if ($input === []) {
      // Not a rebuild, so either nothing was submitted or this is the
      // build a submission is about to be processed against. In the
      // second case the elements have to be the ones the submitted
      // answers ask for: a select offering the stored venue's rooms
      // refuses the new venue's room as a choice it was never offered,
      // before the surface is ever asked, and a slot rendered as the
      // stored variant has no element for the chosen variant's keys to
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
      }
      return array_replace($stored, $submitted);
    }
    // A rebuild reads them the same way. The discard rule only looks at
    // refinement targets, so a stale key that is none — a venue the site
    // took away, left empty while the room was touched — would otherwise
    // be overlaid as empty, and rebuilt as a key that holds nothing.
    $path = static::surfaceInputPath($state) ?? [];
    $kept = static::staleKeptPaths($state, $path, $input, $stored);
    if ($kept !== []) {
      // Out of the raw input as well, so that the rebuilt element takes
      // its #default_value: the stored value, if the list offers it
      // again, and the empty option standing for it if not.
      $this->forgetSurfaceInput($kept, $form_state);
      $input = static::withStaleKept($input, $stored, $kept);
    }
    // A programmatic submission is not a rebuild. Its caller said every
    // value on purpose, in one statement, and a value the surface
    // refuses is refused rather than quietly dropped — the payload rule,
    // and the same line the stale model draws: chosen, therefore judged.
    // Discarding is for the half-finished edit a browser is still in the
    // middle of.
    if (!$state->isProgrammed()) {
      $discarded = $this->surfaceFormBuilder()->discardedRefinementInput($surface, $stored, $input);
      if ($discarded !== []) {
        $this->forgetSurfaceInput($discarded, $form_state);
        // A key, or a dotted path to one inside an attached child or a
        // slot: the child's own refiners orphan the child's own keys.
        foreach ($discarded as $dotted) {
          NestedArray::unsetValue($input, explode('.', $dotted));
        }
      }
    }
    return $input === [] ? $stored : array_replace($stored, $input);
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
   *   The input path of the surface container.
   * @param array $input
   *   The submitted input, keyed by surface key.
   * @param array $stored
   *   What the host holds for the surface's keys.
   *
   * @return string[]
   *   Dotted paths, relative to the container.
   *
   * @see \Drupal\data_surface\Form\DataSurfaceFormBuilderInterface::STALE_MARKER_KEY
   */
  protected static function staleKeptPaths(FormStateInterface $state, array $path, array $input, array $stored): array {
    $marker = NestedArray::getValue($state->getUserInput(), [
      ...$path,
      DataSurfaceFormBuilderInterface::STALE_MARKER_KEY,
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
   *   The submitted input, keyed by surface key.
   * @param array $stored
   *   What the host holds for the surface's keys.
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
   * Reads what a submission sent for the surface, before it is processed.
   *
   * The build a submission is processed against runs before Form API has
   * found the triggering element, so the container cannot be located the
   * way a rebuild locates it. Only a host that knows where its container
   * sits in the input can answer, through surfaceSubmissionPath(); every
   * other host answers nothing, and its elements are built from what is
   * stored as before.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface being built.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the containing form.
   *
   * @return array
   *   Submitted input keyed by surface key; empty when nothing of this
   *   form was submitted.
   */
  protected function surfaceSubmittedInput(DataSurfaceInterface $surface, FormStateInterface $form_state): array {
    $state = static::surfaceCompleteFormState($form_state);
    $path = $this->surfaceSubmissionPath($state);
    if ($path === NULL) {
      return [];
    }
    $tree = NestedArray::getValue($state->getUserInput(), $path);
    return is_array($tree) ? array_intersect_key($tree, $surface->getDefinitions()->toArray()) : [];
  }

  /**
   * Gets where this form's surface sits in a submission's input.
   *
   * @param \Drupal\Core\Form\FormStateInterface $state
   *   The complete form state.
   *
   * @return string[]|null
   *   The input path of the surface container when the form state holds
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
   *   The surface keys whose input is discarded, or dotted paths below
   *   them.
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
   * Locates the surface container inside the raw input, via the trigger.
   *
   * A refinement trigger is one of the surface's own elements, and it
   * says how far below the container it sits: one level for a key of the
   * surface, two for a key of an attached child or a slot variant, which
   * is wired as a dependency of the child's own refiners. Its absolute
   * #parents less that path is the container, wherever the host nested
   * it. A trigger that says nothing is read as a key of the surface.
   *
   * @param \Drupal\Core\Form\FormStateInterface $state
   *   The complete form state.
   *
   * @return string[]|null
   *   The input path of the surface container, or NULL when nothing
   *   triggered this build.
   */
  protected static function surfaceInputPath(FormStateInterface $state): ?array {
    $trigger = $state->getTriggeringElement();
    if ($trigger === NULL || !isset($trigger['#parents'])) {
      return NULL;
    }
    $below = $trigger[DataSurfaceFormBuilderInterface::TRIGGER_KEY]['path'] ?? [NULL];
    return array_slice($trigger['#parents'], 0, -max(1, count($below)));
  }

}
