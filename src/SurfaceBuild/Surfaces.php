<?php

declare(strict_types=1);

namespace Drupal\data_surface\SurfaceBuild;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\data_surface\DataSurface;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\data_surface\DataSurfaceFactoryInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;
use Drupal\data_surface\Refinement\Narrowing;
use Drupal\data_surface\Surface\AltersOutputsInterface;
use Drupal\data_surface\Surface\HasOutputsInterface;
use Drupal\data_surface\Surface\SurfaceAccessInterface;
use Drupal\data_surface\Surface\SurfaceAlterInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceInterface;
use Drupal\data_surface\Surface\SurfaceTargetInterface;

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
 * 6. The seal, through the engine's factory, so the build event fires
 *    and subscribers written in the old spelling still contribute.
 *
 * The engine's builder is the whole state of a build. Nothing is cached
 * across builds, because a surface describes live site state, the way
 * every host in the old spelling builds its surface when asked.
 */
final class Surfaces implements SurfacesInterface {

  /**
   * The host id prefix for a surface built for no host in particular.
   */
  public const HOST_PREFIX = 'surface:';

  /**
   * Constructs the build step.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceRegistry $registry
   *   What discovery found.
   * @param \Drupal\data_surface\DataSurfaceFactoryInterface $factory
   *   The engine's factory, which dispatches the build event and seals.
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The typed data manager, for the definitions add() creates.
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface $classResolver
   *   The class resolver, which returns an alter, a target or an access
   *   class as the autowired service discovery registered, and makes a
   *   surface with no constructor.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The account an access question is about when none is named.
   */
  public function __construct(
    protected readonly SurfaceRegistry $registry,
    protected readonly DataSurfaceFactoryInterface $factory,
    protected readonly TypedDataManagerInterface $typedDataManager,
    protected readonly ClassResolverInterface $classResolver,
    protected readonly AccountInterface $currentUser,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function build(string $surface, SurfaceContext $context, ?string $host_class = NULL, ?string $host_id = NULL): DataSurfaceInterface {
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

    $links = [[$owner, $definition->refiners]];
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
      $links[] = [$instance, $alter->refiners];
    }

    $input_keys = $inputs->keys();
    foreach ($definition->identity as $key) {
      if (!in_array($key, $input_keys, TRUE)) {
        throw new \LogicException(sprintf(
          'The %s surface (%s) names "%s" as an identity key in #[Surface(identity:)], but its shape never declares that input.',
          $definition->id,
          $definition->class,
          $key,
        ));
      }
    }

    $this->applyContext($builder, $definition, $context, $input_keys);
    foreach ($links as [$instance, $refiners]) {
      $this->bindRefiners($builder, $definition, $instance, $refiners, $input_keys, $outputs->keys());
    }

    // phpcs:ignore Drupal.Files.LineLength.TooLong
    // SKETCH GAP: the sketch has no build event; it still fires here, after the context and refiners, so old-spelling subscribers run last and see locks and situation constraints already applied.
    // phpcs:ignore Drupal.Files.LineLength.TooLong
    // SKETCH GAP: the sketch's build() takes a surface and a context only; an optional host class and host id are added so old-spelling subscribers matching a host (block:<id>, a block class) still match.
    return $this->factory->build(
      $builder,
      $host_class ?? $definition->class,
      $host_id ?? self::HOST_PREFIX . $definition->id,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function situation(string $surface, string $situation, array $arguments = []): SurfaceContext {
    $found = $this->registry->getSituation($surface, $situation);
    $context = (new \ReflectionMethod($found->class, $found->method))->invokeArgs(NULL, $arguments);
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
   * {@inheritdoc}
   */
  public function target(string $surface, SurfaceContext $context): DataSurfaceTargetInterface {
    $definition = $this->registry->getDefinition($surface);
    if ($definition->target === NULL) {
      throw new \LogicException(sprintf(
        'The %s surface names no target in #[Surface(target:)]: its host supplies one, the way the block host stores a plugin\'s configuration.',
        $definition->id,
      ));
    }
    return new SurfaceTargetAdapter($this->instance($definition->target, SurfaceTargetInterface::class), $context);
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
   *
   * @throws \LogicException
   *   When the context names a key the shape does not declare, carries
   *   starting values without creating, or widens a key.
   */
  protected function applyContext(DataSurfaceBuilderInterface $builder, SurfaceDefinition $definition, SurfaceContext $context, array $input_keys): void {
    $assert_input = static function (string $key, string $what) use ($definition, $context, $input_keys): void {
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
   *
   * @throws \LogicException
   *   When a method fails a seal-time check, or one that watches nothing
   *   widens what it was given.
   */
  protected function bindRefiners(DataSurfaceBuilderInterface $builder, SurfaceDefinition $definition, object $instance, array $refiners, array $input_keys, array $output_keys): void {
    if ($refiners === []) {
      return;
    }
    $bindings = [];
    $once = [];
    foreach ($refiners as $refiner) {
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
      // SKETCH GAP: the sketch lets an alter tighten an owner's key but the engine's contributor chains only see contributed choices; alters' links go in the owner's chain, after the surface's.
      $builder->addRefiner((string) $key, $link);
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
