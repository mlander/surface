<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\data_surface\DataSurface;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceStorageInterface;
use Drupal\data_surface\Refinement\ChoiceSet;
use Drupal\data_surface\Refinement\Condition;
use Drupal\data_surface\Refinement\DeclaredRefiner;
use Drupal\data_surface\Refinement\DoesNotApply;
use Drupal\data_surface\Refinement\Narrowing;
use Drupal\data_surface\Refinement\Rule;
use Drupal\data_surface\Refinement\RuntimeRefiner;
use Drupal\data_surface\Refinement\WatchedValueCheckInterface;
use Drupal\data_surface\Resource\AltersOutputsInterface;
use Drupal\data_surface\Resource\HasOutputsInterface;
use Drupal\data_surface\Resource\HasStorageLayoutInterface;
use Drupal\data_surface\Resource\AccessAnswer;
use Drupal\data_surface\Resource\OperationAccessInterface;
use Drupal\data_surface\Resource\AltersInputsInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\DefinesInputsInterface;
use Drupal\data_surface\Resource\ResourceStorageInterface;
use Drupal\data_surface\Attachment;
use Drupal\data_surface\Entry;
use Drupal\data_surface\Collection;

/**
 * The build step, over the engine's builder and factory.
 *
 * In order, for one resource or structure and one context (both kinds
 * are built alike; only the storage step tells them apart):
 *
 * 1. The owner's shape. defineInputs() and, for a class with outputs,
 *    defineOutputs(), called on the class, each over a ResourceBlueprint that
 *    writes straight into a fresh DataSurfaceBuilder. Neither kind is
 *    ever instantiated: its declarations and its refiners are static, so
 *    the class may as well be a plugin's, as it is for a plugin that is its
 *    own structure.
 * 2. The alters #[Alters] names for this class, in discovery order,
 *    skipping one whose operations leave this context's id out:
 *    alterInputs() and alterOutputs() over a ResourceBlueprintAdditions,
 *    which mounts what they add under the alter's module.
 * 3. The seal-time checks on the shape: every identity key is a declared
 *    input, and no operation id is provided twice.
 * 4. The context. Starting values become the declared defaults, when
 *    the context creates; each constraint is added to its key and held
 *    to the narrowing check; each identity key the context knows becomes
 *    that key's default and is locked, which is the engine's lock: the
 *    value fixed to the declared default.
 * 5. The refiners. Every #[RefinesInput] method is checked against the
 *    shape, then bound: the keys it watches become the engine's
 *    refinement edges with addRefinement(), and its class becomes one
 *    RefinesInputRefiner link in the owner's chain of that key, the
 *    owner's links before any alter's. The owner's link holds its
 *    class name, its refiners being static; an alter's holds the alter,
 *    an autowired service. An alter's method may refine and watch the
 *    keys that alter mounted, each bound by its dotted path inside the
 *    mount. A method that watches nothing runs once, here, and is held
 *    to the narrowing check. A runtime method (`runtime: TRUE`) is bound
 *    in a link of its own, wrapped in Refinement\RuntimeRefiner, after
 *    its class's static link; one that watches nothing is not run here
 *    but bound ungated, to run on every refinement. Only a runtime
 *    method may be an instance method: a static refiner is a static
 *    method, so it cannot hold the services its class was built with.
 * 6. The children, each through this same build step in its own frame,
 *    each invoked the way one asked on its own is: through one of
 *    its own operations when one applies (invokeChild()), otherwise in
 *    the context its parent's hands it or in its parent's own. Its access
 *    then decides whether the child is open, locked or left out for the
 *    current account (childAccess()): its access class however it was
 *    invoked, and the permission of an operation the build resolved.
 *    A collection's child is built the same way, once for the list, and
 *    its access answers once for the list.
 * 7. The seal.
 *
 * The engine's builder is the whole state of a build. Nothing is cached
 * across builds, because a shape describes live site state; what that
 * state is, a shape or an alter says with addCacheableDependency().
 */
final class Resources implements ResourcesInterface {

  /**
   * Constructs the build step.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found.
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The typed data manager, for the definitions add() creates.
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface $classResolver
   *   The class resolver, which returns an alter, a storage or an access
   *   class as the autowired service discovery registered.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The account an access question is about when none is named.
   * @param \Drupal\data_surface\ResourceBuild\OperationArguments $operationArguments
   *   What turns a route's or a tool's values into an operation's
   *   arguments, an entity's id into the entity among them.
   * @param \Drupal\data_surface\ResourceBuild\DerivedVariants $derivedVariants
   *   What fills an open slot for the values no declared variant fills.
   * @param \Drupal\data_surface\Refinement\WatchedValueCheckInterface $watchedValueCheck
   *   What a watched value passes before a refiner is handed it, sealed
   *   into every data surface and every child this step builds.
   */
  public function __construct(
    protected readonly ResourceRegistry $registry,
    protected readonly TypedDataManagerInterface $typedDataManager,
    protected readonly ClassResolverInterface $classResolver,
    protected readonly AccountInterface $currentUser,
    protected readonly OperationArguments $operationArguments,
    protected readonly DerivedVariants $derivedVariants,
    protected readonly WatchedValueCheckInterface $watchedValueCheck,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function build(string $resource, OperationContext $context): DataSurfaceInterface {
    return $this->buildResource($resource, $context, []);
  }

  /**
   * Builds one resource or structure, or one child inside its parent.
   *
   * @param string $resource
   *   The class, or its id; a derived resource has only its id.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   Where it is being asked for.
   * @param array<int, array{class: string, id: string, key: string, inputs: string[]}> $ancestry
   *   The parents this one is being built inside, outermost first, each
   *   with the key it is attached at and its own input keys; empty for
   *   one asked for on its own.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The sealed data surface.
   */
  protected function buildResource(string $resource, OperationContext $context, array $ancestry): DataSurfaceInterface {
    $definition = $this->registry->getDefinition($resource);
    // Refuses two providers of one operation id, whichever is asked for.
    $this->registry->getOperations($definition->name());

    $builder = (new DataSurfaceBuilder())->setWatchedValueCheck($this->watchedValueCheck);
    $inputs = new ResourceBlueprint($builder, $this->typedDataManager);
    $outputs = new ResourceBlueprint($builder, $this->typedDataManager, TRUE);
    $this->fillBlueprints($definition, $inputs, $outputs);

    // The owner's own refiners first, one link per class they are on:
    // the owner's class itself, or the classes a deriver named.
    $links = [];
    foreach ($definition->refiners as $refiner) {
      $links[$refiner->class] ??= [$refiner->class, [], NULL];
      $links[$refiner->class][1][] = $refiner;
    }
    $links = array_values($links);
    $additions = [];
    foreach ($definition->alters as $alter) {
      if (!$alter->appliesIn($context->id)) {
        continue;
      }
      $instance = $this->classResolver->getInstanceFromDefinition($alter->class);
      if ($instance instanceof AltersInputsInterface) {
        $instance->alterInputs($additions[$alter->module][0] ??= new ResourceBlueprintAdditions($builder, $this->typedDataManager, $alter->module));
      }
      if ($instance instanceof AltersOutputsInterface) {
        $instance->alterOutputs($additions[$alter->module][1] ??= new ResourceBlueprintAdditions($builder, $this->typedDataManager, $alter->module, TRUE));
      }
      if ($instance instanceof HasStorageLayoutInterface) {
        $builder->setThirdPartyLayout($alter->module, $instance->storageLayout());
      }
      $links[] = [$instance, $alter->refiners, $alter->module];
    }

    $input_keys = $inputs->keys();
    $attached = array_merge(array_keys($inputs->attachments()), array_keys($inputs->slots()));
    $lists = array_keys($inputs->lists());
    foreach ($definition->identity as $key) {
      if (!in_array($key, $input_keys, TRUE)) {
        throw new \LogicException(sprintf(
          'The %s resource (%s) names "%s" as an identity key in #[Resource(identity:)], but its shape never declares that input%s.',
          $definition->id,
          $definition->class ?? $definition->inputs?->describe(),
          $key,
          match (TRUE) {
            in_array($key, $attached, TRUE) => ' (it holds an attached structure, a map, which cannot say which thing this is)',
            in_array($key, $lists, TRUE) => ' (it is a collection, which holds a list and cannot say which thing this is)',
            default => '',
          },
        ));
      }
    }
    foreach ($ancestry as $ancestor) {
      if ($ancestor['class'] === $definition->name()) {
        throw new \LogicException(sprintf(
          '%s is attached inside itself, through %s: nothing can contain itself.',
          $definition->id,
          implode(' -> ', array_map(static fn (array $level): string => $level['id'] . '.' . $level['key'], $ancestry)),
        ));
      }
    }
    $slots = $this->slotsOf($definition, $inputs, $input_keys);

    $this->applyContext($builder, $definition, $context, $input_keys, $attached, $lists);
    // What each module mounted, so an alter's method may watch its own
    // module's keys and is told so when it names another's.
    $mounted_by = [];
    foreach ($additions as $contributor => $blueprints) {
      foreach (isset($blueprints[0]) ? $blueprints[0]->keys() : [] as $key) {
        $mounted_by[$key][] = (string) $contributor;
      }
    }
    // What a coded or runtime refiner may rule out, which a declared rule
    // may then not require: the owner's keys by name, an alter's own by
    // `<module>.<key>`.
    $ruling = [];
    foreach ($links as [, $refiners, $module]) {
      foreach ($refiners as $refiner) {
        if ($refiner->mayNotApply) {
          $ruling[] = $module !== NULL && !in_array($refiner->key, $input_keys, TRUE) ? $module . '.' . $refiner->key : $refiner->key;
        }
      }
    }
    // Each contributor's coded refiners, then the rules it declared, the
    // owner's before any alter's.
    // Decision: see docs/decisions.md#a-rule-declared-as-data.
    $contributors = ['' => NULL];
    foreach ($links as [, , $module]) {
      $contributors[$module ?? ''] ??= $module;
    }
    foreach ($additions as $module => $blueprints) {
      $contributors[(string) $module] ??= (string) $module;
    }
    foreach ($contributors as $contributor) {
      $extended = $contributor !== NULL && isset($additions[$contributor][0]) ? $additions[$contributor][0]->extended() : [];
      $mounted = $contributor !== NULL && isset($additions[$contributor][0]) ? $additions[$contributor][0]->keys() : [];
      foreach ($links as [$instance, $refiners, $module]) {
        if ($module === $contributor) {
          $this->bindRefiners($builder, $definition, $instance, $refiners, $input_keys, $outputs->keys(), $attached, $ancestry === [] ? NULL : $ancestry[count($ancestry) - 1], $module, $extended, $mounted, $module === NULL ? [] : $mounted_by, $lists);
        }
      }
      $rules = $contributor === NULL ? $inputs->rules() : (isset($additions[$contributor][0]) ? $additions[$contributor][0]->rules() : []);
      $this->bindRules($builder, $definition, $rules, $contributor, $input_keys, $attached, $lists, $extended, $mounted, $contributor === NULL ? [] : $mounted_by, $ruling);
    }

    // The children, each through this same build step in its own frame:
    // its own shape, its own alters, its own refiners, and the context
    // it is invoked in, which its access may then narrow to locked or to
    // nothing at all, however it was invoked.
    $frame = ['class' => $definition->name(), 'id' => $definition->id, 'key' => '', 'inputs' => $input_keys];
    $permission = $this->registry->getOperations($definition->name())[$context->id]->permission ?? NULL;
    $frame['permission'] = $permission === NULL ? NULL : static::resolvePermission($permission, $context);
    foreach ($inputs->attachments() as $key => $child) {
      [$attachment, $access] = $this->buildChild((string) $child, $context, $key, $frame, $ancestry);
      if ($access !== NULL) {
        // What the child's access answer read is what this data surface,
        // and everything emitted from it, varies by.
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
          $variants[(string) $value] ??= new Attachment((new DataSurfaceBuilder($definitions))->setWatchedValueCheck($this->watchedValueCheck)->seal());
        }
      }
      $builder->attachBy($key, $by, $variants);
    }
    // Each collection's child, built once for the list: every item is
    // invoked in the one context its operation resolves, and its access
    // answers once for the list, since field access is per field and not
    // per delta.
    // Decision: see docs/decisions.md#access-once-per-list.
    foreach ($inputs->lists() as $key => $child) {
      [$attachment, $access, $child_context] = $this->buildChild($child, $context, $key, $frame, $ancestry);
      if ($access !== NULL) {
        $builder->addCacheableDependency($access);
      }
      if ($attachment === NULL) {
        $builder->omit($key);
        continue;
      }
      $builder->attachList($key, new Collection($attachment, $child_context));
      if ($access !== NULL && $access->isLocked()) {
        $builder->lock($key);
      }
    }

    return $builder->seal();
  }

  /**
   * Builds one child at a key, invoked the way invokeChild() resolves.
   *
   * @param string $child
   *   The child's name: its class, or a derived resource's id.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The parent's context.
   * @param string $key
   *   The key the child sits at.
   * @param array{class: class-string, id: string, key: string, inputs: string[], permission?: string|null} $frame
   *   The parent, for messages and the cycle check, with the permission
   *   its own operation asks, resolved.
   * @param array $ancestry
   *   The parent's own ancestry.
   *
   * @return array{0: \Drupal\data_surface\Attachment|null, 1: \Drupal\data_surface\Resource\AccessAnswer|null, 2: \Drupal\data_surface\Resource\OperationContext}
   *   The sealed child, or NULL when its access omits it; that access
   *   answer, or NULL for a child that invokes no operation of its own and
   *   has no access class, which is open and was asked nothing; and the
   *   context the child was invoked in.
   */
  protected function buildChild(string $child, OperationContext $context, string $key, array $frame, array $ancestry): array {
    $definition = $this->registry->getDefinition($child);
    [$child_context, $operation] = $this->invokeChild($definition, $context, $key, $frame['id']);
    $access = $this->childAccess($definition, $operation, $child_context, $this->currentUser, $frame['permission'] ?? NULL);
    if ($access !== NULL && $access->isOmitted()) {
      return [NULL, $access, $child_context];
    }
    $frame['key'] = $key;
    return [
      new Attachment(
        $this->buildResource($definition->name(), $child_context, [...$ancestry, $frame]),
        $definition->name(),
        $definition->id,
      ),
      $access,
      $child_context,
    ];
  }

  /**
   * Resolves how a child at a key is invoked, the same way at every use.
   *
   * In this order, the first that applies:
   * 1. The parent's context names one of the child's operations, by id,
   *    with withChild($key, operation: ...); or it hands the child a
   *    context whole with withChild($key, $context), which is taken as it
   *    is and invokes no operation of the child's, so no operation's
   *    permission is asked of it (its access class still is, by
   *    childAccess()).
   * 2. The child's operation whose id is the parent context's id.
   * 3. The child's default operation, #[Operation(default: TRUE)].
   * 4. None: the child sees its parent's context id, `creates` and known
   *    identity, exactly as a child always did (childContext()).
   *
   * An operation invoked here is called with the arguments it declares,
   * read by name from the identity the parent handed it and then from
   * the parent's known identity; the context it returns then knows that
   * identity too, its own known keys winning on a clash. A context handed
   * whole is taken as it is. An operation found by the parent's id or by
   * default that cannot be given an argument it needs is passed over, for
   * the next step; one the parent named is refused.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $child
   *   The child.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The parent's context.
   * @param string $key
   *   The key the child sits at.
   * @param string $parent
   *   The parent's id, for messages.
   *
   * @return array{0: \Drupal\data_surface\Resource\OperationContext, 1: \Drupal\data_surface\ResourceBuild\OperationDefinition|null}
   *   The child's context, and the operation of its own it was invoked
   *   through, or NULL when it inherits.
   *
   * @throws \LogicException
   *   When the parent names an operation the child does not declare, or
   *   one that cannot be given an argument it needs.
   */
  protected function invokeChild(ResourceDefinition $child, OperationContext $context, string $key, string $parent): array {
    // Decision: see docs/decisions.md#one-invocation-model-for-both-levels.
    $operations = $this->registry->getOperations($child->name());
    $named = $context->childOperation($key);
    if ($named !== NULL) {
      $operation = $operations[$named['operation']] ?? throw new \LogicException(sprintf(
        '%s, in its "%s" context, names the "%s" operation for its "%s" child, which %s does not declare; it declares %s.',
        $parent,
        $context->id,
        $named['operation'],
        $key,
        $child->id,
        $operations === [] ? 'none' : implode(', ', array_keys($operations)),
      ));
      $own = $this->childOperationContext($child, $operation, $context, $named['known'], $key, $parent);
      assert($own !== NULL);
      return [$own, $operation];
    }
    $handed = $context->forChild($key);
    if ($handed !== $context) {
      // A context handed whole was built by the parent, which took
      // responsibility for entering it: it is taken as it is, and no
      // operation of the child's is invoked, so none of their permissions
      // is asked. The child's access class still answers.
      return [$handed, NULL];
    }
    // The parent's id is a hint, and so is the default: either is
    // passed over when the parent knows too little to invoke it, as a
    // context that knows nothing yet, which a static tool definition or
    // a catalogue builds, does.
    $candidates = isset($operations[$context->id]) ? [$operations[$context->id]] : [];
    foreach ($operations as $candidate) {
      if ($candidate->default && $candidate->id !== $context->id) {
        $candidates[] = $candidate;
      }
    }
    foreach ($candidates as $operation) {
      $own = $this->childOperationContext($child, $operation, $context, [], $key, $parent, FALSE);
      if ($own !== NULL) {
        return [$own, $operation];
      }
    }
    return [static::childContext($context, $key), NULL];
  }

  /**
   * Invokes one of a child's operations for its parent.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $child
   *   The child.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The child's operation.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The parent's context.
   * @param array<string, mixed> $handed
   *   The identity the parent handed the child with the operation's name.
   * @param string $key
   *   The key the child sits at, for messages.
   * @param string $parent
   *   The parent's id, for messages.
   * @param bool $named
   *   Whether the parent named the operation, so that one that cannot be
   *   invoked is a mistake rather than a step to pass over.
   *
   * @return \Drupal\data_surface\Resource\OperationContext|null
   *   The child's context, knowing what it was handed and what its parent
   *   knows, its own known keys first; NULL for an operation the parent did
   *   not name that cannot be given what it needs.
   *
   * @throws \LogicException
   *   When a named operation needs an argument nothing handed it.
   */
  protected function childOperationContext(ResourceDefinition $child, OperationDefinition $operation, OperationContext $context, array $handed, string $key, string $parent, bool $named = TRUE): ?OperationContext {
    $identity = $handed + $context->known;
    try {
      $own = $this->operation($child->name(), $operation->id, $identity);
    }
    catch (\InvalidArgumentException $e) {
      if (!$named) {
        return NULL;
      }
      throw new \LogicException(sprintf(
        '%s invokes the "%s" operation of %s at its "%s" key, from its "%s" context, and cannot give it what it needs: %s Hand it with withChild(\'%s\', operation: \'%s\', known: [...]).',
        $parent,
        $operation->id,
        $child->id,
        $key,
        $context->id,
        $e->getMessage(),
        $key,
        $operation->id,
      ), 0, $e);
    }
    return $own->withKnown(array_diff_key($identity, $own->known));
  }

  /**
   * Answers what an account may do with a child, however it was invoked.
   *
   * The permission of the child's operation the build resolved (named,
   * matched by the parent's id, or the default), then the child's access
   * class, in the child's own context, combined so the narrower answer
   * stands. A permission the account lacks omits the child; one whose
   * placeholder the context cannot fill says nothing, the parent's access
   * having gated the caller already; core's answer from the class is read
   * as AccessAnswer::fromAnswer() reads it. A child invoked through no
   * operation of its own (a context handed whole, or inherited) is asked
   * no permission, and its access class answers alone.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $child
   *   The child.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition|null $operation
   *   The operation the build resolved for it, or NULL for none.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The child's context.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   * @param string|null $gate
   *   The permission the parent's own operation asks, resolved: a child
   *   asking the same one narrows nothing, its parent's gate having asked
   *   it already.
   *
   * @return \Drupal\data_surface\Resource\AccessAnswer|null
   *   Open, locked or omitted, with what the answer read; NULL for a child
   *   with no operation and no access class, which stays open.
   */
  protected function childAccess(ResourceDefinition $child, ?OperationDefinition $operation, OperationContext $context, AccountInterface $account, ?string $gate = NULL): ?AccessAnswer {
    // Decision: see docs/decisions.md#access-has-three-answers.
    // Decision: see docs/decisions.md#one-invocation-model-for-both-levels.
    if ($operation === NULL && $child->access === NULL) {
      return NULL;
    }
    $access = AccessAnswer::open();
    // Only an operation the build resolved asks its permission: a parent
    // that built the child's context itself took responsibility for entry.
    $resolved = $operation?->permission === NULL ? NULL : static::resolvePermission((string) $operation->permission, $context);
    // Decision: see docs/decisions.md#a-permission-a-child-cannot-name.
    if ($resolved !== NULL && $resolved !== $gate) {
      $result = AccessResult::allowedIfHasPermission($account, $resolved);
      if (!$result->isAllowed()) {
        return AccessAnswer::omitted(sprintf('The "%s" operation needs the "%s" permission.', $operation?->id, $resolved))->addCacheableDependency($result);
      }
      $access->addCacheableDependency($result);
    }
    if ($child->access === NULL) {
      return $access;
    }
    return $access->andIf(AccessAnswer::fromAnswer($this->instance($child->access, OperationAccessInterface::class)->access($context, $account)));
  }

  /**
   * Gets the context a child at a key sees when it invokes no operation.
   *
   * Its own, when the parent's context hands it one with withChild(),
   * as an operation does to tell a child the parent's identity. Otherwise
   * the parent's context id, whether it creates, and the identity it
   * knows, which is what lets a child's storage load by the same thing
   * its parent's does. A child with operations of its own is resolved by
   * invokeChild() first, which falls back to this.
   *
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The parent's context.
   * @param string $key
   *   The key the child sits at.
   *
   * @return \Drupal\data_surface\Resource\OperationContext
   *   The child's context.
   */
  public static function childContext(OperationContext $context, string $key): OperationContext {
    $child = $context->forChild($key);
    if ($child !== $context) {
      return $child;
    }
    // Decision: see docs/decisions.md#child-context.
    return new OperationContext($context->id, $context->creates, $context->known);
  }

  /**
   * Resolves each slot's children and checks its deciding key.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure with the slots.
   * @param \Drupal\data_surface\ResourceBuild\ResourceBlueprint $inputs
   *   The owner's input blueprint.
   * @param string[] $input_keys
   *   The owner's plain input keys.
   *
   * @return array<string, array{by: string, children: array<string, class-string>}>
   *   The slots, each filled from discovery's variants.
   *
   * @throws \LogicException
   *   When a deciding key is not a plain input of the owner.
   */
  protected function slotsOf(ResourceDefinition $definition, ResourceBlueprint $inputs, array $input_keys): array {
    $slots = [];
    foreach ($inputs->slots() as $key => $by) {
      if (!in_array($by, $input_keys, TRUE)) {
        throw new \LogicException(sprintf(
          'In %s, the "%s" slot is chosen by "%s", which its shape never declares as a plain input: a slot is chosen by a sibling that holds one value.',
          $definition->id,
          $key,
          $by,
        ));
      }
      // Every structure whose #[Variant] names this class and key
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
  public function operation(string $resource, string $operation, array $arguments = []): OperationContext {
    $found = $this->registry->getOperation($resource, $operation);
    $resolved = $this->operationArguments->resolve($found, $arguments);
    // A derived operation is a method its deriver named, handed the
    // arguments by name; an #[Operation] method takes them as its own.
    $context = $found->context !== NULL
      ? $found->context->call($this->classResolver, $resolved)
      : (new \ReflectionMethod($found->class, $found->method))->invokeArgs(NULL, $resolved);
    if (!$context instanceof OperationContext) {
      throw new \LogicException(sprintf(
        'The "%s" operation, %s, returned %s; an operation returns an %s.',
        $found->id,
        $found->describe(),
        get_debug_type($context),
        OperationContext::class,
      ));
    }
    if ($context->id !== $found->id) {
      throw new \LogicException(sprintf(
        'The "%s" operation, %s, returned a context whose id is "%s". An operation\'s context carries the operation\'s id as its own: call withId(\'%s\') on a context built from another operation.',
        $found->id,
        $found->describe(),
        $context->id,
        $found->id,
      ));
    }
    return $context;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOperation(string $resource, string $operation, array $arguments = []): DataSurfaceInterface {
    return $this->build($resource, $this->operation($resource, $operation, $arguments));
  }

  /**
   * {@inheritdoc}
   */
  public function access(string $resource, OperationContext $context, ?AccountInterface $account = NULL): AccessResultInterface {
    $definition = $this->registry->getDefinition($resource);
    $account ??= $this->currentUser;
    $answer = $this->ownAccess($definition, $context, $account);
    if ($answer->isForbidden() || !$this->childrenMayAnswer($definition)) {
      return $answer;
    }
    // Decision: see docs/decisions.md#a-chosen-variant-may-refuse.
    $answers = $this->childAnswers($definition, $context, $account, $this->build($definition->name(), $context));
    foreach ($answers as $child) {
      if ($child->isForbidden()) {
        return $answer->andIf($child);
      }
    }
    // A variant that did not refuse still varied by what it read.
    if ($answer instanceof RefinableCacheableDependencyInterface) {
      foreach ($answers as $child) {
        $answer->addCacheableDependency($child);
      }
    }
    return $answer;
  }

  /**
   * Answers access for one alone: its permission, then its class.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The answer.
   */
  protected function ownAccess(ResourceDefinition $definition, OperationContext $context, AccountInterface $account): AccessResultInterface {
    // Decision: see docs/decisions.md#no-declared-operation-no-permission-tier.
    $permission = $this->registry->getOperations($definition->name())[$context->id]->permission ?? NULL;
    $result = AccessResult::neutral();
    if ($permission !== NULL) {
      $resolved = static::resolvePermission($permission, $context);
      if ($resolved === NULL) {
        return AccessResult::forbidden(sprintf(
          'The "%s" operation\'s permission, "%s", names identity its context does not know.',
          $context->id,
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
    $answer = static::atTheTop($this->instance($definition->access, OperationAccessInterface::class)->access($context, $account));
    return $permission === NULL ? $answer : $result->andIf($answer);
  }

  /**
   * Reads an access class's answer the way one asked on its own is read.
   *
   * Core's answer is the answer. Of the three AccessAnswer answers, open
   * is allowed and omitted is forbidden; locked is forbidden too, because
   * a whole resource shown and not saved is not built at the top level
   * yet, and refusing is the reading that writes nothing.
   *
   * @param \Drupal\Core\Access\AccessResultInterface|\Drupal\data_surface\Resource\AccessAnswer $answer
   *   The access class's answer.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Core's answer, with the cacheability it read.
   */
  protected static function atTheTop(AccessResultInterface|AccessAnswer $answer): AccessResultInterface {
    if ($answer instanceof AccessResultInterface) {
      return $answer;
    }
    // Decision: see docs/decisions.md#locked-at-the-top.
    $result = $answer->isOpen()
      ? AccessResult::allowed()
      : AccessResult::forbidden($answer->getReason() ?? sprintf('The access class answered %s.', $answer->answer()));
    return $result->addCacheableDependency($answer);
  }

  /**
   * Says whether anything else discovered has an access class.
   *
   * Building a resource to find its children costs a build, so it is done
   * only when some child could have something to say.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure being asked about.
   *
   * @return bool
   *   TRUE when some other resource or structure names an access class.
   */
  protected function childrenMayAnswer(ResourceDefinition $definition): bool {
    foreach ($this->registry->getDefinitions() as $other) {
      if ($other->name() !== $definition->name() && $other->access !== NULL) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Asks each slot variant the parent's identity has chosen for its access.
   *
   * A slot whose deciding key is locked has its variant chosen by what
   * the parent is (a field's settings by its type), and the parent cannot
   * be written without it. That variant's access class, asked in the
   * context it is invoked in however it was invoked, may refuse the
   * parent, never allow. It is looked up among the slot's declared
   * variants, since the build leaves out one whose access is not open.
   * Every other child's access shaped its own keys when it was built, and
   * refuses nothing above it. Recursively, through every child the build
   * carries.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $parent
   *   The parent.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The parent's context.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   * @param \Drupal\data_surface\DataSurfaceInterface $built
   *   The parent, built in that context.
   *
   * @return \Drupal\Core\Access\AccessResultInterface[]
   *   The chosen variants' answers, outermost first.
   */
  protected function childAnswers(ResourceDefinition $parent, OperationContext $context, AccountInterface $account, DataSurfaceInterface $built): array {
    $answers = [];
    foreach ($built->getDefinitions()->entries() as $key => $entry) {
      $key = (string) $key;
      $attachment = $entry->attachment ?? $entry->list?->item;
      if ($attachment === NULL && $entry->slot !== NULL && $built->isLocked($entry->slot->by)) {
        $value = $built->getDefault($entry->slot->by);
        $class = is_string($value) || is_int($value) ? ($this->registry->getVariants($parent->name(), $key)[$value] ?? NULL) : NULL;
        if ($class === NULL) {
          continue;
        }
        $variant = $this->registry->getDefinition($class);
        [$variant_context] = $this->invokeChild($variant, $context, $key, $parent->id);
        if ($variant->access !== NULL) {
          $answer = static::atTheTop($this->instance($variant->access, OperationAccessInterface::class)->access($variant_context, $account));
          $answers[] = $answer;
          if ($answer->isForbidden()) {
            continue;
          }
        }
        $attachment = $entry->slot->variants[(string) $value] ?? NULL;
        if ($attachment !== NULL) {
          array_push($answers, ...$this->childAnswers($variant, $variant_context, $account, $attachment->child));
        }
        continue;
      }
      if ($attachment?->source === NULL) {
        continue;
      }
      $child = $this->registry->getDefinition($attachment->source);
      [$child_context] = $this->invokeChild($child, $context, $key, $parent->id);
      array_push($answers, ...$this->childAnswers($child, $child_context, $account, $attachment->child));
    }
    return $answers;
  }

  /**
   * {@inheritdoc}
   */
  public function defaults(string $resource): array {
    $builder = new DataSurfaceBuilder();
    $this->fillBlueprints($this->registry->getDefinition($resource), new ResourceBlueprint($builder, $this->typedDataManager));
    return $builder->seal()->getDefaultValues();
  }

  /**
   * {@inheritdoc}
   */
  public function storage(string $resource, OperationContext $context, ?DataSurfaceInterface $built = NULL): DataSurfaceStorageInterface {
    $definition = $this->registry->getDefinition($resource);
    if ($definition->storage === NULL) {
      throw new \LogicException(sprintf(
        $definition->isResource()
          ? '%s names no storage in #[Resource(storage:)]: its host or its parent stores it, the way the block host stores a plugin\'s configuration.'
          : '%s is a structure, which has no storage of its own: its host or its parent stores it, the way the block host stores a plugin\'s configuration.',
        $definition->id,
      ));
    }
    return $this->storageFor($definition, $context, $built ?? $this->build($definition->name(), $context));
  }

  /**
   * Composes a resource's storage with its children's, along the tree.
   *
   * Each child whose class names storage of its own is routed to it, in
   * the context it is invoked in (invokeChild()), and recursively for the
   * child's own children; a child without it stays in its parent's
   * values, stored by the parent under its key. A slot routes per
   * variant, by what its deciding key holds.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource, which has storage.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   Its context.
   * @param \Drupal\data_surface\DataSurfaceInterface $built
   *   The resource as built, whose entries carry the children.
   *
   * @return \Drupal\data_surface\ResourceBuild\StorageAdapter
   *   The storage.
   */
  protected function storageFor(ResourceDefinition $definition, OperationContext $context, DataSurfaceInterface $built): StorageAdapter {
    $routes = [];
    foreach ($built->getDefinitions()->entries() as $key => $entry) {
      $children = $entry->attachment !== NULL
        ? [StorageAdapter::ATTACHED => $entry->attachment]
        : ($entry->slot !== NULL ? $entry->slot->variants : []);
      foreach ($children as $id => $attachment) {
        $child = $attachment->source === NULL ? NULL : $this->registry->getDefinition($attachment->source);
        if ($child?->storage !== NULL) {
          $routes[(string) $key][(string) $id] = $this->storageFor($child, $this->invokeChild($child, $context, (string) $key, $definition->id)[0], $attachment->child);
        }
      }
    }
    return new StorageAdapter(
      $this->instance((string) $definition->storage, ResourceStorageInterface::class),
      $context,
      $routes,
      $definition->identity,
      $this->omittedKeys($definition, $built),
    );
  }

  /**
   * Lists the children the build left out, which the parent stores.
   *
   * The keys the owner's blueprint attaches a child or a collection at that
   * the built resource does not carry: only a child's access omits a key.
   * The storage keeps what it holds under each, since the caller could
   * not see it.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\data_surface\DataSurfaceInterface $built
   *   The resource as built.
   *
   * @return string[]
   *   The omitted keys.
   */
  protected function omittedKeys(ResourceDefinition $definition, DataSurfaceInterface $built): array {
    $blueprint = new ResourceBlueprint(new DataSurfaceBuilder(), $this->typedDataManager);
    $this->fillBlueprints($definition, $blueprint);
    return array_values(array_filter(
      [...array_keys($blueprint->attachments()), ...array_keys($blueprint->lists())],
      static fn (string $key): bool => $built->getDefinitions()->entry($key) === NULL,
    ));
  }

  /**
   * Applies what a context says about where it is asked for.
   *
   * @param \Drupal\data_surface\DataSurfaceBuilderInterface $builder
   *   The builder holding the shape.
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context.
   * @param string[] $input_keys
   *   The owner's input keys.
   * @param string[] $attached
   *   The owner's keys holding an attached child or a slot.
   * @param string[] $lists
   *   The owner's collection keys, which a context may narrow and not
   *   start.
   *
   * @throws \LogicException
   *   When the context names a key the shape does not declare, carries
   *   starting values without creating, or widens a key.
   */
  protected function applyContext(DataSurfaceBuilderInterface $builder, ResourceDefinition $definition, OperationContext $context, array $input_keys, array $attached = [], array $lists = []): void {
    $assert_input = static function (string $key, string $what) use ($definition, $context, $input_keys, $attached, $lists): void {
      if (in_array($key, $lists, TRUE)) {
        // The list key is the parent's, so where the parent is asked for
        // may narrow it (its Count); its items are the child's, so no
        // starting value can be given for them here.
        if ($what === 'constraint') {
          return;
        }
        throw new \LogicException(sprintf(
          'The "%s" context gives a %s for "%s", which is a collection in %s: its items are each the child\'s, accepted in the child\'s own frame, and a list of them starts empty.',
          $context->id,
          $what,
          $key,
          $definition->id,
        ));
      }
      if (in_array($key, $attached, TRUE)) {
        throw new \LogicException(sprintf(
          'The "%s" context gives a %s for "%s", which holds a child in %s: a child is narrowed and started by its own context, handed to it with withChild(\'%s\', ...).',
          $context->id,
          $what,
          $key,
          $definition->id,
          $key,
        ));
      }
      if (!in_array($key, $input_keys, TRUE)) {
        throw new \LogicException(sprintf(
          'The "%s" context gives a %s for "%s", which %s does not declare as an input.',
          $context->id,
          $what,
          $key,
          $definition->id,
        ));
      }
    };

    if ($context->starting !== []) {
      if (!$context->creates) {
        throw new \LogicException(sprintf(
          'The "%s" context carries starting values for %s but does not create. Starting values are where a new thing begins; a thing that exists begins from what its storage loads.',
          $context->id,
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
      Narrowing::assertNarrows((string) $key, sprintf('the "%s" operation', $context->id), $before, $target);
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
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure.
   * @param object|class-string $instance
   *   The owner's class, whose methods are static, or the alter instance
   *   the methods are called on.
   * @param \Drupal\data_surface\ResourceBuild\RefinerDefinition[] $refiners
   *   Its #[RefinesInput] methods.
   * @param string[] $input_keys
   *   The owner's input keys.
   * @param string[] $output_keys
   *   The owner's output keys.
   * @param string[] $attached
   *   The owner's keys holding an attached child or a slot.
   * @param array{class: class-string, id: string, key: string, inputs: string[]}|null $parent
   *   The parent this one is attached inside, or NULL.
   * @param string|null $module
   *   The module of an alter, or NULL for the owner itself.
   * @param string[] $extended
   *   The owner's keys that alter offered more values on with
   *   extendChoices(): its methods on those keys narrow its own values.
   * @param string[] $mounted
   *   The keys that alter added, which its methods may refine and watch
   *   too.
   * @param array<string, string[]> $mountedBy
   *   For an alter, every key any alter of the owner mounted, mapped
   *   to the modules that mounted it; empty for the owner itself.
   * @param string[] $lists
   *   The owner's collection keys: a method may refine one (its Count),
   *   and may not watch one.
   *
   * @throws \LogicException
   *   When a method fails a seal-time check, or one that watches nothing
   *   widens what it was given.
   */
  protected function bindRefiners(DataSurfaceBuilderInterface $builder, ResourceDefinition $definition, object|string $instance, array $refiners, array $input_keys, array $output_keys, array $attached = [], ?array $parent = NULL, ?string $module = NULL, array $extended = [], array $mounted = [], array $mountedBy = [], array $lists = []): void {
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
      static::assertStaticOnResource($definition, $refiner, $module);
      static::assertStaticUnlessRuntime($definition, $refiner);
      static::assertWalled($definition, $refiner, $input_keys, $attached, $parent, $lists);
      static::assertNotWatchingForeignMount($definition, $refiner, $input_keys, $mounted, $mountedBy);
      // Decision: see docs/decisions.md#count-is-the-parents.
      $refinable = $own ? [...$input_keys, $refiner->key] : [...$input_keys, ...$lists];
      if ($refiner->mayNotApply) {
        // Decision: see docs/decisions.md#a-key-that-does-not-apply.
        array_push($refinable, ...$attached);
      }
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
    // run is now, and what it returns is what the data surface advertises.
    foreach ($once as [$refiner, $own]) {
      $advertised = $own
        ? $builder->getThirdPartyDefinition((string) $module, $refiner->key)
        : $builder->getDefinition($refiner->key);
      assert($advertised !== NULL);
      $refined = $link->invoke($refiner, DataSurface::deepClone($advertised), []);
      Narrowing::assertNarrows($refiner->key, $refiner->describe(), $advertised, $refined);
      if (in_array($refiner->key, $lists, TRUE)) {
        DataSurface::assertItemsUntouched(new Entry($refiner->key, $advertised), $refined);
      }
      DataSurface::carryMetadata($advertised, $refined);
      if ($own) {
        $builder->setThirdPartyDefinition((string) $module, $refiner->key, $refined);
        continue;
      }
      $builder->setDefinition($refiner->key, $refined);
    }
  }

  /**
   * Checks one contributor's declared rules against the shape, and binds them.
   *
   * Each rule is a static refiner of its key: its condition's keys become
   * refinement edges that gate nothing (Entry::declaredDependencies()),
   * and the contributor's rules one Refinement\DeclaredRefiner link in the
   * owner's chain of each key they narrow, after the contributor's coded
   * refiners. An alter's rule on a key it mounted is bound under the
   * mount, as its coded refiners are.
   *
   * Decision: see docs/decisions.md#a-rule-declared-as-data.
   *
   * @param \Drupal\data_surface\DataSurfaceBuilderInterface $builder
   *   The builder holding the shape.
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure.
   * @param \Drupal\data_surface\Refinement\Rule[] $rules
   *   The rules the contributor declared, in order.
   * @param string|null $module
   *   The alter's module, or NULL for the owner.
   * @param string[] $input_keys
   *   The owner's plain input keys.
   * @param string[] $attached
   *   The owner's keys holding an attached child or a slot.
   * @param string[] $lists
   *   The owner's collection keys.
   * @param string[] $extended
   *   The owner's keys the alter offered more values on.
   * @param string[] $mounted
   *   The keys the alter added.
   * @param array<string, string[]> $mountedBy
   *   Every key an alter mounted, mapped to the modules that did.
   * @param string[] $ruling
   *   The keys a coded or runtime refiner may rule out, an alter's own by
   *   `<module>.<key>`.
   *
   * @throws \LogicException
   *   Naming the rule and what is wrong with it.
   */
  protected function bindRules(DataSurfaceBuilderInterface $builder, ResourceDefinition $definition, array $rules, ?string $module, array $input_keys, array $attached, array $lists, array $extended, array $mounted, array $mountedBy, array $ruling): void {
    if ($rules === []) {
      return;
    }
    $who = $module === NULL ? $definition->id : sprintf('the %s alter of %s', $module, $definition->id);
    $bound = [];
    $paths = [];
    foreach ($rules as $rule) {
      $name = sprintf('narrow("%s") in %s', $rule->key, $who);
      $condition = $rule->condition();
      if ($condition === NULL || !$rule->hasEffect()) {
        throw new \LogicException(sprintf('%s says %s. A rule is a condition and at least one effect: ->when(Condition::...) and what it does, such as ->constrain(), ->require(), ->doesNotApply() or ->describe().', $name, $condition === NULL ? 'no ->when()' : 'nothing it does'));
      }
      $own = $module !== NULL && in_array($rule->key, $mounted, TRUE) && !in_array($rule->key, $input_keys, TRUE);
      $nested = !$own && in_array($rule->key, $attached, TRUE);
      $list = !$own && in_array($rule->key, $lists, TRUE);
      if (!$own && !$nested && !$list && !in_array($rule->key, $input_keys, TRUE)) {
        throw new \LogicException(sprintf('%s narrows "%s", which %s does not declare as an input%s.', $name, $rule->key, $definition->id, isset($mountedBy[$rule->key]) ? sprintf(' (the %s module mounted it, and an alter narrows only the keys it mounted itself and the owner\'s)', implode(', ', $mountedBy[$rule->key])) : ''));
      }
      if (!$own && in_array($rule->key, $extended, TRUE)) {
        throw new \LogicException(sprintf('%s narrows "%s", a key the alter offered more values on. What it offered is narrowed by a #[RefinesInput] method on the key, handed those values alone; a declared rule of them is not built.', $name, $rule->key));
      }
      $constraints = $rule->constraints();
      if ($nested && !$rule->rulesOut()) {
        throw new \LogicException(sprintf('%s narrows "%s", which holds a child in %s, other than by ruling it out. A child narrows its own keys, in its own frame; a parent may only say the key does not apply.', $name, $rule->key, $definition->id));
      }
      if ($list && (array_diff(array_keys($constraints), ['Count']) !== [] || $rule->requires())) {
        throw new \LogicException(sprintf('%s constrains "%s", a collection in %s, other than by its Count. The list is the parent\'s to narrow, by its Count; every item is the child\'s.', $name, $rule->key, $definition->id));
      }
      $at = $own ? $module . '.' . $rule->key : $rule->key;
      if ($rule->requires() && in_array($at, $ruling, TRUE)) {
        throw new \LogicException(sprintf('%s requires "%s", which a #[RefinesInput] method may answer does not apply. No schema can say where a key is required that a coded rule may rule out: say both in one place, the refiner or the rules.', $name, $rule->key));
      }
      $advertised = $own ? $builder->getThirdPartyDefinition((string) $module, $rule->key) : $builder->getDefinition($rule->key);
      assert($advertised !== NULL);
      static::assertConstrainable($name, $rule, $advertised);

      // The keys the condition reads: siblings in this frame.
      // Decision: see docs/decisions.md#a-rule-declared-as-data.
      $watched = [];
      foreach ($condition->keys() as $key) {
        $mine = $module !== NULL && in_array($key, $mounted, TRUE) && !in_array($key, $input_keys, TRUE);
        $problem = match (TRUE) {
          $key === $rule->key => 'the key the rule narrows: a key does not depend on itself',
          in_array($key, $attached, TRUE) => 'a key holding a child, whose values are the child\'s',
          in_array($key, $lists, TRUE) => 'a collection, whose items are each a child',
          !$mine && !in_array($key, $input_keys, TRUE) => isset($mountedBy[$key])
            ? sprintf('a key the %s module mounted: an alter reads the keys it mounted itself and the owner\'s', implode(', ', $mountedBy[$key]))
            : sprintf('no input of %s', $definition->id),
          default => NULL,
        };
        if ($problem !== NULL) {
          throw new \LogicException(sprintf('%s has a condition on "%s", which is %s.', $name, $key, $problem));
        }
        // A port, once ports land, is resolved here, to the key its
        // container wires it to.
        $path = $mine ? RefinesInputRefiner::path((string) $module, $key) : $key;
        if ($mine) {
          $paths[$key] = $path;
        }
        $watched[] = $path;
        $read = $mine ? $builder->getThirdPartyDefinition((string) $module, $key) : $builder->getDefinition($key);
        assert($read !== NULL);
        static::assertReadable($name, $key, $condition, $read);
      }
      $builder->addRefinement($own ? RefinesInputRefiner::path((string) $module, $rule->key) : $rule->key, $watched, FALSE, TRUE);
      $bound[$own ? RefinesInputRefiner::MOUNT : $rule->key][] = $rule;
    }
    $link = new DeclaredRefiner($bound, $module, $paths);
    foreach (array_keys($bound) as $key) {
      $builder->addRefiner((string) $key, $link);
    }
  }

  /**
   * Refuses a rule's effects the key cannot take, or that would widen it.
   *
   * @param string $name
   *   The rule, in words.
   * @param \Drupal\data_surface\Refinement\Rule $rule
   *   The rule.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $advertised
   *   The key as declared.
   *
   * @throws \LogicException
   *   Naming the rule and what it would widen.
   */
  protected static function assertConstrainable(string $name, Rule $rule, DataDefinitionInterface $advertised): void {
    $type = $advertised->getDataType();
    foreach (array_keys($rule->constraints()) as $constraint) {
      $fits = match ($constraint) {
        'Range' => in_array($type, ['integer', 'float'], TRUE),
        'Count' => $advertised instanceof ListDataDefinitionInterface,
        'Choice' => ChoiceSet::of($advertised) !== NULL,
        default => !$advertised instanceof ComplexDataDefinitionInterface && !$advertised instanceof ListDataDefinitionInterface,
      };
      if (!$fits) {
        throw new \LogicException(sprintf('%s constrains "%s", whose type is %s, by %s, which it cannot take%s.', $name, $rule->key, $type, $constraint === 'Choice' ? 'a list of its values' : $constraint, $constraint === 'Choice' ? ': it declares no list of values' : ''));
      }
    }
    $written = $rule->applyAsWritten(DataSurface::deepClone($advertised));
    if (DoesNotApply::marks($written)) {
      return;
    }
    $widened = Narrowing::narrowed($written, $advertised);
    if ($widened !== NULL) {
      throw new \LogicException(sprintf('%s widens what the key declares: %s. A declared rule may only narrow; declare the key wider, or the rule narrower.', $name, $widened));
    }
  }

  /**
   * Refuses a condition on a key that it cannot read exactly.
   *
   * Each value a condition compares with is of the key's type, so the
   * `if` the full schema states reads the accepted document as the
   * pipeline does: a number for an integer, TRUE or FALSE for a boolean.
   *
   * @param string $name
   *   The rule, in words.
   * @param string $key
   *   The condition key.
   * @param \Drupal\data_surface\Refinement\Condition $condition
   *   The whole condition.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $read
   *   The condition key's definition.
   *
   * @throws \LogicException
   *   Naming the rule, the key and the value.
   */
  protected static function assertReadable(string $name, string $key, Condition $condition, DataDefinitionInterface $read): void {
    $type = $read->getDataType();
    $plain = !$read instanceof ComplexDataDefinitionInterface && !$read instanceof ListDataDefinitionInterface;
    foreach ($condition->tests() as $test) {
      if ($test->key !== $key) {
        continue;
      }
      if ($test->isBound() && !in_array($type, ['integer', 'float'], TRUE)) {
        throw new \LogicException(sprintf('%s compares "%s" with a number, and its type is %s: a bound reads an integer or a float.', $name, $key, $type));
      }
      foreach ($test->operands() as $operand) {
        $fits = $plain && match ($type) {
          'integer' => is_int($operand),
          'float' => is_int($operand) || is_float($operand),
          'boolean' => is_bool($operand),
          default => is_string($operand),
        };
        if (!$fits) {
          throw new \LogicException(sprintf('%s compares "%s", whose type is %s, with %s, which it never holds. A condition compares a key with values of its own type, as the accepted document holds them.', $name, $key, $type, var_export($operand, TRUE)));
        }
      }
    }
  }

  /**
   * Refuses an owner's #[RefinesInput] method that is not static.
   *
   * A resource or structure is never instantiated, so its refiner has no
   * instance to run on; an alter's may be either, since an alter is a
   * service.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure.
   * @param \Drupal\data_surface\ResourceBuild\RefinerDefinition $refiner
   *   The method.
   * @param string|null $module
   *   The module of an alter, or NULL for the owner itself.
   *
   * @throws \LogicException
   *   Naming the method.
   */
  protected static function assertStaticOnResource(ResourceDefinition $definition, RefinerDefinition $refiner, ?string $module): void {
    if ($module === NULL && !$refiner->isStatic) {
      throw new \LogicException(sprintf(
        '%s is a #[RefinesInput] method of %s and is not static. A resource or structure is never instantiated, so its refiners are static, like its defineInputs(); only an alter\'s runtime refiner may be an instance method, because an alter is a service.',
        $refiner->describe(),
        $definition->id,
      ));
    }
  }

  /**
   * Refuses an instance method that does not declare itself runtime.
   *
   * A static refiner promises to be a pure function of the siblings it
   * watches, which the served contract runs ahead of time. The one thing
   * a static method cannot have is the services its class was built
   * with, so the language holds it to that promise where it can: an
   * instance method can read whatever its alter was injected with, and
   * is only allowed as a runtime refiner. A static method may still
   * declare runtime, since it can reach the global container.
   *
   * Decision: see docs/decisions.md#static-by-default-runtime-by-declaration.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure.
   * @param \Drupal\data_surface\ResourceBuild\RefinerDefinition $refiner
   *   The method, an alter's: an owner's that is not static is refused
   *   before this, by assertStaticOnResource().
   *
   * @throws \LogicException
   *   Naming the method and the two ways to fix it.
   */
  protected static function assertStaticUnlessRuntime(ResourceDefinition $definition, RefinerDefinition $refiner): void {
    if (!$refiner->isStatic && !$refiner->runtime) {
      throw new \LogicException(sprintf(
        '%s is an instance method of an alter of %s and does not declare runtime: TRUE. A static refiner is a pure function of the siblings it watches, which the served contract runs ahead of time, and an instance method can read the services its alter was built with: make it `public static` (translating with t()), or, if its answer depends on site state, declare #[RefinesInput(\'%s\', runtime: TRUE)].',
        $refiner->describe(),
        $definition->id,
        $refiner->key,
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
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure.
   * @param \Drupal\data_surface\ResourceBuild\RefinerDefinition $refiner
   *   The method.
   * @param string[] $input_keys
   *   The owner's input keys.
   * @param string[] $mounted
   *   The keys the method's alter added.
   * @param array<string, string[]> $mountedBy
   *   Every mounted key, mapped to the modules that mounted it; empty
   *   for the owner's own methods.
   *
   * @throws \LogicException
   *   Naming the method, the key and the module that mounted it.
   */
  protected static function assertNotWatchingForeignMount(ResourceDefinition $definition, RefinerDefinition $refiner, array $input_keys, array $mounted, array $mountedBy): void {
    foreach ($refiner->watched() as $watched) {
      if (isset($mountedBy[$watched]) && !in_array($watched, $input_keys, TRUE) && !in_array($watched, $mounted, TRUE)) {
        throw new \LogicException(sprintf(
          '%s watches "%s", a key the %s module mounted on %s. An alter may watch the keys it mounted itself and the owner\'s, never another module\'s.',
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
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure.
   * @param \Drupal\data_surface\ResourceBuild\RefinerDefinition $refiner
   *   The method.
   * @param string[] $input_keys
   *   The owner's input keys, which win over a mounted key of one name.
   * @param string|null $module
   *   The module of an alter, or NULL for the owner itself.
   * @param string[] $mounted
   *   The keys that alter added.
   *
   * @return array<string, string>
   *   The watched keys the alter mounted, each mapped to its dotted path.
   *
   * @throws \LogicException
   *   When the parameter receiving one cannot take NULL.
   */
  protected static function mountedWatches(ResourceDefinition $definition, RefinerDefinition $refiner, array $input_keys, ?string $module, array $mounted): array {
    $paths = [];
    foreach ($refiner->watched() as $position => $watched) {
      if ($module === NULL || in_array($watched, $input_keys, TRUE) || !in_array($watched, $mounted, TRUE)) {
        continue;
      }
      $parameter = (new \ReflectionMethod($refiner->class, $refiner->method))->getParameters()[$position + 1] ?? NULL;
      if ($parameter !== NULL && !$parameter->allowsNull()) {
        throw new \LogicException(sprintf(
          '%s watches "%s", a key its alter mounted on %s, which is handed as it stands and is NULL until answered: declare $%s nullable.',
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
   * Refuses a #[RefinesInput] method that reaches across to a child.
   *
   * The pattern's wall between a parent and its child, in both
   * directions. A parent's method may neither refine a key holding a
   * child (the child refines its own keys, in its own frame), except to
   * answer that the key does not apply, nor watch one; a child's method
   * may not watch its parent's keys, because a child sees only its
   * context. The one door is the context: a parent
   * hands a child what it needs as identity with withChild().
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure.
   * @param \Drupal\data_surface\ResourceBuild\RefinerDefinition $refiner
   *   The method.
   * @param string[] $input_keys
   *   Its own plain input keys.
   * @param string[] $attached
   *   Its keys holding an attached child or a slot.
   * @param array{class: class-string, id: string, key: string, inputs: string[]}|null $parent
   *   The parent this one is attached inside, or NULL.
   * @param string[] $lists
   *   Its collection keys, which a method may refine, the list
   *   being the parent's, and may not watch, its items being the child's.
   *
   * @throws \LogicException
   *   Naming the method and the key it reached for.
   */
  protected static function assertWalled(ResourceDefinition $definition, RefinerDefinition $refiner, array $input_keys, array $attached, ?array $parent, array $lists = []): void {
    // Whether the key applies at all is the parent's to say, since the
    // parent owns the key; what the child holds is the child's.
    // Decision: see docs/decisions.md#a-key-that-does-not-apply.
    if (in_array($refiner->key, $attached, TRUE) && !$refiner->mayNotApply) {
      throw new \LogicException(sprintf(
        '%s refines "%s", which holds a child in %s. A child is refined by its own #[RefinesInput] methods, in its own frame; a parent cannot refine into it, and may only answer that the key does not apply, with a method declaring `: DataDefinitionInterface|DoesNotApply`.',
        $refiner->describe(),
        $refiner->key,
        $definition->id,
      ));
    }
    foreach ($refiner->watched() as $watched) {
      if (in_array($watched, $lists, TRUE)) {
        throw new \LogicException(sprintf(
          '%s watches "%s", which is a collection in %s. A parent does not read its children\'s values, and every item of a collection is a child: what depends on an item belongs in the child.',
          $refiner->describe(),
          $watched,
          $definition->id,
        ));
      }
      if (in_array($watched, $attached, TRUE)) {
        throw new \LogicException(sprintf(
          '%s watches "%s", which holds a child in %s. A parent does not read its child\'s values: what depends on both belongs in the child.',
          $refiner->describe(),
          $watched,
          $definition->id,
        ));
      }
      if ($parent !== NULL && !in_array($watched, $input_keys, TRUE) && in_array($watched, $parent['inputs'], TRUE)) {
        throw new \LogicException(sprintf(
          '%s watches "%s", which is a key of %s, the parent this one is attached inside at "%s". A child never reads its parent\'s values; the parent hands it what it needs as identity in the child\'s context, with withChild().',
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
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure.
   * @param \Drupal\data_surface\ResourceBuild\RefinerDefinition $refiner
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
  protected static function assertRefinable(ResourceDefinition $definition, RefinerDefinition $refiner, array $input_keys, array $output_keys, ?array $watchable = NULL): void {
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
          '%s refines "%s", which is an output of %s. Outputs are never refined: a refinement narrows what may be sent, and nobody sends an output.',
          $refiner->describe(),
          $refiner->key,
          $definition->id,
        )
        : sprintf(
          '%s refines "%s", which %s does not declare as an input.',
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
          '%s watches "%s", which %s does not declare as an input.',
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
   *   The permission, as the operation declares it.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context.
   *
   * @return string|null
   *   The permission, or NULL when it names a key the context does not
   *   know as a scalar.
   */
  protected static function resolvePermission(string $permission, OperationContext $context): ?string {
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
   * Fills a blueprint, from its class or from what derived it.
   *
   * A class's defineInputs() and defineOutputs() are called on the
   * class; a derived resource's are the callables its deriver
   * named, handed the blueprint first.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure.
   * @param \Drupal\data_surface\ResourceBuild\ResourceBlueprint $inputs
   *   The input blueprint.
   * @param \Drupal\data_surface\ResourceBuild\ResourceBlueprint|null $outputs
   *   The output blueprint, or NULL when only the inputs are wanted.
   */
  protected function fillBlueprints(ResourceDefinition $definition, ResourceBlueprint $inputs, ?ResourceBlueprint $outputs = NULL): void {
    if ($definition->isDerived()) {
      // Decision: see docs/decisions.md#a-derived-resource-has-no-class.
      $definition->inputs?->call($this->classResolver, $inputs);
      if ($outputs !== NULL) {
        $definition->outputs?->call($this->classResolver, $outputs);
      }
      return;
    }
    // Decision: see docs/decisions.md#a-resource-or-structure-is-static.
    $owner = static::resourceClass($definition);
    $owner::defineInputs($inputs);
    if ($outputs !== NULL && is_subclass_of($owner, HasOutputsInterface::class)) {
      $owner::defineOutputs($outputs);
    }
  }

  /**
   * Gets the class whose static methods describe a definition.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure.
   *
   * @return class-string<\Drupal\data_surface\Resource\DefinesInputsInterface>
   *   Its class, which discovery held to DefinesInputsInterface.
   */
  protected static function resourceClass(ResourceDefinition $definition): string {
    $class = $definition->class;
    assert(is_subclass_of($class, DefinesInputsInterface::class));
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
