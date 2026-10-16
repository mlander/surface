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
use Drupal\data_surface\Refinement\RuntimeRefiner;
use Drupal\data_surface\Refinement\WatchedValueCheckInterface;
use Drupal\data_surface\Surface\AltersOutputsInterface;
use Drupal\data_surface\Surface\HasOutputsInterface;
use Drupal\data_surface\Surface\HasStorageShapeInterface;
use Drupal\data_surface\Surface\SurfaceAccess;
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
 *    defineOutputs(), called on the class, each over a SurfaceShape that
 *    writes straight into a fresh DataSurfaceBuilder. A surface is never
 *    instantiated: its shape and its refiners are static, so the class
 *    may as well be a plugin's, as it is for a plugin that is its own
 *    surface.
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
 *    surface's links before any alter's. The surface's link holds its
 *    class name, its refiners being static; an alter's holds the alter,
 *    an autowired service. An alter's method may refine and watch the
 *    keys that alter mounted, each bound by its dotted path inside the
 *    mount. A method that watches nothing runs once, here, and is held
 *    to the narrowing check. A runtime method (`runtime: TRUE`) is bound
 *    in a link of its own, wrapped in Refinement\RuntimeRefiner, after
 *    its class's static link; one that watches nothing is not run here
 *    but bound ungated, to run on every refinement.
 * 6. The children, each through this same build step in its own frame,
 *    each invoked the way a surface asked on its own is: through one of
 *    its own situations when one applies (invokeChild()), whose access
 *    then decides whether the child is open, locked or left out for the
 *    current account (childAccess()); otherwise in its parent's context.
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
   *   class as the autowired service discovery registered.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The account an access question is about when none is named.
   * @param \Drupal\data_surface\SurfaceBuild\SituationArguments $situationArguments
   *   What turns a route's or a tool's values into a situation's
   *   arguments, an entity's id into the entity among them.
   * @param \Drupal\data_surface\SurfaceBuild\DerivedVariants $derivedVariants
   *   What fills an open slot for the values no declared variant fills.
   * @param \Drupal\data_surface\Refinement\WatchedValueCheckInterface $watchedValueCheck
   *   What a watched value passes before a refiner is handed it, sealed
   *   into every surface and every child this step builds.
   */
  public function __construct(
    protected readonly SurfaceRegistry $registry,
    protected readonly TypedDataManagerInterface $typedDataManager,
    protected readonly ClassResolverInterface $classResolver,
    protected readonly AccountInterface $currentUser,
    protected readonly SituationArguments $situationArguments,
    protected readonly DerivedVariants $derivedVariants,
    protected readonly WatchedValueCheckInterface $watchedValueCheck,
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
   *   The surface class, or its id; a derived surface has only its id.
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   Where it is being asked for.
   * @param array<int, array{class: string, id: string, key: string, inputs: string[]}> $ancestry
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
    $this->registry->getSituations($definition->name());

    $builder = (new DataSurfaceBuilder())->setWatchedValueCheck($this->watchedValueCheck);
    $inputs = new SurfaceShape($builder, $this->typedDataManager);
    $outputs = new SurfaceShape($builder, $this->typedDataManager, TRUE);
    $this->defineShape($definition, $inputs, $outputs);

    // The surface's own refiners first, one link per class they are on:
    // the surface class itself, or the classes a deriver named.
    $links = [];
    foreach ($definition->refiners as $refiner) {
      $links[$refiner->class] ??= [$refiner->class, [], NULL];
      $links[$refiner->class][1][] = $refiner;
    }
    $links = array_values($links);
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
          $definition->class ?? $definition->inputs?->describe(),
          $key,
          in_array($key, $subsurfaces, TRUE) ? ' (it is a subsurface, which holds a map and cannot say which thing this is)' : '',
        ));
      }
    }
    foreach ($ancestry as $ancestor) {
      if ($ancestor['class'] === $definition->name()) {
        throw new \LogicException(sprintf(
          'The %s surface is attached inside itself, through %s: a surface cannot contain itself.',
          $definition->id,
          implode(' -> ', array_map(static fn (array $level): string => $level['id'] . '.' . $level['key'], $ancestry)),
        ));
      }
    }
    $slots = $this->slotsOf($definition, $inputs, $input_keys);

    $this->applyContext($builder, $definition, $context, $input_keys, $subsurfaces);
    // What each module mounted, so an alter's method may watch its own
    // module's keys and is told so when it names another's.
    $mounted_by = [];
    foreach ($additions as $contributor => $shapes) {
      foreach (isset($shapes[0]) ? $shapes[0]->keys() : [] as $key) {
        $mounted_by[$key][] = (string) $contributor;
      }
    }
    foreach ($links as [$instance, $refiners, $module]) {
      $extended = $module !== NULL && isset($additions[$module][0]) ? $additions[$module][0]->extended() : [];
      $mounted = $module !== NULL && isset($additions[$module][0]) ? $additions[$module][0]->keys() : [];
      $this->bindRefiners($builder, $definition, $instance, $refiners, $input_keys, $outputs->keys(), $subsurfaces, $ancestry === [] ? NULL : $ancestry[count($ancestry) - 1], $module, $extended, $mounted, $module === NULL ? [] : $mounted_by);
    }

    // The children, each through this same build step in its own frame:
    // its own shape, its own alters, its own refiners, and the context
    // it is invoked in, which its own situation's access may then narrow
    // to locked or to nothing at all.
    $frame = ['class' => $definition->name(), 'id' => $definition->id, 'key' => '', 'inputs' => $input_keys];
    $permission = $this->registry->getSituations($definition->name())[$context->operation]->permission ?? NULL;
    $frame['permission'] = $permission === NULL ? NULL : static::resolvePermission($permission, $context);
    foreach ($inputs->attachments() as $key => $child) {
      [$attachment, $access] = $this->buildChild($child, $context, $key, $frame, $ancestry);
      if ($access !== NULL) {
        // What the child's access answer read is what this surface, and
        // everything emitted from it, varies by.
        $builder->addCacheableDependency($access);
      }
      if ($attachment === NULL) {
        // Decision: see docs/decisions.md#omitted-is-omitted-both-ways.
        $builder->omit($key);
        continue;
      }
      $builder->attach($key, $attachment);
      if ($access !== NULL && $access->isLocked()) {
        $builder->lock($key);
      }
    }
    foreach ($slots as $key => ['by' => $by, 'children' => $children]) {
      $variants = [];
      $withheld = [];
      foreach ($children as $value => $child) {
        [$attachment, $access] = $this->buildChild($child, $context, $key, $frame, $ancestry);
        if ($access !== NULL) {
          $builder->addCacheableDependency($access);
        }
        // Decision: see docs/decisions.md#a-variant-is-open-or-not-offered.
        if ($attachment === NULL || ($access !== NULL && !$access->isOpen())) {
          $withheld[] = (string) $value;
          continue;
        }
        $variants[(string) $value] = $attachment;
      }
      $deriver = $this->derivedVariants->for($definition->name(), $key);
      if ($deriver !== NULL) {
        // A withheld variant is still declared: nothing derives in its
        // place.
        foreach ($deriver->variants([...array_keys($variants), ...$withheld]) as $value => $definitions) {
          $variants[(string) $value] ??= new SurfaceAttachment((new DataSurfaceBuilder($definitions))->setWatchedValueCheck($this->watchedValueCheck)->seal());
        }
      }
      $builder->attachBy($key, $by, $variants);
    }

    return $builder->seal();
  }

  /**
   * Builds one child at a key, invoked the way invokeChild() resolves.
   *
   * @param string $child
   *   The child surface's name: its class, or a derived surface's id.
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   The parent's context.
   * @param string $key
   *   The key the child sits at.
   * @param array{class: class-string, id: string, key: string, inputs: string[], permission?: string|null} $frame
   *   The parent, for messages and the cycle check, with the permission
   *   its own situation asks, resolved.
   * @param array $ancestry
   *   The parent's own ancestry.
   *
   * @return array{0: \Drupal\data_surface\SurfaceAttachment|null, 1: \Drupal\data_surface\Surface\SurfaceAccess|null}
   *   The sealed child, or NULL when its access omits it; and that access
   *   answer, or NULL for a child invoked through no situation of its own,
   *   whose access is asked only by access(), as it always was.
   */
  protected function buildChild(string $child, SurfaceContext $context, string $key, array $frame, array $ancestry): array {
    $definition = $this->registry->getDefinition($child);
    [$child_context, $situation] = $this->invokeChild($definition, $context, $key, $frame['id']);
    $access = $situation === NULL ? NULL : $this->childAccess($definition, $situation, $child_context, $this->currentUser, $frame['permission'] ?? NULL);
    if ($access !== NULL && $access->isOmitted()) {
      return [NULL, $access];
    }
    $frame['key'] = $key;
    return [
      new SurfaceAttachment(
        $this->buildSurface($definition->name(), $child_context, [...$ancestry, $frame]),
        $definition->name(),
        $definition->id,
      ),
      $access,
    ];
  }

  /**
   * Resolves how a child at a key is invoked, the same way at every use.
   *
   * In this order, the first that applies:
   * 1. The parent's context names one of the child's situations, by id,
   *    with withChild($key, situation: ...); or it hands the child a
   *    context whole with withChild($key, $context), which is taken as it
   *    is and invokes no situation of the child's (so no access of its
   *    own), exactly as before.
   * 2. The child's situation whose id is the parent's operation.
   * 3. The child's default situation, #[Situation(default: TRUE)].
   * 4. None: the child sees its parent's operation, `creates` and known
   *    identity, exactly as a child always did (childContext()).
   *
   * A situation invoked here is called with the arguments it declares,
   * read by name from the identity the parent handed it and then from
   * the parent's known identity; the context it returns then knows that
   * identity too, its own known keys winning on a clash. A context handed
   * whole is taken as it is. A situation found by operation or by default
   * that cannot be given an argument it needs is passed over, for the
   * next step; one the parent named is refused.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $child
   *   The child surface.
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   The parent's context.
   * @param string $key
   *   The key the child sits at.
   * @param string $parent
   *   The parent's id, for messages.
   *
   * @return array{0: \Drupal\data_surface\Surface\SurfaceContext, 1: \Drupal\data_surface\SurfaceBuild\SituationDefinition|null}
   *   The child's context, and the situation of its own it was invoked
   *   through, or NULL when it inherits.
   *
   * @throws \LogicException
   *   When the parent names a situation the child does not declare, or
   *   one that cannot be given an argument it needs.
   */
  protected function invokeChild(SurfaceDefinition $child, SurfaceContext $context, string $key, string $parent): array {
    // Decision: see docs/decisions.md#one-invocation-model-for-both-levels.
    $situations = $this->registry->getSituations($child->name());
    $named = $context->childSituation($key);
    if ($named !== NULL) {
      $situation = $situations[$named['situation']] ?? throw new \LogicException(sprintf(
        'The %s surface\'s "%s" context names the "%s" situation for its "%s" child, which the %s surface does not declare; it declares %s.',
        $parent,
        $context->operation,
        $named['situation'],
        $key,
        $child->id,
        $situations === [] ? 'none' : implode(', ', array_keys($situations)),
      ));
      $own = $this->childSituationContext($child, $situation, $context, $named['known'], $key, $parent);
      assert($own !== NULL);
      return [$own, $situation];
    }
    $handed = $context->forChild($key);
    if ($handed !== $context) {
      // A context handed whole was built by the parent, and is taken as
      // it is, with no access of the child's own: what every withChild()
      // written before situations could be named always meant.
      return [$handed, NULL];
    }
    // The parent's operation is a hint, and so is the default: either is
    // passed over when the parent knows too little to invoke it, as a
    // context that knows nothing yet, which a static tool definition or
    // a catalogue builds, does.
    $candidates = isset($situations[$context->operation]) ? [$situations[$context->operation]] : [];
    foreach ($situations as $candidate) {
      if ($candidate->default && $candidate->id !== $context->operation) {
        $candidates[] = $candidate;
      }
    }
    foreach ($candidates as $situation) {
      $own = $this->childSituationContext($child, $situation, $context, [], $key, $parent, FALSE);
      if ($own !== NULL) {
        return [$own, $situation];
      }
    }
    return [static::childContext($context, $key), NULL];
  }

  /**
   * Invokes one of a child's situations for its parent.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $child
   *   The child surface.
   * @param \Drupal\data_surface\SurfaceBuild\SituationDefinition $situation
   *   The child's situation.
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   The parent's context.
   * @param array<string, mixed> $handed
   *   The identity the parent handed the child with the situation's name.
   * @param string $key
   *   The key the child sits at, for messages.
   * @param string $parent
   *   The parent's id, for messages.
   * @param bool $named
   *   Whether the parent named the situation, so that one that cannot be
   *   invoked is a mistake rather than a step to pass over.
   *
   * @return \Drupal\data_surface\Surface\SurfaceContext|null
   *   The child's context, knowing what it was handed and what its parent
   *   knows, its own known keys first; NULL for a situation the parent did
   *   not name that cannot be given what it needs.
   *
   * @throws \LogicException
   *   When a named situation needs an argument nothing handed it.
   */
  protected function childSituationContext(SurfaceDefinition $child, SituationDefinition $situation, SurfaceContext $context, array $handed, string $key, string $parent, bool $named = TRUE): ?SurfaceContext {
    $identity = $handed + $context->known;
    try {
      $own = $this->situation($child->name(), $situation->id, $identity);
    }
    catch (\InvalidArgumentException $e) {
      if (!$named) {
        return NULL;
      }
      throw new \LogicException(sprintf(
        'The %s surface invokes the "%s" situation of the %s surface at its "%s" key, from its "%s" context, and cannot give it what it needs: %s Hand it with withChild(\'%s\', situation: \'%s\', known: [...]).',
        $parent,
        $situation->id,
        $child->id,
        $key,
        $context->operation,
        $e->getMessage(),
        $key,
        $situation->id,
      ), 0, $e);
    }
    return $own->withKnown(array_diff_key($identity, $own->known));
  }

  /**
   * Answers what an account may do with a child invoked through a situation.
   *
   * The child's situation's permission, then its surface's access class,
   * in the child's own context, combined so the narrower answer stands. A
   * permission the account lacks omits the child; one whose placeholder
   * the context cannot fill says nothing, the parent's access having
   * gated the caller already; core's answer from the class is read as
   * SurfaceAccess::fromAnswer() reads it.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $child
   *   The child surface.
   * @param \Drupal\data_surface\SurfaceBuild\SituationDefinition $situation
   *   The situation it was invoked through.
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   The child's context.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   * @param string|null $gate
   *   The permission the parent's own situation asks, resolved: a child
   *   asking the same one narrows nothing, its parent's gate having asked
   *   it already.
   *
   * @return \Drupal\data_surface\Surface\SurfaceAccess
   *   Open, locked or omitted, with what the answer read.
   */
  protected function childAccess(SurfaceDefinition $child, SituationDefinition $situation, SurfaceContext $context, AccountInterface $account, ?string $gate = NULL): SurfaceAccess {
    // Decision: see docs/decisions.md#access-has-three-answers.
    $access = SurfaceAccess::open();
    $resolved = $situation->permission === NULL ? NULL : static::resolvePermission($situation->permission, $context);
    // Decision: see docs/decisions.md#a-permission-a-child-cannot-name.
    if ($resolved !== NULL && $resolved !== $gate) {
      $result = AccessResult::allowedIfHasPermission($account, $resolved);
      if (!$result->isAllowed()) {
        return SurfaceAccess::omitted(sprintf('The "%s" situation needs the "%s" permission.', $situation->id, $resolved))->addCacheableDependency($result);
      }
      $access->addCacheableDependency($result);
    }
    if ($child->access === NULL) {
      return $access;
    }
    return $access->andIf(SurfaceAccess::fromAnswer($this->instance($child->access, SurfaceAccessInterface::class)->access($context, $account)));
  }

  /**
   * Gets the context a child at a key sees when it invokes no situation.
   *
   * Its own, when the parent's context hands it one with withChild(),
   * as a situation does to tell a child the parent's identity. Otherwise
   * the parent's operation, whether it creates, and the identity it
   * knows, which is what lets a child's target load by the same thing
   * its parent's does. A child with situations of its own is resolved by
   * invokeChild() first, which falls back to this.
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
    // Decision: see docs/decisions.md#child-context.
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
      // Decision: see docs/decisions.md#a-slot-nothing-fills.
      $slots[$key] = [
        'by' => $by,
        'children' => $this->registry->getVariants($definition->name(), $key),
      ];
    }
    return $slots;
  }

  /**
   * {@inheritdoc}
   */
  public function situation(string $surface, string $situation, array $arguments = []): SurfaceContext {
    $found = $this->registry->getSituation($surface, $situation);
    $resolved = $this->situationArguments->resolve($found, $arguments);
    // A derived situation is a method its deriver named, handed the
    // arguments by name; a #[Situation] method takes them as its own.
    $context = $found->context !== NULL
      ? $found->context->call($this->classResolver, $resolved)
      : (new \ReflectionMethod($found->class, $found->method))->invokeArgs(NULL, $resolved);
    if (!$context instanceof SurfaceContext) {
      throw new \LogicException(sprintf(
        'The "%s" situation, %s, returned %s; a situation returns a %s.',
        $found->id,
        $found->describe(),
        get_debug_type($context),
        SurfaceContext::class,
      ));
    }
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
    // Decision: see docs/decisions.md#children-may-refuse.
    $answers = $this->childAnswers($context, $account, $this->build($definition->name(), $context), $definition->id);
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
    // Decision: see docs/decisions.md#no-declared-situation-no-permission-tier.
    $permission = $this->registry->getSituations($definition->name())[$context->operation]->permission ?? NULL;
    $result = AccessResult::neutral();
    if ($permission !== NULL) {
      $resolved = static::resolvePermission($permission, $context);
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
    $answer = static::atTheTop($this->instance($definition->access, SurfaceAccessInterface::class)->access($context, $account));
    return $permission === NULL ? $answer : $result->andIf($answer);
  }

  /**
   * Reads an access class's answer the way a surface asked on its own is.
   *
   * Core's answer is the answer. Of the three SurfaceAccess answers, open
   * is allowed and omitted is forbidden; locked is forbidden too, because
   * a whole surface shown and not saved is not built at the top level
   * yet, and refusing is the reading that writes nothing.
   *
   * @param \Drupal\Core\Access\AccessResultInterface|\Drupal\data_surface\Surface\SurfaceAccess $answer
   *   The access class's answer.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Core's answer, with the cacheability it read.
   */
  protected static function atTheTop(AccessResultInterface|SurfaceAccess $answer): AccessResultInterface {
    if ($answer instanceof AccessResultInterface) {
      return $answer;
    }
    // Decision: see docs/decisions.md#locked-at-the-top.
    $result = $answer->isOpen()
      ? AccessResult::allowed()
      : AccessResult::forbidden($answer->getReason() ?? sprintf('The surface\'s access answered %s.', $answer->answer()));
    return $result->addCacheableDependency($answer);
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
      if ($other->name() !== $definition->name() && $other->access !== NULL) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Asks every child a context resolves that has an access class.
   *
   * An attached child, and a slot's variant when its deciding key is
   * locked, each in the context the parent's hands it, recursively. A
   * child invoked through a situation of its own is not asked here: its
   * access decided its keys when it was built.
   *
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   The parent's context.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   * @param \Drupal\data_surface\DataSurfaceInterface $built
   *   The parent, built in that context.
   * @param string $parent
   *   The parent's id, for messages.
   *
   * @return \Drupal\Core\Access\AccessResultInterface[]
   *   The children's answers, outermost first.
   */
  protected function childAnswers(SurfaceContext $context, AccountInterface $account, DataSurfaceInterface $built, string $parent): array {
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
      [$child_context, $situation] = $this->invokeChild($child, $context, (string) $key, $parent);
      // A child invoked through a situation of its own was answered for
      // when it was built: its access shaped its keys, and refuses
      // nothing above it.
      if ($situation === NULL && $child->access !== NULL) {
        $answers[] = static::atTheTop($this->instance($child->access, SurfaceAccessInterface::class)->access($child_context, $account));
      }
      array_push($answers, ...$this->childAnswers($child_context, $account, $attachment->child, $child->id));
    }
    return $answers;
  }

  /**
   * {@inheritdoc}
   */
  public function defaults(string $surface): array {
    $builder = new DataSurfaceBuilder();
    $this->defineShape($this->registry->getDefinition($surface), new SurfaceShape($builder, $this->typedDataManager));
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
    return $this->targetFor($definition, $context, $built ?? $this->build($definition->name(), $context));
  }

  /**
   * Composes a surface's target with its children's, along the tree.
   *
   * Each subsurface whose class names a target of its own is routed to
   * it, in the context it is invoked in (invokeChild()), and recursively
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
          $routes[(string) $key][(string) $id] = $this->targetFor($child, $this->invokeChild($child, $context, (string) $key, $definition->id)[0], $attachment->child);
        }
      }
    }
    return new SurfaceTargetAdapter(
      $this->instance((string) $definition->target, SurfaceTargetInterface::class),
      $context,
      $routes,
      $definition->identity,
      $this->omittedKeys($definition, $built),
    );
  }

  /**
   * Lists the children the build left out, which the parent stores.
   *
   * The keys the owner's shape attaches a child at that the built surface
   * does not carry: only a child's access omits a key. The target keeps
   * what it holds under each, since the caller could not see it.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $definition
   *   The surface.
   * @param \Drupal\data_surface\DataSurfaceInterface $built
   *   The surface as built.
   *
   * @return string[]
   *   The omitted keys.
   */
  protected function omittedKeys(SurfaceDefinition $definition, DataSurfaceInterface $built): array {
    $shape = new SurfaceShape(new DataSurfaceBuilder(), $this->typedDataManager);
    $this->defineShape($definition, $shape);
    return array_values(array_filter(
      array_keys($shape->attachments()),
      static fn (string $key): bool => $built->getDefinitions()->entry($key) === NULL,
    ));
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
      if (!$context->creates) {
        throw new \LogicException(sprintf(
          'The "%s" context carries starting values for %s but does not create. Starting values are where a new thing begins; a thing that exists begins from what its target loads.',
          $context->operation,
          implode(', ', array_keys($context->starting)),
        ));
      }
      // A starting value is the value a caller sees before choosing, and
      // may replace: exactly what a declared default is to the engine.
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

    // Decision: see docs/decisions.md#known-keys-that-are-not-identity.
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
   * @param object|class-string $instance
   *   The surface class, whose methods are static, or the alter instance
   *   the methods are called on.
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
   * @param string[] $mounted
   *   The keys that alter added, which its methods may refine and watch
   *   too.
   * @param array<string, string[]> $mountedBy
   *   For an alter, every key any alter of the surface mounted, mapped
   *   to the modules that mounted it; empty for the surface itself.
   *
   * @throws \LogicException
   *   When a method fails a seal-time check, or one that watches nothing
   *   widens what it was given.
   */
  protected function bindRefiners(DataSurfaceBuilderInterface $builder, SurfaceDefinition $definition, object|string $instance, array $refiners, array $input_keys, array $output_keys, array $subsurfaces = [], ?array $parent = NULL, ?string $module = NULL, array $extended = [], array $mounted = [], array $mountedBy = []): void {
    if ($refiners === []) {
      return;
    }
    // Decision: see docs/decisions.md#static-by-default-runtime-by-declaration.
    $bindings = ['static' => [], 'runtime' => [], 'ungated' => []];
    $once = [];
    $paths = [];
    foreach ($refiners as $refiner) {
      // Decision: see docs/decisions.md#an-alter-refines-its-own-mounted-key.
      $own = $module !== NULL && in_array($refiner->key, $mounted, TRUE) && !in_array($refiner->key, $input_keys, TRUE);
      static::assertStaticOnSurface($definition, $refiner, $module);
      static::assertWalled($definition, $refiner, $input_keys, $subsurfaces, $parent);
      static::assertNotWatchingForeignMount($definition, $refiner, $input_keys, $mounted, $mountedBy);
      $refinable = $own ? [...$input_keys, $refiner->key] : $input_keys;
      static::assertRefinable($definition, $refiner, $refinable, $output_keys, [...$input_keys, ...$mounted]);
      // Decision: see docs/decisions.md#an-alter-watches-its-own-mounted-key.
      $watched = static::mountedWatches($definition, $refiner, $input_keys, $module, $mounted);
      $paths += $watched;
      $key = $own ? RefinesInputRefiner::MOUNT : $refiner->key;
      if ($refiner->watched() === []) {
        // A runtime refiner that watches nothing still cannot be run
        // ahead of time: it runs on every refinement instead.
        if ($refiner->runtime) {
          $bindings['ungated'][$key][] = $refiner;
          continue;
        }
        $once[] = [$refiner, $own];
        continue;
      }
      // Decision: see docs/decisions.md#gating-per-key.
      $builder->addRefinement(
        $own ? RefinesInputRefiner::path((string) $module, $refiner->key) : $refiner->key,
        array_map(static fn (string $name): string => $watched[$name] ?? $name, $refiner->watched()),
        $refiner->runtime,
      );
      $bindings[$refiner->runtime ? 'runtime' : 'static'][$key][] = $refiner;
    }
    // One link per kind, so the engine can leave a runtime refiner out of
    // what is stated ahead of time; on one key, the static methods of a
    // class run before its runtime ones.
    $link = new RefinesInputRefiner($instance, $bindings['static'], $module, $paths);
    $runtime = new RefinesInputRefiner($instance, $bindings['runtime'], $module, $paths);
    $ungated = new RefinesInputRefiner($instance, $bindings['ungated'], $module, $paths);
    $links = [
      [$link, $bindings['static']],
      [new RuntimeRefiner($runtime), $bindings['runtime']],
      [new RuntimeRefiner($ungated, gated: FALSE), $bindings['ungated']],
    ];
    foreach ($links as [$kind, $bound]) {
      foreach (array_keys($bound) as $key) {
        // An alter's method on a key it offered more values on is that
        // contribution's refiner: the engine hands it the alter's values
        // only, and the union of both chains is what is offered.
        $builder->addRefiner((string) $key, $kind, in_array($key, $extended, TRUE) ? $module : NULL);
      }
    }
    // A method that watches nothing has nothing to wait for, so its one
    // run is now, and what it returns is what the surface advertises.
    foreach ($once as [$refiner, $own]) {
      $advertised = $own
        ? $builder->getThirdPartyDefinition((string) $module, $refiner->key)
        : $builder->getDefinition($refiner->key);
      assert($advertised !== NULL);
      $refined = $link->invoke($refiner, DataSurface::deepClone($advertised), []);
      Narrowing::assertNarrows($refiner->key, $refiner->describe(), $advertised, $refined);
      DataSurface::carryMetadata($advertised, $refined);
      if ($own) {
        $builder->setThirdPartyDefinition((string) $module, $refiner->key, $refined);
        continue;
      }
      $builder->setDefinition($refiner->key, $refined);
    }
  }

  /**
   * Refuses a surface's #[RefinesInput] method that is not static.
   *
   * A surface is never instantiated, so its refiner has no instance to
   * run on; an alter's may be either, since an alter is a service.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $definition
   *   The surface.
   * @param \Drupal\data_surface\SurfaceBuild\RefinerDefinition $refiner
   *   The method.
   * @param string|null $module
   *   The module of an alter, or NULL for the surface itself.
   *
   * @throws \LogicException
   *   Naming the method.
   */
  protected static function assertStaticOnSurface(SurfaceDefinition $definition, RefinerDefinition $refiner, ?string $module): void {
    if ($module === NULL && !$refiner->isStatic) {
      throw new \LogicException(sprintf(
        '%s is a #[RefinesInput] method of the %s surface and is not static. A surface is never instantiated, so its refiners are static, like its shape; an alter\'s refiner may be an instance method, because an alter is a service.',
        $refiner->describe(),
        $definition->id,
      ));
    }
  }

  /**
   * Refuses an alter's method that watches another module's mounted key.
   *
   * An alter may watch the keys it mounted itself, by the names it added
   * them under; what another module mounted is that module's, and an
   * alter does not read it, as a child does not read its parent.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $definition
   *   The surface.
   * @param \Drupal\data_surface\SurfaceBuild\RefinerDefinition $refiner
   *   The method.
   * @param string[] $input_keys
   *   The owner's input keys.
   * @param string[] $mounted
   *   The keys the method's alter added.
   * @param array<string, string[]> $mountedBy
   *   Every mounted key, mapped to the modules that mounted it; empty
   *   for the surface's own methods.
   *
   * @throws \LogicException
   *   Naming the method, the key and the module that mounted it.
   */
  protected static function assertNotWatchingForeignMount(SurfaceDefinition $definition, RefinerDefinition $refiner, array $input_keys, array $mounted, array $mountedBy): void {
    foreach ($refiner->watched() as $watched) {
      if (isset($mountedBy[$watched]) && !in_array($watched, $input_keys, TRUE) && !in_array($watched, $mounted, TRUE)) {
        throw new \LogicException(sprintf(
          '%s watches "%s", a key the %s module mounted on the %s surface. An alter may watch the keys it mounted itself and the owner\'s, never another module\'s.',
          $refiner->describe(),
          $watched,
          implode(', ', $mountedBy[$watched]),
          $definition->id,
        ));
      }
    }
  }

  /**
   * Resolves the keys an alter's method watches that the alter mounted.
   *
   * Each is named as the alter added it and lives at its dotted path
   * inside the mount, which is where it is read from. It is handed as it
   * stands, NULL while unanswered, because it never holds the key it
   * refines back, so the parameter receiving it has to accept NULL.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $definition
   *   The surface.
   * @param \Drupal\data_surface\SurfaceBuild\RefinerDefinition $refiner
   *   The method.
   * @param string[] $input_keys
   *   The owner's input keys, which win over a mounted key of one name.
   * @param string|null $module
   *   The module of an alter, or NULL for the surface itself.
   * @param string[] $mounted
   *   The keys that alter added.
   *
   * @return array<string, string>
   *   The watched keys the alter mounted, each mapped to its dotted path.
   *
   * @throws \LogicException
   *   When the parameter receiving one cannot take NULL.
   */
  protected static function mountedWatches(SurfaceDefinition $definition, RefinerDefinition $refiner, array $input_keys, ?string $module, array $mounted): array {
    $paths = [];
    foreach ($refiner->watched() as $position => $watched) {
      if ($module === NULL || in_array($watched, $input_keys, TRUE) || !in_array($watched, $mounted, TRUE)) {
        continue;
      }
      $parameter = (new \ReflectionMethod($refiner->class, $refiner->method))->getParameters()[$position + 1] ?? NULL;
      if ($parameter !== NULL && !$parameter->allowsNull()) {
        throw new \LogicException(sprintf(
          '%s watches "%s", a key its alter mounted on the %s surface, which is handed as it stands and is NULL until answered: declare $%s nullable.',
          $refiner->describe(),
          $watched,
          $definition->id,
          $parameter->getName(),
        ));
      }
      $paths[$watched] = RefinesInputRefiner::path($module, $watched);
    }
    return $paths;
  }

  /**
   * Refuses a #[RefinesInput] method that reaches across a subsurface.
   *
   * The pattern's wall between a parent and its child, in both
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
   * @param string[]|null $watchable
   *   The keys the method may watch, when more than the input keys: an
   *   alter's adds the keys it mounted.
   *
   * @throws \LogicException
   *   Naming the method and what is wrong with it.
   */
  protected static function assertRefinable(SurfaceDefinition $definition, RefinerDefinition $refiner, array $input_keys, array $output_keys, ?array $watchable = NULL): void {
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
      if (!in_array($watched, $watchable ?? $input_keys, TRUE)) {
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
   * Fills a surface's shape, from its class or from what derived it.
   *
   * A class-based surface's defineInputs() and defineOutputs() are
   * called on the class; a derived surface's shape methods are the ones
   * its deriver named, handed the shape first.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $definition
   *   The surface.
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceShape $inputs
   *   The input shape.
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceShape|null $outputs
   *   The output shape, or NULL when only the inputs are wanted.
   */
  protected function defineShape(SurfaceDefinition $definition, SurfaceShape $inputs, ?SurfaceShape $outputs = NULL): void {
    if ($definition->isDerived()) {
      // Decision: see docs/decisions.md#a-derived-surface-has-no-class.
      $definition->inputs?->call($this->classResolver, $inputs);
      if ($outputs !== NULL) {
        $definition->outputs?->call($this->classResolver, $outputs);
      }
      return;
    }
    // Decision: see docs/decisions.md#a-surface-is-static.
    $owner = static::surfaceClass($definition);
    $owner::defineInputs($inputs);
    if ($outputs !== NULL && is_subclass_of($owner, HasOutputsInterface::class)) {
      $owner::defineOutputs($outputs);
    }
  }

  /**
   * Gets the class a surface's static methods are called on.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $definition
   *   The surface.
   *
   * @return class-string<\Drupal\data_surface\Surface\SurfaceInterface>
   *   Its class, which discovery held to SurfaceInterface.
   */
  protected static function surfaceClass(SurfaceDefinition $definition): string {
    $class = $definition->class;
    assert(is_subclass_of($class, SurfaceInterface::class));
    return $class;
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
