<?php

declare(strict_types=1);

namespace Drupal\data_surface\SurfaceBuild;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\data_surface\DataSurface;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;
use Drupal\data_surface\Refinement\Narrowing;
use Drupal\data_surface\Surface\AltersOutputsInterface;
use Drupal\data_surface\Surface\HasOutputsInterface;
use Drupal\data_surface\Surface\HasStorageShapeInterface;
use Drupal\data_surface\Surface\SurfaceAccessInterface;
use Drupal\data_surface\Surface\SurfaceAlterInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceInterface;
use Drupal\data_surface\Surface\SurfaceTargetInterface;
use Drupal\data_surface\SurfaceAttachment;

/**
 * The build step, over the engine's builder and factory.
 *
 * In order, for one surface and one context:
 *
 * 1. The owner's shape. defineInputs() and, for a surface with outputs,
 *    defineOutputs(), each over a SurfaceShape that writes straight into
 *    a fresh DataSurfaceBuilder.
 * 2. The alters #[AltersSurface] names for this surface, in discovery
 *    order, skipping one whose situations leave this context's operation
 *    out: alterInputs() and alterOutputs() over a SurfaceShapeAdditions,
 *    which mounts what they add under the alter's module.
 * 3. The seal-time checks on the shape: every identity key is a declared
 *    input, and no situation id is provided twice.
 * 4. The context. Starting values become the declared defaults, when
 *    the context creates; each constraint is added to its key and held
 *    to the narrowing check; each identity key the context knows becomes
 *    that key's default and is locked, which is the engine's lock — the
 *    value fixed to the declared default.
 * 5. The refiners. Every #[RefinesInput] method is checked against the
 *    shape, then bound: the keys it watches become the engine's
 *    refinement edges with addRefinement(), and its class becomes one
 *    RefinesInputRefiner link in the owner's chain of that key, the
 *    surface's links before any alter's. A method that watches nothing
 *    runs once, here, and is held to the narrowing check.
 * 6. The children, each through this same build step in its own frame.
 * 7. The seal.
 *
 * The engine's builder is the whole state of a build. Nothing is cached
 * across builds, because a surface describes live site state; what that
 * state is, a shape or an alter says with addCacheableDependency().
 */
final class Surfaces implements SurfacesInterface {

  /**
   * Constructs the build step.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceRegistry $registry
   *   What discovery found.
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The typed data manager, for the definitions add() creates.
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface $classResolver
   *   The class resolver, which returns an alter, a target or an access
   *   class as the autowired service discovery registered, and makes a
   *   surface with no constructor.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The account an access question is about when none is named.
   * @param \Drupal\data_surface\SurfaceBuild\SituationArguments $situationArguments
   *   What turns a route's or a tool's values into a situation's
   *   arguments, an entity's id into the entity among them.
   * @param \Drupal\data_surface\SurfaceBuild\DerivedVariants $derivedVariants
   *   What fills an open slot for the values no declared variant fills.
   */
  public function __construct(
    protected readonly SurfaceRegistry $registry,
    protected readonly TypedDataManagerInterface $typedDataManager,
    protected readonly ClassResolverInterface $classResolver,
    protected readonly AccountInterface $currentUser,
    protected readonly SituationArguments $situationArguments,
    protected readonly DerivedVariants $derivedVariants,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function build(string $surface, SurfaceContext $context): DataSurfaceInterface {
    return $this->buildSurface($surface, $context, []);
  }

  /**
   * Builds one surface, or one subsurface inside the build of its parent.
   *
   * @param string $surface
   *   The surface class, or its #[Surface] id.
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   Where it is being asked for.
   * @param array<int, array{class: class-string, id: string, key: string, inputs: string[]}> $ancestry
   *   The surfaces this one is being built inside, outermost first, each
   *   with the key it is attached at and its own input keys; empty for
   *   a surface asked for on its own.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The sealed surface.
   */
  protected function buildSurface(string $surface, SurfaceContext $context, array $ancestry): DataSurfaceInterface {
    $definition = $this->registry->getDefinition($surface);
    // Refuses two providers of one situation id, whichever is asked for.
    $this->registry->getSituations($definition->class);

    $builder = new DataSurfaceBuilder();
    $owner = $this->instance($definition->class, SurfaceInterface::class);
    $inputs = new SurfaceShape($builder, $this->typedDataManager);
    $outputs = new SurfaceShape($builder, $this->typedDataManager, TRUE);
    $owner->defineInputs($inputs);
    if ($owner instanceof HasOutputsInterface) {
      $owner->defineOutputs($outputs);
    }

    $links = [[$owner, $definition->refiners, NULL]];
    $additions = [];
    foreach ($definition->alters as $alter) {
      if (!$alter->appliesIn($context->operation)) {
        continue;
      }
      $instance = $this->classResolver->getInstanceFromDefinition($alter->class);
      if ($instance instanceof SurfaceAlterInterface) {
        $instance->alterInputs($additions[$alter->module][0] ??= new SurfaceShapeAdditions($builder, $this->typedDataManager, $alter->module));
      }
      if ($instance instanceof AltersOutputsInterface) {
        $instance->alterOutputs($additions[$alter->module][1] ??= new SurfaceShapeAdditions($builder, $this->typedDataManager, $alter->module, TRUE));
      }
      if ($instance instanceof HasStorageShapeInterface) {
        // phpcs:ignore Drupal.Files.LineLength.TooLong
        // SKETCH GAP: the sketch has no storage shape for what an alter mounts; an alter implementing HasStorageShapeInterface hands one for its own module's mount, which the builder refuses for a module that mounts nothing and SurfaceTargetAdapter applies.
        $builder->setThirdPartyShape($alter->module, $instance->storageShape());
      }
      $links[] = [$instance, $alter->refiners, $alter->module];
    }

    $input_keys = $inputs->keys();
    $subsurfaces = array_merge(array_keys($inputs->attachments()), array_keys($inputs->slots()));
    foreach ($definition->identity as $key) {
      if (!in_array($key, $input_keys, TRUE)) {
        throw new \LogicException(sprintf(
          'The %s surface (%s) names "%s" as an identity key in #[Surface(identity:)], but its shape never declares that input%s.',
          $definition->id,
          $definition->class,
          $key,
          in_array($key, $subsurfaces, TRUE) ? ' (it is a subsurface, which holds a map and cannot say which thing this is)' : '',
        ));
      }
    }
    foreach ($ancestry as $ancestor) {
      if ($ancestor['class'] === $definition->class) {
        throw new \LogicException(sprintf(
          'The %s surface is attached inside itself, through %s: a surface cannot contain itself.',
          $definition->id,
          implode(' -> ', array_map(static fn (array $level): string => $level['id'] . '.' . $level['key'], $ancestry)),
        ));
      }
    }
    $slots = $this->slotsOf($definition, $inputs, $input_keys);

    $this->applyContext($builder, $definition, $context, $input_keys, $subsurfaces);
    foreach ($links as [$instance, $refiners, $module]) {
      $extended = $module !== NULL && isset($additions[$module][0]) ? $additions[$module][0]->extended() : [];
      $this->bindRefiners($builder, $definition, $instance, $refiners, $input_keys, $outputs->keys(), $subsurfaces, $ancestry === [] ? NULL : $ancestry[count($ancestry) - 1], $module, $extended);
    }

    // The children, each through this same build step in its own frame:
    // its own shape, its own alters, its own refiners, and the context
    // the parent's context hands it.
    $frame = ['class' => $definition->class, 'id' => $definition->id, 'key' => '', 'inputs' => $input_keys];
    foreach ($inputs->attachments() as $key => $child) {
      $builder->attach($key, $this->buildChild($child, $context, $key, $frame, $ancestry));
    }
    foreach ($slots as $key => ['by' => $by, 'children' => $children]) {
      $variants = [];
      foreach ($children as $value => $child) {
        $variants[(string) $value] = $this->buildChild($child, $context, $key, $frame, $ancestry);
      }
      $deriver = $this->derivedVariants->for($definition->class, $key);
      if ($deriver !== NULL) {
        // phpcs:ignore Drupal.Files.LineLength.TooLong
        // SKETCH GAP: the sketch fills an open slot only with #[SurfaceVariant] classes; a value no class fills (a field type with no settings surface) gets a variant derived from a description that already exists (its config schema), sealed here as a shape with no class, so no alter, refiner, target or access class of its own.
        foreach ($deriver->variants(array_keys($variants)) as $value => $definitions) {
          $variants[(string) $value] ??= new SurfaceAttachment((new DataSurfaceBuilder($definitions))->seal());
        }
      }
      $builder->attachBy($key, $by, $variants);
    }

    return $builder->seal();
  }

  /**
   * Builds one child at a key, in the context its parent's hands it.
   *
   * @param class-string $child
   *   The child surface class.
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   The parent's context.
   * @param string $key
   *   The key the child sits at.
   * @param array{class: class-string, id: string, key: string, inputs: string[]} $frame
   *   The parent, for messages and the cycle check.
   * @param array $ancestry
   *   The parent's own ancestry.
   *
   * @return \Drupal\data_surface\SurfaceAttachment
   *   The sealed child.
   */
  protected function buildChild(string $child, SurfaceContext $context, string $key, array $frame, array $ancestry): SurfaceAttachment {
    $class = $this->registry->getDefinition($child)->class;
    $frame['key'] = $key;
    return new SurfaceAttachment(
      $this->buildSurface($class, static::childContext($context, $key), [...$ancestry, $frame]),
      $class,
    );
  }

  /**
   * Gets the context a child at a key is built and stored in.
   *
   * Its own, when the parent's context hands it one with withChild(),
   * as a situation does to tell a child the parent's identity. Otherwise
   * the parent's operation, whether it creates, and the identity it
   * knows, which is what lets a child's target load by the same thing
   * its parent's does.
   *
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   The parent's context.
   * @param string $key
   *   The key the child sits at.
   *
   * @return \Drupal\data_surface\Surface\SurfaceContext
   *   The child's context.
   */
  public static function childContext(SurfaceContext $context, string $key): SurfaceContext {
    $child = $context->forChild($key);
    if ($child !== $context) {
      return $child;
    }
    // phpcs:ignore Drupal.Files.LineLength.TooLong
    // SKETCH GAP: the sketch says a child "otherwise sees its parent's" context; it sees the operation, creates and known identity, but not the parent's constraints, starting values or child contexts, which all name the parent's keys.
    return new SurfaceContext($context->operation, $context->creates, $context->known);
  }

  /**
   * Resolves each slot's children and checks its deciding key.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $definition
   *   The surface.
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceShape $inputs
   *   The owner's input shape.
   * @param string[] $input_keys
   *   The owner's plain input keys.
   *
   * @return array<string, array{by: string, children: array<string, class-string>}>
   *   The slots, each filled from discovery's variants.
   *
   * @throws \LogicException
   *   When a deciding key is not a plain input of the surface.
   */
  protected function slotsOf(SurfaceDefinition $definition, SurfaceShape $inputs, array $input_keys): array {
    $slots = [];
    foreach ($inputs->slots() as $key => $by) {
      if (!in_array($by, $input_keys, TRUE)) {
        throw new \LogicException(sprintf(
          'The %s surface\'s "%s" slot is chosen by "%s", which its shape never declares as a plain input: a slot is chosen by a sibling that holds one value.',
          $definition->id,
          $key,
          $by,
        ));
      }
      // Every surface whose #[SurfaceVariant] names this surface and key
      // fills it; the parent names none.
      // phpcs:ignore Drupal.Files.LineLength.TooLong
      // SKETCH GAP: the sketch does not say what a slot nothing fills is; it stays a placeholder and its deciding key gains an empty Choice, so nothing can be chosen, rather than refusing the surface on a site with no variant module.
      $slots[$key] = [
        'by' => $by,
        'children' => $this->registry->getVariants($definition->class, $key),
      ];
    }
    return $slots;
  }

  /**
   * {@inheritdoc}
   */
  public function situation(string $surface, string $situation, array $arguments = []): SurfaceContext {
    $found = $this->registry->getSituation($surface, $situation);
    $context = (new \ReflectionMethod($found->class, $found->method))
      ->invokeArgs(NULL, $this->situationArguments->resolve($found, $arguments));
    if (!$context instanceof SurfaceContext) {
      throw new \LogicException(sprintf(
        'The "%s" situation, %s, returned %s; a situation returns a %s.',
        $found->id,
        $found->describe(),
        get_debug_type($context),
        SurfaceContext::class,
      ));
    }
    // phpcs:ignore Drupal.Files.LineLength.TooLong
    // SKETCH GAP: the sketch says the operation is the situation id but not who enforces it; a situation returning another operation is refused here.
    if ($context->operation !== $found->id) {
      throw new \LogicException(sprintf(
        'The "%s" situation, %s, returned a context for the "%s" operation. A situation\'s context carries its own id as the operation: call withOperation(\'%s\') on a context built from another situation.',
        $found->id,
        $found->describe(),
        $context->operation,
        $found->id,
      ));
    }
    return $context;
  }

  /**
   * {@inheritdoc}
   */
  public function buildSituation(string $surface, string $situation, array $arguments = []): DataSurfaceInterface {
    return $this->build($surface, $this->situation($surface, $situation, $arguments));
  }

  /**
   * {@inheritdoc}
   */
  public function access(string $surface, SurfaceContext $context, ?AccountInterface $account = NULL): AccessResultInterface {
    $definition = $this->registry->getDefinition($surface);
    $account ??= $this->currentUser;
    $answer = $this->ownAccess($definition, $context, $account);
    if ($answer->isForbidden() || !$this->childrenMayAnswer($definition)) {
      return $answer;
    }
    // phpcs:ignore Drupal.Files.LineLength.TooLong
    // SKETCH GAP: the sketch gives a surface an access class but does not say whether a subsurface's counts; a child the context resolves is asked in its own context and may refuse, never allow, so a field type's settings can refuse the field they belong to.
    $answers = $this->childAnswers($context, $account, $this->build($definition->class, $context));
    foreach ($answers as $child) {
      if ($child->isForbidden()) {
        return $answer->andIf($child);
      }
    }
    // A child that did not refuse still varied by what it read.
    if ($answer instanceof RefinableCacheableDependencyInterface) {
      foreach ($answers as $child) {
        $answer->addCacheableDependency($child);
      }
    }
    return $answer;
  }

  /**
   * Answers access for one surface alone: its permission, its class.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $definition
   *   The surface.
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   The context.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The answer.
   */
  protected function ownAccess(SurfaceDefinition $definition, SurfaceContext $context, AccountInterface $account): AccessResultInterface {
    // phpcs:ignore Drupal.Files.LineLength.TooLong
    // SKETCH GAP: the sketch does not cover a context whose operation is no declared situation (a plugin host's 'configure'); it has no permission tier, so the access class alone answers, or neutral.
    $permission = $this->registry->getSituations($definition->class)[$context->operation]->permission ?? NULL;
    $result = AccessResult::neutral();
    if ($permission !== NULL) {
      $resolved = static::resolvePermission($permission, $context);
      // phpcs:ignore Drupal.Files.LineLength.TooLong
      // SKETCH GAP: the sketch does not say what a %key placeholder the context does not know means; it is forbidden, since the permission cannot be named.
      if ($resolved === NULL) {
        return AccessResult::forbidden(sprintf(
          'The "%s" situation\'s permission, "%s", names identity its context does not know.',
          $context->operation,
          $permission,
        ));
      }
      $result = AccessResult::allowedIfHasPermission($account, $resolved);
      if (!$result->isAllowed()) {
        return $result;
      }
    }
    if ($definition->access === NULL) {
      return $result;
    }
    $answer = $this->instance($definition->access, SurfaceAccessInterface::class)->access($context, $account);
    return $permission === NULL ? $answer : $result->andIf($answer);
  }

  /**
   * Says whether any other discovered surface has an access class.
   *
   * Building a surface to find its children costs a build, so it is done
   * only when some child could have something to say.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $definition
   *   The surface being asked about.
   *
   * @return bool
   *   TRUE when some other surface names an access class.
   */
  protected function childrenMayAnswer(SurfaceDefinition $definition): bool {
    foreach ($this->registry->getDefinitions() as $other) {
      if ($other->class !== $definition->class && $other->access !== NULL) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Asks every child a context resolves that has an access class.
   *
   * An attached child, and a slot's variant when its deciding key is
   * locked, each in the context the parent's hands it, recursively.
   *
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   The parent's context.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   * @param \Drupal\data_surface\DataSurfaceInterface $built
   *   The parent, built in that context.
   *
   * @return \Drupal\Core\Access\AccessResultInterface[]
   *   The children's answers, outermost first.
   */
  protected function childAnswers(SurfaceContext $context, AccountInterface $account, DataSurfaceInterface $built): array {
    $answers = [];
    foreach ($built->getDefinitions()->entries() as $key => $entry) {
      $attachment = $entry->attachment;
      if ($attachment === NULL && $entry->slot !== NULL && $built->isLocked($entry->slot->by)) {
        $chosen = $entry->slot->chosen($built->getDefault($entry->slot->by));
        $attachment = $chosen === NULL ? NULL : $entry->slot->variant($chosen);
      }
      if ($attachment?->source === NULL) {
        continue;
      }
      $child = $this->registry->getDefinition($attachment->source);
      $child_context = static::childContext($context, (string) $key);
      if ($child->access !== NULL) {
        $answers[] = $this->instance($child->access, SurfaceAccessInterface::class)->access($child_context, $account);
      }
      array_push($answers, ...$this->childAnswers($child_context, $account, $attachment->child));
    }
    return $answers;
  }

  /**
   * {@inheritdoc}
   */
  public function defaults(string $surface): array {
    $definition = $this->registry->getDefinition($surface);
    $builder = new DataSurfaceBuilder();
    $this->instance($definition->class, SurfaceInterface::class)
      ->defineInputs(new SurfaceShape($builder, $this->typedDataManager));
    // phpcs:ignore Drupal.Files.LineLength.TooLong
    // SKETCH GAP: the sketch has no static defaults; a plugin host whose protocol asks a class for its defaults statically reads the owner's shape alone, sealed on the spot with no alter or context.
    return $builder->seal()->getDefaultValues();
  }

  /**
   * {@inheritdoc}
   */
  public function target(string $surface, SurfaceContext $context, ?DataSurfaceInterface $built = NULL): DataSurfaceTargetInterface {
    $definition = $this->registry->getDefinition($surface);
    if ($definition->target === NULL) {
      throw new \LogicException(sprintf(
        'The %s surface names no target in #[Surface(target:)]: its host supplies one, the way the block host stores a plugin\'s configuration.',
        $definition->id,
      ));
    }
    return $this->targetFor($definition, $context, $built ?? $this->build($definition->class, $context));
  }

  /**
   * Composes a surface's target with its children's, along the tree.
   *
   * Each subsurface whose class names a target of its own is routed to
   * it, in the context the parent's hands that child, and recursively
   * for the child's own children; a subsurface without one stays in its
   * parent's values, stored by the parent under its key. A slot routes
   * per variant, by what its deciding key holds.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $definition
   *   The surface, which has a target.
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   Its context.
   * @param \Drupal\data_surface\DataSurfaceInterface $built
   *   The surface as built, whose entries carry the children.
   *
   * @return \Drupal\data_surface\SurfaceBuild\SurfaceTargetAdapter
   *   The target.
   */
  protected function targetFor(SurfaceDefinition $definition, SurfaceContext $context, DataSurfaceInterface $built): SurfaceTargetAdapter {
    $routes = [];
    foreach ($built->getDefinitions()->entries() as $key => $entry) {
      $children = $entry->attachment !== NULL
        ? [SurfaceTargetAdapter::ATTACHED => $entry->attachment]
        : ($entry->slot !== NULL ? $entry->slot->variants : []);
      foreach ($children as $id => $attachment) {
        $child = $attachment->source === NULL ? NULL : $this->registry->getDefinition($attachment->source);
        if ($child?->target !== NULL) {
          $routes[(string) $key][(string) $id] = $this->targetFor($child, static::childContext($context, (string) $key), $attachment->child);
        }
      }
    }
    return new SurfaceTargetAdapter(
      $this->instance((string) $definition->target, SurfaceTargetInterface::class),
      $context,
      $routes,
      $definition->identity,
    );
  }

  /**
   * Applies what a context says about where the surface is asked for.
   *
   * @param \Drupal\data_surface\DataSurfaceBuilderInterface $builder
   *   The builder holding the shape.
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $definition
   *   The surface.
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   The context.
   * @param string[] $input_keys
   *   The owner's input keys.
   * @param string[] $subsurfaces
   *   The owner's subsurface keys.
   *
   * @throws \LogicException
   *   When the context names a key the shape does not declare, carries
   *   starting values without creating, or widens a key.
   */
  protected function applyContext(DataSurfaceBuilderInterface $builder, SurfaceDefinition $definition, SurfaceContext $context, array $input_keys, array $subsurfaces = []): void {
    $assert_input = static function (string $key, string $what) use ($definition, $context, $input_keys, $subsurfaces): void {
      if (in_array($key, $subsurfaces, TRUE)) {
        // phpcs:ignore Drupal.Files.LineLength.TooLong
        // SKETCH GAP: the sketch narrows a child through the child's own context; a constraint or starting value the parent's context names for a subsurface key is refused, and pointed at withChild().
        throw new \LogicException(sprintf(
          'The "%s" context gives a %s for "%s", which is a subsurface of the %s surface: a child is narrowed and started by its own context, handed to it with withChild(\'%s\', ...).',
          $context->operation,
          $what,
          $key,
          $definition->id,
          $key,
        ));
      }
      if (!in_array($key, $input_keys, TRUE)) {
        throw new \LogicException(sprintf(
          'The "%s" context gives a %s for "%s", which the %s surface does not declare as an input.',
          $context->operation,
          $what,
          $key,
          $definition->id,
        ));
      }
    };

    if ($context->starting !== []) {
      // phpcs:ignore Drupal.Files.LineLength.TooLong
      // SKETCH GAP: the sketch applies starting values "if it creates" but not what happens otherwise; a non-creating context with starting values is refused rather than silently ignored.
      if (!$context->creates) {
        throw new \LogicException(sprintf(
          'The "%s" context carries starting values for %s but does not create. Starting values are where a new thing begins; a thing that exists begins from what its target loads.',
          $context->operation,
          implode(', ', array_keys($context->starting)),
        ));
      }
      // A starting value is the value a caller sees before choosing, and
      // may replace: exactly what a declared default is to the engine.
      // phpcs:ignore Drupal.Files.LineLength.TooLong
      // SKETCH GAP: the sketch calls them "initial values" without a mechanism; they become the key's declared default (setDefault), overriding the shape's default for this build only.
      foreach ($context->starting as $key => $value) {
        $assert_input((string) $key, 'starting value');
        $builder->setDefault((string) $key, $value);
      }
    }

    foreach ($context->constraints as $key => $constraints) {
      $assert_input((string) $key, 'constraint');
      $target = $builder->getDefinition((string) $key);
      assert($target !== NULL);
      $before = DataSurface::deepClone($target);
      foreach ($constraints as $name => $options) {
        $target->addConstraint((string) $name, $options);
      }
      Narrowing::assertNarrows((string) $key, sprintf('the "%s" situation', $context->operation), $before, $target);
    }

    // phpcs:ignore Drupal.Files.LineLength.TooLong
    // SKETCH GAP: the sketch does not say what a known key that is not an identity key does; it is not locked, and stays on the context for the target and access class to read.
    foreach ($definition->identity as $key) {
      if (array_key_exists($key, $context->known)) {
        $builder->setDefault($key, $context->known[$key]);
        $builder->lock($key);
      }
    }
  }

  /**
   * Checks one class's refiners against the shape, and binds them.
   *
   * @param \Drupal\data_surface\DataSurfaceBuilderInterface $builder
   *   The builder holding the shape.
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $definition
   *   The surface.
   * @param object $instance
   *   The surface or alter instance the methods are called on.
   * @param \Drupal\data_surface\SurfaceBuild\RefinerDefinition[] $refiners
   *   Its #[RefinesInput] methods.
   * @param string[] $input_keys
   *   The owner's input keys.
   * @param string[] $output_keys
   *   The owner's output keys.
   * @param string[] $subsurfaces
   *   The owner's subsurface keys.
   * @param array{class: class-string, id: string, key: string, inputs: string[]}|null $parent
   *   The surface this one is attached inside, or NULL.
   * @param string|null $module
   *   The module of an alter, or NULL for the surface itself.
   * @param string[] $extended
   *   The owner's keys that alter offered more values on with
   *   extendChoices(): its methods on those keys narrow its own values.
   *
   * @throws \LogicException
   *   When a method fails a seal-time check, or one that watches nothing
   *   widens what it was given.
   */
  protected function bindRefiners(DataSurfaceBuilderInterface $builder, SurfaceDefinition $definition, object $instance, array $refiners, array $input_keys, array $output_keys, array $subsurfaces = [], ?array $parent = NULL, ?string $module = NULL, array $extended = []): void {
    if ($refiners === []) {
      return;
    }
    $bindings = [];
    $once = [];
    foreach ($refiners as $refiner) {
      static::assertWalled($definition, $refiner, $input_keys, $subsurfaces, $parent);
      static::assertRefinable($definition, $refiner, $input_keys, $output_keys);
      if ($refiner->watched() === []) {
        $once[] = $refiner;
        continue;
      }
      // phpcs:ignore Drupal.Files.LineLength.TooLong
      // SKETCH GAP: the sketch runs each method once its own siblings have values; the engine gates per key, so a key refined by methods watching different siblings waits for the union of them.
      $builder->addRefinement($refiner->key, $refiner->watched());
      $bindings[$refiner->key][] = $refiner;
    }
    $link = new RefinesInputRefiner($instance, $bindings);
    foreach (array_keys($bindings) as $key) {
      // phpcs:ignore Drupal.Files.LineLength.TooLong
      // SKETCH GAP: the sketch lets an alter tighten an owner's key but the engine's contributor chains only see contributed choices; alters' links go in the owner's chain, after the surface's, except on a key the alter offered more values on, where they are its contribution's chain.
      // An alter's method on a key it offered more values on is that
      // contribution's refiner: the engine hands it the alter's values
      // only, and the union of both chains is what is offered.
      $builder->addRefiner((string) $key, $link, in_array($key, $extended, TRUE) ? $module : NULL);
    }
    // A method that watches nothing has nothing to wait for, so its one
    // run is now, and what it returns is what the surface advertises.
    // phpcs:ignore Drupal.Files.LineLength.TooLong
    // SKETCH GAP: the sketch says a refiner with no siblings "runs once" but not when; it runs at build, after the context, held to the narrowing check, and its result is advertised.
    foreach ($once as $refiner) {
      $advertised = $builder->getDefinition($refiner->key);
      assert($advertised !== NULL);
      $refined = $link->invoke($refiner, DataSurface::deepClone($advertised), []);
      Narrowing::assertNarrows($refiner->key, $refiner->describe(), $advertised, $refined);
      DataSurface::carryMetadata($advertised, $refined);
      $builder->setDefinition($refiner->key, $refined);
    }
  }

  /**
   * Refuses a #[RefinesInput] method that reaches across a subsurface.
   *
   * The sketch's wall between a parent and its child, in both
   * directions. A parent's method may neither refine a subsurface key —
   * the child refines its own keys, in its own frame — nor watch one; a
   * child's method may not watch its parent's keys, because a child sees
   * only its context. The one door is the context: a parent hands a
   * child what it needs as identity with withChild().
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $definition
   *   The surface.
   * @param \Drupal\data_surface\SurfaceBuild\RefinerDefinition $refiner
   *   The method.
   * @param string[] $input_keys
   *   The surface's own plain input keys.
   * @param string[] $subsurfaces
   *   The surface's subsurface keys.
   * @param array{class: class-string, id: string, key: string, inputs: string[]}|null $parent
   *   The surface this one is attached inside, or NULL.
   *
   * @throws \LogicException
   *   Naming the method and the key it reached for.
   */
  protected static function assertWalled(SurfaceDefinition $definition, RefinerDefinition $refiner, array $input_keys, array $subsurfaces, ?array $parent): void {
    // phpcs:ignore Drupal.Files.LineLength.TooLong
    // SKETCH GAP: the sketch says a parent cannot refine a child's key and a child cannot read a parent's value; a parent watching a whole subsurface key is not mentioned and is refused too, so the wall holds both ways.
    if (in_array($refiner->key, $subsurfaces, TRUE)) {
      throw new \LogicException(sprintf(
        '%s refines "%s", which is a subsurface of the %s surface. A subsurface is refined by its own #[RefinesInput] methods, in its own frame; a parent cannot refine into it.',
        $refiner->describe(),
        $refiner->key,
        $definition->id,
      ));
    }
    foreach ($refiner->watched() as $watched) {
      if (in_array($watched, $subsurfaces, TRUE)) {
        throw new \LogicException(sprintf(
          '%s watches "%s", which is a subsurface of the %s surface. A parent does not read its child\'s values: what depends on both belongs in the child.',
          $refiner->describe(),
          $watched,
          $definition->id,
        ));
      }
      if ($parent !== NULL && !in_array($watched, $input_keys, TRUE) && in_array($watched, $parent['inputs'], TRUE)) {
        throw new \LogicException(sprintf(
          '%s watches "%s", which is a key of the %s surface this one is attached inside at "%s". A child never reads its parent\'s values; the parent hands it what it needs as identity in the child\'s context, with withChild().',
          $refiner->describe(),
          $watched,
          $parent['id'],
          $parent['key'],
        ));
      }
    }
  }

  /**
   * Refuses a #[RefinesInput] method the shape cannot honor.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $definition
   *   The surface.
   * @param \Drupal\data_surface\SurfaceBuild\RefinerDefinition $refiner
   *   The method.
   * @param string[] $input_keys
   *   The owner's input keys.
   * @param string[] $output_keys
   *   The owner's output keys.
   *
   * @throws \LogicException
   *   Naming the method and what is wrong with it.
   */
  protected static function assertRefinable(SurfaceDefinition $definition, RefinerDefinition $refiner, array $input_keys, array $output_keys): void {
    if (!$refiner->takesDefinition) {
      throw new \LogicException(sprintf(
        '%s refines "%s" but takes no parameters: its first parameter receives that key\'s definition.',
        $refiner->describe(),
        $refiner->key,
      ));
    }
    if (!in_array($refiner->key, $input_keys, TRUE)) {
      throw new \LogicException(in_array($refiner->key, $output_keys, TRUE)
        ? sprintf(
          '%s refines "%s", which is an output of the %s surface. Outputs are never refined: a refinement narrows what may be sent, and nobody sends an output.',
          $refiner->describe(),
          $refiner->key,
          $definition->id,
        )
        : sprintf(
          '%s refines "%s", which the %s surface does not declare as an input.',
          $refiner->describe(),
          $refiner->key,
          $definition->id,
        ));
    }
    if ($refiner->watches !== NULL && $refiner->watches !== $refiner->parameters) {
      throw new \LogicException(sprintf(
        '%s lists watches [%s], but its parameters after the definition are (%s). The watches list names those parameters in order, so a renamed parameter is refused here rather than silently never watched.',
        $refiner->describe(),
        implode(', ', $refiner->watches),
        implode(', ', array_map(static fn (string $name): string => '$' . $name, $refiner->parameters)),
      ));
    }
    foreach ($refiner->watched() as $watched) {
      if (!in_array($watched, $input_keys, TRUE)) {
        throw new \LogicException(sprintf(
          '%s watches "%s", which the %s surface does not declare as an input.',
          $refiner->describe(),
          $watched,
          $definition->id,
        ));
      }
    }
  }

  /**
   * Fills a permission's `%key` placeholders from the known identity.
   *
   * @param string $permission
   *   The permission, as the situation declares it.
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   The context.
   *
   * @return string|null
   *   The permission, or NULL when it names a key the context does not
   *   know as a scalar.
   */
  protected static function resolvePermission(string $permission, SurfaceContext $context): ?string {
    $unknown = FALSE;
    $resolved = preg_replace_callback('/%([A-Za-z0-9_]+)/', static function (array $match) use ($context, &$unknown): string {
      $value = $context->known[$match[1]] ?? NULL;
      if (!is_scalar($value)) {
        $unknown = TRUE;
        return $match[0];
      }
      return (string) $value;
    }, $permission);
    return $unknown ? NULL : $resolved;
  }

  /**
   * Gets an instance of a class discovery named, of the expected kind.
   *
   * @param string $class
   *   The class.
   * @param class-string<T> $interface
   *   What it has to implement.
   *
   * @return T
   *   The instance.
   *
   * @template T of object
   *
   * @throws \LogicException
   *   When it does not implement the interface.
   */
  protected function instance(string $class, string $interface): object {
    $instance = $this->classResolver->getInstanceFromDefinition($class);
    if (!$instance instanceof $interface) {
      throw new \LogicException(sprintf('%s is named as a %s but does not implement it.', $class, $interface));
    }
    return $instance;
  }

}
