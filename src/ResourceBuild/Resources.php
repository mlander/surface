<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\PermissionsHashGeneratorInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\data_surface\DataSurface;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Pipeline\DataSurfaceStorageInterface;
use Drupal\data_surface\Refinement\ChoiceSet;
use Drupal\data_surface\Refinement\Condition;
use Drupal\data_surface\Refinement\DeclaredRefiner;
use Drupal\data_surface\Refinement\DoesNotApply;
use Drupal\data_surface\Refinement\Narrowing;
use Drupal\data_surface\Refinement\Rule;
use Drupal\data_surface\Refinement\RuntimeRefiner;
use Drupal\data_surface\Refinement\StaticRefinerContainer;
use Drupal\data_surface\Refinement\WatchedValueCheckInterface;
use Drupal\data_surface\Resource\AltersOutputsInterface;
use Drupal\data_surface\Resource\HasOutputsInterface;
use Drupal\data_surface\Resource\HasStorageLayoutInterface;
use Drupal\data_surface\Resource\KnowsClearableKeysInterface;
use Drupal\data_surface\Resource\AccessAnswer;
use Drupal\data_surface\Resource\OperationAccessInterface;
use Drupal\data_surface\Resource\AltersInputsInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\DefinesInputsInterface;
use Drupal\data_surface\Resource\ResourceStorageInterface;
use Drupal\data_surface\Attachment;
use Drupal\data_surface\Entry;
use Drupal\data_surface\Collection;
use Drupal\data_surface\Mixin;
use Drupal\data_surface\Port;

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
 *    own structure. A structure mixed in with with() declares its members
 *    into the same builder, where the call stands.
 * 2. The alters #[Alters] names for this class, in discovery order,
 *    skipping one whose operations leave this context's id out:
 *    alterInputs() and alterOutputs() over a ResourceBlueprintAdditions,
 *    which mounts what they add under the alter's module. A structure an
 *    alter attached at its own mount is built here, in its own frame, its
 *    keys the mount's and its ports wired to the owner's keys
 *    (mountStructure()), before anything is bound, since the alter's
 *    glue reads its keys.
 * 3. The seal-time checks on the shape: every identity key is a declared
 *    input, and no operation id is provided twice.
 * 4. The context. Starting values become the declared defaults, when
 *    the context creates; each constraint is added to its key and held
 *    to the narrowing check; each identity key the context knows becomes
 *    that key's default and is locked, which is the engine's lock: the
 *    value fixed to the declared default.
 * 5. Each structure mixed in (mixIn()): its ports' wiring checked, built
 *    by itself for its def and its access, which holds its members to
 *    what is stored or takes them back out; then what of the owner's names
 *    its members is checked (assertMixinsUnnamed()), and its refiners,
 *    rules and alters are bound in the owner's frame first (bindMixin()).
 * 6. The refiners. Every #[RefinesInput] method is checked against the
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
 * 7. The children, each through this same build step in its own frame,
 *    its ports wired to the parent's keys (assertWiring(), wirePorts()),
 *    each invoked the way one asked on its own is: through one of
 *    its own operations when one applies (invokeChild()), otherwise in
 *    the context its parent's hands it or in its parent's own. Its access
 *    then decides whether the child is open, locked or left out for the
 *    current account (childAccess()): its access class however it was
 *    invoked, and the permission of an operation the build resolved.
 *    A collection's child is built the same way, once for the list, and
 *    its access answers once for the list. Built for the account alone
 *    (buildStatic()), an access class's answer that is runtime is set
 *    aside and its keys marked, and only the static ones apply.
 * 8. The seal.
 *
 * The engine's builder is the whole state of a build. Nothing is cached
 * across builds, because a shape describes live site state; what that
 * state is, a shape or an alter says with addCacheableDependency(). The
 * one thing kept is an access class's static answer, which by its own
 * cacheability depends on nothing but the account: asked once per
 * context and account kind (the account's roles and permissions), and
 * reused.
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
   * @param \Drupal\Core\Session\PermissionsHashGeneratorInterface|null $permissionsHashGenerator
   *   What tells two accounts' permissions apart, which a static access
   *   answer is kept by; NULL keeps none.
   */
  /**
   * What resolves a shape, with its alters, once asked for.
   */
  protected ?Shapes $shapes = NULL;

  /**
   * Whether the build under way is for the account alone (buildStatic()).
   */
  protected bool $accountAlone = FALSE;

  /**
   * The static access answers asked so far, by class, context and account kind.
   *
   * @var array<string, \Drupal\data_surface\Resource\AccessAnswer>
   */
  protected array $staticAnswers = [];

  public function __construct(
    protected readonly ResourceRegistry $registry,
    protected readonly TypedDataManagerInterface $typedDataManager,
    protected readonly ClassResolverInterface $classResolver,
    protected readonly AccountInterface $currentUser,
    protected readonly OperationArguments $operationArguments,
    protected readonly DerivedVariants $derivedVariants,
    protected readonly WatchedValueCheckInterface $watchedValueCheck,
    protected readonly ?PermissionsHashGeneratorInterface $permissionsHashGenerator = NULL,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function build(string $resource, OperationContext $context): DataSurfaceInterface {
    $alone = $this->accountAlone;
    $this->accountAlone = FALSE;
    try {
      return $this->buildResource($resource, $context, [], top: TRUE);
    }
    finally {
      $this->accountAlone = $alone;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildStatic(string $resource, OperationContext $context): DataSurfaceInterface {
    // Decision: see
    // docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability.
    $alone = $this->accountAlone;
    $this->accountAlone = TRUE;
    try {
      return $this->buildResource($resource, $context, [], top: TRUE);
    }
    finally {
      $this->accountAlone = $alone;
    }
  }

  /**
   * Builds one resource or structure, or one child inside its parent.
   *
   * @param string $resource
   *   The class, or its id; a derived resource has only its id.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   Where it is being asked for.
   * @param array<int, array{class: string, id: string, key: string, inputs: string[], held?: array|null}> $ancestry
   *   The parents this one is being built inside, outermost first, each
   *   with the key it is attached at, its own input keys and the storage
   *   that stores them (heldBy()); empty for one asked for on its own.
   * @param string|\Stringable|null $locked
   *   Why every key is held to what is stored, for a variant its access
   *   locked; NULL otherwise.
   * @param bool $top
   *   TRUE for the resource an operation is asked of on its own, which
   *   asks its own access class and states how its operation behaves;
   *   FALSE for a child, and for a structure mixed in, built by itself.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The sealed data surface.
   */
  protected function buildResource(string $resource, OperationContext $context, array $ancestry, string|\Stringable|null $locked = NULL, bool $top = FALSE): DataSurfaceInterface {
    $definition = $this->registry->getDefinition($resource);
    // Refuses two providers of one operation id, whichever is asked for.
    $this->registry->getOperations($definition->name());

    $builder = (new DataSurfaceBuilder())->setWatchedValueCheck($this->watchedValueCheck);
    $inputs = $this->blueprint($builder, $definition);
    $outputs = $this->blueprint($builder, $definition, TRUE);
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
        $instance->alterInputs($additions[$alter->module][0] ??= (new ResourceBlueprintAdditions($builder, $this->typedDataManager, $alter->module))->useShapes($this->shapes()));
      }
      if ($instance instanceof AltersOutputsInterface) {
        $instance->alterOutputs($additions[$alter->module][1] ??= (new ResourceBlueprintAdditions($builder, $this->typedDataManager, $alter->module, TRUE))->useShapes($this->shapes()));
      }
      if ($instance instanceof HasStorageLayoutInterface) {
        $builder->setThirdPartyLayout($alter->module, $instance->storageLayout());
      }
      $links[] = [$instance, $alter->refiners, $alter->module];
    }
    $declaring = ['' => $inputs, 'output ' => $outputs];
    foreach ($additions as $module => $blueprints) {
      foreach ($blueprints as $output => $blueprint) {
        $declaring[sprintf('%s.%s.', $output === 1 ? DataSurfaceBuilderInterface::THIRD_PARTY_OUTPUTS : 'third_party_settings', $module)] = $blueprint;
      }
    }
    static::assertShapesNarrowed($definition, $declaring);

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
    $ports = $inputs->ports();
    $frame = ['class' => $definition->name(), 'id' => $definition->id, 'key' => '', 'inputs' => $input_keys];
    $permission = $this->registry->getOperations($definition->name())[$context->id]->permission ?? NULL;
    $frame['permission'] = $permission === NULL ? NULL : static::resolvePermission($permission, $context);
    // Which keys the storage always holds, asked of the storage that
    // stores them: this resource's own, or its parent's, under the key
    // the parent stores it at.
    // Decision: see docs/decisions.md#a-key-its-storage-always-holds.
    $frame['held'] = $this->heldBy($definition, $context, $ancestry);
    $this->markAlwaysHeld($builder, $input_keys, $frame['held']);
    // Each structure mixed in, built by itself for its def and its access,
    // which locks its members or takes them back out; then nothing of the
    // owner's may name what that access may take away, or rule a member
    // out.
    // Decision: see docs/decisions.md#a-mixin-is-merged-flat.
    $mixins = $this->mixIn($builder, $definition, $inputs, $context, $frame, $input_keys, $attached, $lists);
    $owned_rules = [$inputs->rules()];
    $wirings = [];
    foreach (array_keys($inputs->attachments()) as $key) {
      $wirings[(string) $key] = $inputs->wiring((string) $key);
    }
    foreach ($additions as $contributor => $blueprints) {
      if (isset($blueprints[0])) {
        $owned_rules[] = $blueprints[0]->rules();
        foreach (array_keys($blueprints[0]->attachments()) as $key) {
          $wirings[(string) $key] = $blueprints[0]->wiring((string) $key);
        }
      }
    }
    $this->assertMixinsUnnamed($definition, $mixins, $links, array_merge(...$owned_rules), $wirings);
    $input_keys = array_values(array_filter($input_keys, static fn (string $key): bool => $builder->getDefinition($key) !== NULL));
    // Each alter's structure at its own mount, built before any refiner or
    // rule is bound, since the alter's glue reads its keys.
    // Decision: see docs/decisions.md#an-alter-attaches-at-its-own-mount.
    $readable = [];
    foreach ($additions as $contributor => $blueprints) {
      foreach (isset($blueprints[0]) ? $blueprints[0]->attachments() : [] as $key => $child) {
        $readable[(string) $contributor] = $this->mountStructure($builder, $definition, (string) $contributor, (string) $key, (string) $child, $blueprints[0]->wiring((string) $key), $context, $frame, $ancestry, $input_keys, $attached, $lists);
      }
    }
    // What each module mounted, so an alter's method may watch its own
    // module's keys and is told so when it names another's.
    $mounted_by = [];
    foreach ($additions as $contributor => $blueprints) {
      foreach ([...(isset($blueprints[0]) ? $blueprints[0]->keys() : []), ...($readable[(string) $contributor] ?? [])] as $key) {
        $mounted_by[$key][] = (string) $contributor;
      }
    }
    // What a coded or runtime refiner may rule out, which a declared rule
    // may then not require: the owner's keys by name, an alter's own by
    // `<module>.<key>`.
    $ruling = $mixins['ruling'];
    foreach ($links as [, $refiners, $module]) {
      foreach ($refiners as $refiner) {
        if ($refiner->mayNotApply) {
          $ruling[] = $module !== NULL && !in_array($refiner->key, $input_keys, TRUE) ? $module . '.' . $refiner->key : $refiner->key;
        }
      }
    }
    // Each structure mixed in first, in the owner's frame: its coded
    // refiners, its rules and its alters', on its own members, its ports
    // read at the owner's keys they are wired to.
    // Decision: see docs/decisions.md#a-mixin-is-merged-flat.
    foreach ($mixins['bind'] as $mixin) {
      $this->bindMixin($builder, $definition, $mixin, $context);
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
          $this->bindRefiners($builder, $definition, $instance, $refiners, $input_keys, $outputs->keys(), $attached, $ancestry === [] ? NULL : $ancestry[count($ancestry) - 1], $module, $extended, $mounted, $module === NULL ? [] : $mounted_by, $lists, $ports, $module === NULL ? [] : ($readable[$module] ?? []));
        }
      }
      $rules = $contributor === NULL ? $inputs->rules() : (isset($additions[$contributor][0]) ? $additions[$contributor][0]->rules() : []);
      $this->bindRules($builder, $definition, $rules, $contributor, $input_keys, $attached, $lists, $extended, $mounted, $contributor === NULL ? [] : $mounted_by, $ruling, $ports, $contributor === NULL ? [] : ($readable[$contributor] ?? []));
    }

    // The children, each through this same build step in its own frame:
    // its own shape, its own alters, its own refiners, and the context
    // it is invoked in, which its access may then narrow to locked or to
    // nothing at all, however it was invoked.
    foreach ($inputs->attachments() as $key => $child) {
      // Decision: see docs/decisions.md#a-port-is-a-live-sibling-value.
      $reads = $inputs->wiring((string) $key);
      $this->assertWiring($builder, $definition, NULL, (string) $key, (string) $child, $reads, $input_keys, $attached, $lists);
      [$attachment, $access, , $runtime] = $this->buildChild((string) $child, $context, $key, $frame, $ancestry);
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
      $attachment = $attachment->withReads($reads, static::wiredKeys($builder, $reads));
      $builder->attach($key, $attachment);
      $this->wirePorts($builder, $key . '.', $attachment, FALSE, $definition->id);
      if ($access !== NULL && $access->isLocked()) {
        $builder->lock($key);
      }
      if ($runtime) {
        $builder->markRuntimeAccess($key);
      }
    }
    foreach ($slots as $key => ['by' => $by, 'children' => $children]) {
      $variants = [];
      $withheld = [];
      $runtime_variant = FALSE;
      foreach ($children as $value => $child) {
        // Nothing wires a variant's ports yet.
        $this->assertWiring($builder, $definition, NULL, $key, $child, [], $input_keys, $attached, $lists, 'variant');
        [$attachment, $access, , $runtime] = $this->buildChild($child, $context, $key, $frame, $ancestry, TRUE);
        if ($access !== NULL) {
          $builder->addCacheableDependency($access);
        }
        // A variant offered here that only the subject decides may be
        // withheld once it is known: the slot says so.
        $runtime_variant = $runtime_variant || $runtime;
        // A locked variant is offered, built locked whole.
        // Decision: see
        // docs/decisions.md#a-variant-is-open-locked-or-not-offered.
        if ($attachment === NULL) {
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
      if ($runtime_variant) {
        $builder->markRuntimeAccess($key);
      }
    }
    // Each collection's child, built once for the list: every item is
    // invoked in the one context its operation resolves, and its access
    // answers once for the list, since field access is per field and not
    // per delta.
    // Decision: see docs/decisions.md#access-once-per-list.
    foreach ($inputs->lists() as $key => $child) {
      // Nothing wires an item's ports yet.
      $this->assertWiring($builder, $definition, NULL, $key, $child, [], $input_keys, $attached, $lists, 'collection');
      [$attachment, $access, $child_context, $runtime] = $this->buildChild($child, $context, $key, $frame, $ancestry);
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
      if ($runtime) {
        $builder->markRuntimeAccess($key);
      }
    }

    // Decision: see docs/decisions.md#an-operation-states-how-it-behaves.
    if ($top && ($this->registry->getOperations($definition->name())[$context->id]->readOnly ?? FALSE)) {
      $builder->markChangesNothing();
    }
    // Decision: see docs/decisions.md#locked-at-the-top.
    $locked ??= $top ? $this->lockedAtTheTop($builder, $definition, $context) : NULL;
    if ($locked !== NULL) {
      $builder->lockWhole($locked);
    }
    return $builder->seal();
  }

  /**
   * Asks a resource asked for on its own whether its access locks it whole.
   *
   * Only a class whose access() may return an AccessAnswer can answer
   * locked, so one declared to return core's answer is not asked here:
   * its answer is the route's and the tool's, as before. Built for the
   * account alone, a runtime answer is set aside, as a child's is.
   *
   * @param \Drupal\data_surface\DataSurfaceBuilderInterface $builder
   *   The builder, which the answer's cacheability reaches.
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   Its context.
   *
   * @return string|null
   *   Why it is locked, or NULL when it is not.
   */
  protected function lockedAtTheTop(DataSurfaceBuilderInterface $builder, ResourceDefinition $definition, OperationContext $context): ?string {
    if ($definition->access === NULL || !static::mayLock($definition->access)) {
      return NULL;
    }
    $answer = $this->ask($definition, $context, $this->currentUser);
    if ($this->accountAlone && !$answer->isStatic()) {
      return NULL;
    }
    $builder->addCacheableDependency($answer);
    return $answer->isLocked() ? static::lockReason($answer) : NULL;
  }

  /**
   * Answers whether an access class can answer locked at all.
   *
   * @param string $class
   *   The access class.
   *
   * @return bool
   *   FALSE when its access() is declared to return core's answer alone.
   */
  protected static function mayLock(string $class): bool {
    $type = (new \ReflectionMethod($class, 'access'))->getReturnType();
    $named = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];
    foreach ($named as $one) {
      if (!$one instanceof \ReflectionNamedType || is_a($one->getName(), AccessAnswer::class, TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Says why an answer locked, in the sentence a caller is shown.
   *
   * @param \Drupal\data_surface\Resource\AccessAnswer $answer
   *   A locked answer.
   *
   * @return string
   *   Its own reason, or one that says it was locked, untranslated as
   *   every access reason is.
   */
  protected static function lockReason(AccessAnswer $answer): string {
    return $answer->getReason() ?? 'Its access answered that it may be seen and not changed.';
  }

  /**
   * Finds the storage that stores a resource's keys, and where.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure being built.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   Its context.
   * @param array $ancestry
   *   Its parents, outermost first, each with what held()'s answer was
   *   for it.
   *
   * @return array{storage: \Drupal\data_surface\Resource\KnowsClearableKeysInterface, context: \Drupal\data_surface\Resource\OperationContext, prefix: string}|null
   *   The storage, the context it is asked in and the path under which it
   *   stores these keys; NULL when no storage that can say stores them.
   */
  protected function heldBy(ResourceDefinition $definition, OperationContext $context, array $ancestry): ?array {
    if ($definition->storage !== NULL) {
      $storage = $this->instance((string) $definition->storage, ResourceStorageInterface::class);
      return $storage instanceof KnowsClearableKeysInterface
        ? ['storage' => $storage, 'context' => $context, 'prefix' => '']
        : NULL;
    }
    $parent = $ancestry === [] ? NULL : $ancestry[count($ancestry) - 1];
    $held = $parent['held'] ?? NULL;
    if (!is_array($held) || !isset($parent['key'])) {
      return NULL;
    }
    return ['prefix' => $held['prefix'] . $parent['key'] . '.'] + $held;
  }

  /**
   * Marks each optional key its storage always holds a value for.
   *
   * A plain scalar or list key only: a map holds its keys, which say for
   * themselves, and a secret keeps what it holds when sent nothing.
   *
   * @param \Drupal\data_surface\DataSurfaceBuilderInterface $builder
   *   The builder holding the keys.
   * @param string[] $keys
   *   The plain input keys.
   * @param array|null $held
   *   What heldBy() answered.
   */
  protected function markAlwaysHeld(DataSurfaceBuilderInterface $builder, array $keys, ?array $held): void {
    if ($held === NULL) {
      return;
    }
    foreach ($keys as $key) {
      $definition = $builder->getDefinition($key);
      if ($definition === NULL || $definition->isRequired() || $definition instanceof ComplexDataDefinitionInterface || DefinitionMetadata::isSecret($definition)) {
        continue;
      }
      if ($held['storage']->mayClear($held['context'], $held['prefix'] . $key) === FALSE) {
        DefinitionMetadata::setAlwaysHeld($definition);
      }
    }
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
   * @param bool $whole
   *   TRUE for a slot's variant, which a locked answer builds locked whole,
   *   since the parent's key is the slot's and not the variant's alone.
   *
   * @return array{0: \Drupal\data_surface\Attachment|null, 1: \Drupal\data_surface\Resource\AccessAnswer|null, 2: \Drupal\data_surface\Resource\OperationContext, 3: bool}
   *   The sealed child, or NULL when its access omits it; that access
   *   answer, or NULL for a child that invokes no operation of its own and
   *   has no access class, which is open and was asked nothing; the
   *   context the child was invoked in; and whether, built for the account
   *   alone, a runtime answer was set aside, so the key is marked.
   */
  protected function buildChild(string $child, OperationContext $context, string $key, array $frame, array $ancestry, bool $whole = FALSE): array {
    $definition = $this->registry->getDefinition($child);
    [$child_context, $operation] = $this->invokeChild($definition, $context, $key, $frame['id']);
    [$access, $runtime] = $this->childAccess($definition, $operation, $child_context, $this->currentUser, $frame['permission'] ?? NULL);
    if ($access !== NULL && $access->isOmitted()) {
      return [NULL, $access, $child_context, FALSE];
    }
    $frame['key'] = $key;
    return [
      new Attachment(
        $this->buildResource($definition->name(), $child_context, [...$ancestry, $frame], $whole && $access?->isLocked() ? static::lockReason($access) : NULL),
        $definition->name(),
        $definition->id,
      ),
      $access,
      $child_context,
      $runtime,
    ];
  }

  /**
   * Builds each structure mixed in by itself, and settles its members.
   *
   * A mixed-in structure's members are the owner's keys already, declared
   * through its blueprint where with() stands. Here each is checked (its
   * ports wired to the owner's keys), built by itself in the owner's
   * context for its def and asked its access: omitted takes its members
   * back out, locked holds them to what is stored, and what a structure
   * it mixes in itself answered inside it is read off its own build.
   *
   * Decision: see docs/decisions.md#a-mixin-is-merged-flat.
   *
   * @param \Drupal\data_surface\DataSurfaceBuilderInterface $builder
   *   The owner's builder.
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The owner.
   * @param \Drupal\data_surface\ResourceBuild\ResourceBlueprint $inputs
   *   The owner's input blueprint.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The owner's context.
   * @param array $frame
   *   The owner, with the permission its operation asks.
   * @param string[] $input_keys
   *   The owner's plain input keys, every structure's members among them.
   * @param string[] $attached
   *   The owner's keys holding a child or a slot.
   * @param string[] $lists
   *   The owner's collection keys.
   *
   * @return array{bind: array<int, array{definition: \Drupal\data_surface\ResourceBuild\ResourceDefinition, blueprint: \Drupal\data_surface\ResourceBuild\ResourceBlueprint, reads: array<string, string>, keys: string[]}>, members: array<string, string>, guarded: array<string, string>, ruling: string[]}
   *   What to bind in the owner's frame, innermost first, each with the
   *   members still there; every member, and every member of a structure
   *   with an access class, each mapped to its structure's id; and the
   *   members a structure's coded refiner may rule out.
   */
  protected function mixIn(DataSurfaceBuilderInterface $builder, ResourceDefinition $definition, ResourceBlueprint $inputs, OperationContext $context, array $frame, array $input_keys, array $attached, array $lists): array {
    $mixed = ['bind' => [], 'members' => [], 'guarded' => [], 'ruling' => []];
    foreach ($inputs->mixins() as ['class' => $class, 'reads' => $reads, 'blueprint' => $blueprint]) {
      $structure = $this->registry->getDefinition($class);
      $this->assertMixinWiring($builder, $definition, $structure, $blueprint, $reads, $input_keys, $attached, $lists);
      $mixed = static::mixedMembers($mixed, $blueprint, $structure, fn (string $name): ResourceDefinition => $this->registry->getDefinition($name));
      // By itself, as if on its own: what its def is written from, in the
      // context its owner's hands it, and what its access answers there.
      $child_context = static::childContext($context, $structure->id);
      [$access, $runtime] = $this->childAccess($structure, NULL, $child_context, $this->currentUser, $frame['permission'] ?? NULL);
      if ($access !== NULL) {
        $builder->addCacheableDependency($access);
      }
      if ($access !== NULL && $access->isOmitted()) {
        foreach ($blueprint->keys() as $key) {
          $builder->withdraw($key);
        }
        continue;
      }
      $built = $this->buildResource($structure->name(), $child_context, []);
      $map = $built->getDefinitions();
      $locked = $access !== NULL && $access->isLocked();
      $present = [];
      foreach ($blueprint->keys() as $key) {
        if (!$map->has($key)) {
          // A structure it mixes in itself, which that one's access left
          // out inside it.
          $builder->withdraw($key);
          continue;
        }
        if ($locked || $map->isHeld($key)) {
          $builder->hold($key);
        }
        // Decided once the subject is known, here or by a structure it
        // mixes in itself: marked, built for the account alone.
        if ($runtime || $map->isRuntimeAccess($key)) {
          $builder->markRuntimeAccess($key);
        }
        $present[] = $key;
      }
      $builder->addMixin(new Mixin(new Attachment($built, $structure->name(), $structure->id), $present, $reads));
      array_push($mixed['bind'], ...$this->mixinBindings($structure, $blueprint, $reads, $present));
    }
    return $mixed;
  }

  /**
   * Lists a structure's members, and those its access class may take away.
   *
   * @param array $mixed
   *   What mixIn() collects so far.
   * @param \Drupal\data_surface\ResourceBuild\ResourceBlueprint $blueprint
   *   The blueprint the structure was declared through.
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $structure
   *   The structure.
   * @param callable(string): \Drupal\data_surface\ResourceBuild\ResourceDefinition $definitions
   *   Reads a structure it mixes in itself.
   *
   * @return array
   *   The same, with this structure's members and those of every
   *   structure it mixes in.
   */
  protected static function mixedMembers(array $mixed, ResourceBlueprint $blueprint, ResourceDefinition $structure, callable $definitions): array {
    foreach ($blueprint->mixins() as ['class' => $class, 'blueprint' => $inner]) {
      $mixed = static::mixedMembers($mixed, $inner, $definitions($class), $definitions);
    }
    foreach ($blueprint->keys() as $key) {
      $mixed['members'][$key] ??= $structure->id;
      if ($structure->access !== NULL) {
        $mixed['guarded'][$key] ??= $structure->id;
      }
    }
    foreach ($structure->refiners as $refiner) {
      if ($refiner->mayNotApply) {
        $mixed['ruling'][] = $refiner->key;
      }
    }
    return $mixed;
  }

  /**
   * Lists what to bind in the owner's frame for one structure mixed in.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $structure
   *   The structure.
   * @param \Drupal\data_surface\ResourceBuild\ResourceBlueprint $blueprint
   *   The blueprint it was declared through.
   * @param array<string, string> $reads
   *   Its ports, wired to the owner's keys.
   * @param string[] $present
   *   The members still there.
   *
   * @return array<int, array{definition: \Drupal\data_surface\ResourceBuild\ResourceDefinition, blueprint: \Drupal\data_surface\ResourceBuild\ResourceBlueprint, reads: array<string, string>, keys: string[]}>
   *   A structure it mixes in itself first, unless its members are gone,
   *   then this one.
   */
  protected function mixinBindings(ResourceDefinition $structure, ResourceBlueprint $blueprint, array $reads, array $present): array {
    $bindings = [];
    foreach ($blueprint->mixins() as ['class' => $class, 'reads' => $inner_reads, 'blueprint' => $inner]) {
      if (array_diff($inner->keys(), $present) !== []) {
        continue;
      }
      // Its ports are wired to keys of the structure mixing it in, which
      // are the owner's keys now, under the same names.
      array_push($bindings, ...$this->mixinBindings($this->registry->getDefinition($class), $inner, $inner_reads, $present));
    }
    $bindings[] = [
      'definition' => $structure,
      'blueprint' => $blueprint,
      'reads' => $reads,
      'keys' => array_values(array_intersect($blueprint->keys(), $present)),
    ];
    return $bindings;
  }

  /**
   * Refuses a wiring of a mixed-in structure's ports its owner cannot honour.
   *
   * Every port is wired, to a plain input of the owner's of the port's
   * type that the structure did not bring itself: in the owner's frame a
   * port is read as the key it is wired to, so one left unwired would
   * have nothing to be read as.
   *
   * Decision: see docs/decisions.md#a-mixin-is-merged-flat.
   *
   * @param \Drupal\data_surface\DataSurfaceBuilderInterface $builder
   *   The owner's builder.
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The owner.
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $structure
   *   The structure.
   * @param \Drupal\data_surface\ResourceBuild\ResourceBlueprint $blueprint
   *   The blueprint it was declared through.
   * @param array<string, string> $reads
   *   The wiring.
   * @param string[] $input_keys
   *   The owner's plain input keys.
   * @param string[] $attached
   *   The owner's keys holding a child or a slot.
   * @param string[] $lists
   *   The owner's collection keys.
   *
   * @throws \LogicException
   *   Naming the owner, the port and what is wrong with the wiring.
   */
  protected function assertMixinWiring(DataSurfaceBuilderInterface $builder, ResourceDefinition $definition, ResourceDefinition $structure, ResourceBlueprint $blueprint, array $reads, array $input_keys, array $attached, array $lists): void {
    $ports = $blueprint->ports();
    foreach ($reads as $port => $wired) {
      if (!isset($ports[$port])) {
        throw new \LogicException(sprintf('%s wires the "%s" port of %s, mixed in, which %s does not read; it reads %s.', $definition->id, $port, $structure->id, $structure->id, $ports === [] ? 'no port' : implode(', ', array_keys($ports))));
      }
      $problem = match (TRUE) {
        in_array($wired, $blueprint->keys(), TRUE) => sprintf('which %s brought itself: a port reads a key of the owner it is mixed into, and its members are its own', $structure->id),
        in_array($wired, $attached, TRUE), in_array($wired, $lists, TRUE) => 'which holds a child: a port reads one value',
        !in_array($wired, $input_keys, TRUE) => sprintf('which %s does not declare as an input: a port reads a key of its owner, by name', $definition->id),
        default => NULL,
      };
      if ($problem !== NULL) {
        throw new \LogicException(sprintf('%s wires the "%s" port of %s, mixed in, to "%s", %s.', $definition->id, $port, $structure->id, $wired, $problem));
      }
      $type = $builder->getDefinition($wired)?->getDataType();
      if ($ports[$port]->type !== 'any' && $type !== $ports[$port]->type) {
        throw new \LogicException(sprintf('%s wires the "%s" port of %s, mixed in, which reads %s %s, to "%s", which holds %s %s.', $definition->id, $port, $structure->id, static::article((string) $ports[$port]->type), $ports[$port]->type, $wired, static::article((string) $type), $type));
      }
      $unshaped = static::unshaped($ports[$port], $builder->getDefinition($wired));
      if ($unshaped !== NULL) {
        throw new \LogicException(sprintf('%s wires the "%s" port of %s, mixed in, to "%s", %s.', $definition->id, $port, $structure->id, $wired, $unshaped));
      }
    }
    foreach ($ports as $port) {
      if (!isset($reads[$port->name])) {
        throw new \LogicException(sprintf(
          '%s mixes %s in without wiring its "%s" port: with(..., reads: [\'%s\' => \'<key>\']) names the key of %s it reads. A mixed-in structure\'s refiners read each port as the owner\'s key it is wired to, so every port is wired, required or not.',
          $definition->id,
          $structure->id,
          $port->name,
          $port->name,
          $definition->id,
        ));
      }
    }
  }

  /**
   * Refuses what of the owner's names a member it may not depend on.
   *
   * A mixed-in structure's members are the owner's keys, so the owner's
   * refiners and rules, and its alters', may watch them and narrow them
   * as their own, with two exceptions. Whether a member applies is the
   * structure's to say: its def, which every owner shares, says where it
   * is required. And nothing of the owner's may name a member of a
   * structure that has an access class: what that access may take away
   * for one account, nothing may depend on for every account.
   *
   * Decision: see docs/decisions.md#a-mixin-is-merged-flat.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The owner.
   * @param array $mixins
   *   What mixIn() answered.
   * @param array<int, array{0: object|string, 1: \Drupal\data_surface\ResourceBuild\RefinerDefinition[], 2: string|null}> $links
   *   The owner's refiners and its alters'.
   * @param \Drupal\data_surface\Refinement\Rule[] $rules
   *   The owner's rules and its alters'.
   * @param array<string, array<string, string>> $wirings
   *   The ports of each child the owner or an alter attached, wired.
   *
   * @throws \LogicException
   *   Naming what names the member, and the structure.
   */
  protected function assertMixinsUnnamed(ResourceDefinition $definition, array $mixins, array $links, array $rules, array $wirings): void {
    $guarded = $mixins['guarded'];
    $guard = static fn (string $what, string $key): \LogicException => new \LogicException(sprintf(
      '%s names "%s", a member of %s, which is mixed into %s and has an access class. What that access may take away for one account, nothing of the owner\'s may depend on: read it inside %s, or drop the access class.',
      $what,
      $key,
      $guarded[$key],
      $definition->id,
      $guarded[$key],
    ));
    $ruled = static fn (string $what, string $key): \LogicException => new \LogicException(sprintf(
      '%s may answer that "%s", a member of %s mixed into %s, does not apply. Whether a mixed-in structure\'s member applies is the structure\'s to say: its def, which every owner shares, says where it is required.',
      $what,
      $key,
      $mixins['members'][$key],
      $definition->id,
    ));
    foreach ($links as [, $refiners]) {
      foreach ($refiners as $refiner) {
        foreach ([$refiner->key, ...$refiner->watched()] as $key) {
          if (isset($guarded[$key])) {
            throw $guard($refiner->describe(), $key);
          }
        }
        if ($refiner->mayNotApply && isset($mixins['members'][$refiner->key])) {
          throw $ruled($refiner->describe(), $refiner->key);
        }
      }
    }
    foreach ($rules as $rule) {
      $name = sprintf('narrow("%s") in %s', $rule->key, $definition->id);
      foreach ([$rule->key, ...($rule->condition()?->keys() ?? [])] as $key) {
        if (isset($guarded[$key])) {
          throw $guard($name, $key);
        }
      }
      if ($rule->rulesOut() && isset($mixins['members'][$rule->key])) {
        throw $ruled($name, $rule->key);
      }
    }
    foreach ($wirings as $at => $reads) {
      foreach ($reads as $port => $key) {
        if (isset($guarded[$key])) {
          throw $guard(sprintf('The "%s" port of the child at "%s"', $port, $at), $key);
        }
      }
    }
  }

  /**
   * Binds one mixed-in structure's refiners and rules in its owner's frame.
   *
   * Its coded refiners, its declared rules, then each of its alters' that
   * applies in the context: refining and narrowing only the structure's
   * own members, watching and reading only those and its ports, each port
   * read at the owner's key it is wired to. What the alter rewords or
   * offers is said through a blueprint that names the structure's members
   * alone and mounts nothing.
   *
   * Decision: see docs/decisions.md#a-mixin-is-merged-flat.
   *
   * @param \Drupal\data_surface\DataSurfaceBuilderInterface $builder
   *   The owner's builder.
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The owner.
   * @param array{definition: \Drupal\data_surface\ResourceBuild\ResourceDefinition, blueprint: \Drupal\data_surface\ResourceBuild\ResourceBlueprint, reads: array<string, string>, keys: string[]} $mixin
   *   The structure, as mixIn() lists it.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The owner's context, which says which alters apply.
   */
  protected function bindMixin(DataSurfaceBuilderInterface $builder, ResourceDefinition $definition, array $mixin, OperationContext $context): void {
    $structure = $mixin['definition'];
    $keys = $mixin['keys'];
    $ports = $mixin['blueprint']->ports();
    $reads = $mixin['reads'];
    $ruling = [];
    foreach ($structure->refiners as $refiner) {
      if ($refiner->mayNotApply) {
        $ruling[] = $refiner->key;
      }
    }
    $this->bindRefiners($builder, $structure, static::resourceClass($structure), $structure->refiners, $keys, [], ports: $ports, translated: $reads);
    $this->bindRules($builder, $structure, $mixin['blueprint']->rules(), NULL, $keys, [], [], [], [], [], $ruling, $ports, translated: $reads);
    foreach ($structure->alters as $alter) {
      if (!$alter->appliesIn($context->id)) {
        continue;
      }
      $instance = $this->classResolver->getInstanceFromDefinition($alter->class);
      $within = [$structure->id, $definition->id, $keys];
      $additions = (new ResourceBlueprintAdditions($builder, $this->typedDataManager, $alter->module, FALSE, $within))->useShapes($this->shapes());
      if ($instance instanceof AltersInputsInterface) {
        $instance->alterInputs($additions);
      }
      if ($instance instanceof AltersOutputsInterface) {
        $instance->alterOutputs((new ResourceBlueprintAdditions($builder, $this->typedDataManager, $alter->module, TRUE, $within))->useShapes($this->shapes()));
      }
      $this->bindRefiners($builder, $structure, $instance, $alter->refiners, $keys, [], [], NULL, $alter->module, $additions->extended(), ports: $ports, translated: $reads);
      $this->bindRules($builder, $structure, $additions->rules(), $alter->module, $keys, [], [], $additions->extended(), [], [], $ruling, $ports, translated: $reads);
    }
  }

  /**
   * Builds the structure an alter attached at its own mount, and fixes it.
   *
   * The alter is an adapter: the structure's keys are its mount's, stored
   * under its module's name as any key an alter mounts, and the structure
   * refines them there in its own frame, its ports reading the owner's
   * keys the alter wired them to. Built before any refiner or rule is
   * bound, because the alter's glue may read the structure's keys.
   *
   * Decision: see docs/decisions.md#an-alter-attaches-at-its-own-mount.
   *
   * @param \Drupal\data_surface\DataSurfaceBuilderInterface $builder
   *   The owner's builder.
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The owner.
   * @param string $module
   *   The alter's module.
   * @param string $key
   *   The mount, `third_party_settings.<module>`.
   * @param string $child
   *   The structure, by name.
   * @param array<string, string> $reads
   *   Its ports, each wired to an owner's key.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The owner's context.
   * @param array $frame
   *   The owner, as buildChild() reads it.
   * @param array $ancestry
   *   The owner's own ancestry.
   * @param string[] $input_keys
   *   The owner's plain input keys.
   * @param string[] $attached
   *   The owner's keys holding a child or a slot.
   * @param string[] $lists
   *   The owner's collection keys.
   *
   * @return string[]
   *   The structure's keys, which the alter's glue may read.
   *
   * @throws \LogicException
   *   For a resource with storage or identity, a structure holding a
   *   child, a wiring the owner cannot honour, or an access answer other
   *   than open.
   */
  protected function mountStructure(DataSurfaceBuilderInterface $builder, ResourceDefinition $definition, string $module, string $key, string $child, array $reads, OperationContext $context, array $frame, array $ancestry, array $input_keys, array $attached, array $lists): array {
    $structure = $this->registry->getDefinition($child);
    if ($structure->storage !== NULL || $structure->identity !== []) {
      throw new \LogicException(sprintf(
        'The %s alter of %s attaches %s at its mount, %s, and %s names %s. A mount holds a structure, which the owner\'s storage stores under the module\'s name.',
        $module,
        $definition->id,
        $structure->id,
        $key,
        $structure->id,
        $structure->storage !== NULL ? 'storage of its own' : 'identity keys',
      ));
    }
    $this->assertWiring($builder, $definition, $module, $key, $child, $reads, $input_keys, $attached, $lists);
    [$attachment, $access] = $this->buildChild($child, $context, $key, $frame, $ancestry);
    if ($attachment === NULL || ($access !== NULL && !$access->isOpen())) {
      throw new \LogicException(sprintf(
        'The access of %s, attached at %s by the %s alter of %s, answered %s. A structure at a mount that is locked or left out is not built yet.',
        $structure->id,
        $key,
        $module,
        $definition->id,
        $access?->answer() ?? 'omitted',
      ));
    }
    if ($access !== NULL) {
      $builder->addCacheableDependency($access);
    }
    foreach ($attachment->child->getDefinitions()->entries() as $name => $entry) {
      if ($entry->isNested() || $entry->list !== NULL || $entry->mounted !== []) {
        throw new \LogicException(sprintf(
          'The %s alter of %s attaches %s at its mount, %s, and %s holds a child at "%s". A structure at a mount holds keys and maps, not children, for now.',
          $module,
          $definition->id,
          $structure->id,
          $key,
          $structure->id,
          $name,
        ));
      }
    }
    $attachment = $attachment->withReads($reads, static::wiredKeys($builder, $reads));
    $builder->mountStructure($module, $attachment);
    $this->wirePorts($builder, $key . '.', $attachment, TRUE, sprintf('the %s alter of %s', $module, $definition->id));
    return $attachment->child->getDefinitions()->names();
  }

  /**
   * Refuses a wiring of a child's ports that its container cannot honour.
   *
   * Each port wired is one the child reads, to a key of the container's
   * own (an owner's plain input, by name) outside the child, of the
   * port's type; every port the child requires is wired. A slot's variant
   * and a collection's item are wired by nothing yet, so neither may
   * require a port.
   *
   * Decision: see docs/decisions.md#a-port-is-a-live-sibling-value.
   *
   * @param \Drupal\data_surface\DataSurfaceBuilderInterface $builder
   *   The container's builder, holding its keys.
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The container: the owner, or the owner an alter adapts.
   * @param string|null $module
   *   The alter's module, or NULL for the owner itself.
   * @param string $key
   *   The key the child sits at.
   * @param string $child
   *   The child, by name.
   * @param array<string, string> $reads
   *   The wiring.
   * @param string[] $input_keys
   *   The owner's plain input keys.
   * @param string[] $attached
   *   The owner's keys holding a child or a slot.
   * @param string[] $lists
   *   The owner's collection keys.
   * @param string $as
   *   How the child is held: attach, variant or collection.
   *
   * @throws \LogicException
   *   Naming the container, the port and what is wrong with the wiring.
   */
  protected function assertWiring(DataSurfaceBuilderInterface $builder, ResourceDefinition $definition, ?string $module, string $key, string $child, array $reads, array $input_keys, array $attached, array $lists, string $as = 'attach'): void {
    [$ports, $declared] = $this->portsOf($child);
    if ($ports === [] && $reads === []) {
      return;
    }
    $who = $module === NULL ? $definition->id : sprintf('The %s alter of %s', $module, $definition->id);
    $id = $this->registry->getDefinition($child)->id;
    foreach ($reads as $port => $wired) {
      if (!isset($ports[$port])) {
        throw new \LogicException(sprintf('%s wires the "%s" port of %s at "%s", which %s does not read; it reads %s.', $who, $port, $id, $key, $id, $ports === [] ? 'no port' : implode(', ', array_keys($ports))));
      }
      $inside = $wired === $key || str_starts_with($wired, $key . '.') || (in_array($wired, $declared, TRUE) && !in_array($wired, $input_keys, TRUE));
      $problem = match (TRUE) {
        $inside => 'which is inside the child itself: a port reads its container\'s key, and what the child holds is its own',
        in_array($wired, $attached, TRUE), in_array($wired, $lists, TRUE) => 'which holds a child: a port reads one value',
        !in_array($wired, $input_keys, TRUE) => sprintf('which %s does not declare as an input: a port reads a key of its container, by name', $definition->id),
        default => NULL,
      };
      if ($problem !== NULL) {
        throw new \LogicException(sprintf('%s wires the "%s" port of %s at "%s" to "%s", %s.', $who, $port, $id, $key, $wired, $problem));
      }
      $type = $builder->getDefinition($wired)?->getDataType();
      if ($ports[$port]->type !== 'any' && $type !== $ports[$port]->type) {
        throw new \LogicException(sprintf('%s wires the "%s" port of %s at "%s", which reads %s %s, to "%s", which holds %s %s.', $who, $port, $id, $key, static::article((string) $ports[$port]->type), $ports[$port]->type, $wired, static::article((string) $type), $type));
      }
      $unshaped = static::unshaped($ports[$port], $builder->getDefinition($wired));
      if ($unshaped !== NULL) {
        throw new \LogicException(sprintf('%s wires the "%s" port of %s at "%s" to "%s", %s.', $who, $port, $id, $key, $wired, $unshaped));
      }
    }
    foreach ($ports as $port) {
      if (!$port->required || isset($reads[$port->name])) {
        continue;
      }
      throw new \LogicException($as === 'attach'
        ? sprintf('%s attaches %s at "%s" without wiring the "%s" port it requires: attach(\'%s\', ..., reads: [\'%s\' => \'<key>\']) names the key of %s it reads.', $who, $id, $key, $port->name, $key, $port->name, $definition->id)
        : sprintf('%s fills the "%s" %s of %s and requires the "%s" port, which nothing wires: a port is wired by attach(), and a %s is wired by nothing yet.', $id, $key, $as === 'variant' ? 'slot' : 'collection', $who, $port->name, $as === 'variant' ? 'slot\'s variant' : 'collection\'s item'));
    }
  }

  /**
   * Says why a key cannot be wired to a port that reads a shape.
   *
   * A port that reads a shape is handed a value its readers may trust to
   * be of that shape, so the key wired to it is declared with the shape:
   * narrowed or not, it allows nothing the shape refuses. A key of the
   * shape's type alone could hold anything of that type. A port that reads
   * a type takes a key of that type, a shape's among them.
   *
   * Decision: see docs/decisions.md#a-shape-is-a-named-definition.
   *
   * @param \Drupal\data_surface\Port $port
   *   The port.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $wired
   *   The key wired to it.
   *
   * @return string|null
   *   Why not, or NULL when it may be.
   */
  protected static function unshaped(Port $port, ?DataDefinitionInterface $wired): ?string {
    if ($port->shape === NULL || $wired === NULL) {
      return NULL;
    }
    $shape = DefinitionMetadata::shapeOf($wired);
    if ($shape === $port->shape) {
      return NULL;
    }
    return sprintf(
      'which is declared with %s, and the port reads the shape %s: a port that reads a shape reads a key declared with it, add(\'<key>\', <shape>::class, ...)',
      $shape === NULL ? 'a type alone' : 'the shape ' . $shape,
      $port->shape,
    );
  }

  /**
   * Gets the container's key each port is wired to, as declared.
   *
   * @param \Drupal\data_surface\DataSurfaceBuilderInterface $builder
   *   The container's builder.
   * @param array<string, string> $reads
   *   The wiring.
   *
   * @return array<string, \Drupal\Core\TypedData\DataDefinitionInterface>
   *   Each wired key's definition, by port.
   */
  protected static function wiredKeys(DataSurfaceBuilderInterface $builder, array $reads): array {
    $wired = [];
    foreach ($reads as $port => $key) {
      $definition = $builder->getDefinition($key);
      if ($definition !== NULL) {
        $wired[$port] = DataSurface::deepClone($definition);
      }
    }
    return $wired;
  }

  /**
   * Reads what a child declares, without building it.
   *
   * @param string $child
   *   The child, by name.
   *
   * @return array{0: array<string, \Drupal\data_surface\Port>, 1: string[]}
   *   Its ports, by name, and every key it declares.
   */
  protected function portsOf(string $child): array {
    $definition = $this->registry->getDefinition($child);
    $blueprint = $this->blueprint(new DataSurfaceBuilder(), $definition);
    $this->fillBlueprints($definition, $blueprint);
    return [$blueprint->ports(), $blueprint->declared()];
  }

  /**
   * Gets the article a type name is read with.
   *
   * @param string $type
   *   The type.
   *
   * @return string
   *   "an" or "a".
   */
  protected static function article(string $type): string {
    return in_array(strtolower($type[0] ?? ''), ['a', 'e', 'i', 'o', 'u'], TRUE) ? 'an' : 'a';
  }

  /**
   * Records, in the container, what a child's ports and keys move.
   *
   * The child refines in its own frame, under its own names; the
   * container is told which of its keys each port reads, as edges at the
   * child's paths in the container's frame, so a form rebuilds the child
   * when one of them changes, a client refines, the cycle check follows
   * the wiring, and a refusal names the container's key. For a structure
   * at a mount, whose keys the container accepts as its own, the child's
   * edges between its own keys are recorded at their paths too.
   *
   * Decision: see docs/decisions.md#a-port-is-a-live-sibling-value.
   *
   * @param \Drupal\data_surface\DataSurfaceBuilderInterface $builder
   *   The container's builder.
   * @param string $prefix
   *   The child's path in the container's frame, with its trailing dot.
   * @param \Drupal\data_surface\Attachment $attachment
   *   The child, wired.
   * @param bool $internal
   *   Whether the child's edges between its own keys are recorded too.
   * @param string $container
   *   The container, in words, for a cycle's refusal.
   */
  protected function wirePorts(DataSurfaceBuilderInterface $builder, string $prefix, Attachment $attachment, bool $internal, string $container): void {
    $map = $attachment->child->getDefinitions();
    $ports = $map->ports();
    $kinds = [
      DataSurfaceInterface::CODED => [FALSE, FALSE],
      DataSurfaceInterface::DECLARED => [FALSE, TRUE],
      DataSurfaceInterface::RUNTIME => [TRUE, FALSE],
    ];
    foreach ($map->refinementPaths() as $target => $dependencies) {
      $entry = $map->entry(explode('.', (string) $target)[0]);
      foreach ($dependencies as $dependency) {
        $port = isset($ports[$dependency]);
        if ($port ? !isset($attachment->reads[$dependency]) : !$internal) {
          // An unwired port holds nothing, and moves nothing.
          continue;
        }
        $mapped = $port ? $attachment->reads[$dependency] : $prefix . $dependency;
        $wiring = $port ? sprintf('the "%s" port of %s, which %s wires to "%s"', $dependency, $attachment->id ?? $attachment->source, $container, $mapped) : NULL;
        $mask = $entry === NULL ? DataSurfaceInterface::CODED : ($entry->watchedBy[$dependency] ?? DataSurfaceInterface::CODED);
        foreach ($kinds as $kind => [$runtime, $declared]) {
          if (($mask & $kind) !== 0) {
            $builder->addRefinement($prefix . $target, [$mapped], $runtime, $declared, $wiring);
          }
        }
      }
    }
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
   * Built for the account alone (buildStatic()), an access class's answer
   * that is runtime is set aside: the permission's answer stands alone,
   * carrying the cache contexts the set-aside answer read, since which
   * kind it is was decided by them, and the key is marked.
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
   * @return array{0: \Drupal\data_surface\Resource\AccessAnswer|null, 1: bool}
   *   Open, locked or omitted, with what the answer read, or NULL for a
   *   child with no operation and no access class, which stays open; and
   *   whether a runtime answer was set aside, which only a build for the
   *   account alone does.
   */
  protected function childAccess(ResourceDefinition $child, ?OperationDefinition $operation, OperationContext $context, AccountInterface $account, ?string $gate = NULL): array {
    // Decision: see docs/decisions.md#access-has-three-answers.
    // Decision: see docs/decisions.md#one-invocation-model-for-both-levels.
    if ($operation === NULL && $child->access === NULL) {
      return [NULL, FALSE];
    }
    $access = AccessAnswer::open();
    // Only an operation the build resolved asks its permission: a parent
    // that built the child's context itself took responsibility for entry.
    $resolved = $operation?->permission === NULL ? NULL : static::resolvePermission((string) $operation->permission, $context);
    // Decision: see docs/decisions.md#a-permission-a-child-cannot-name.
    if ($resolved !== NULL && $resolved !== $gate) {
      $result = AccessResult::allowedIfHasPermission($account, $resolved);
      if (!$result->isAllowed()) {
        $omitted = AccessAnswer::omitted(sprintf('The "%s" operation needs the "%s" permission.', $operation?->id, $resolved));
        return [$omitted->addCacheableDependency($result), FALSE];
      }
      $access->addCacheableDependency($result);
    }
    if ($child->access === NULL) {
      return [$access, FALSE];
    }
    $answer = $this->ask($child, $context, $account);
    // Decision: see
    // docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability.
    if ($this->accountAlone && !$answer->isStatic()) {
      return [$access->addCacheContexts($answer->getCacheContexts()), TRUE];
    }
    return [$access->andIf($answer), FALSE];
  }

  /**
   * Asks a resource's or structure's access class, as a child is asked.
   *
   * A static answer depends on nothing but the account, so it is asked
   * once for a context and an account kind (the account's roles and its
   * permissions) and kept. With assertions on, it is asked a second time
   * with the global container guarded (StaticRefinerContainer::guardAccess())
   * and must answer the same, static again, having asked for no service
   * that reads site state: an answer whose cacheability left out what it
   * read is refused, naming the class.
   *
   * Decision: see
   * docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure, which names an access class.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   Its context.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return \Drupal\data_surface\Resource\AccessAnswer
   *   The class's answer, read the way a child reads it.
   *
   * @throws \LogicException
   *   With assertions on, when a static answer asked again differs, or
   *   asks for a service that reads site state.
   */
  protected function ask(ResourceDefinition $definition, OperationContext $context, AccountInterface $account): AccessAnswer {
    $class = (string) $definition->access;
    $kept = $this->keptAs($class, $context, $account);
    if ($kept !== NULL && isset($this->staticAnswers[$kept])) {
      return $this->staticAnswers[$kept];
    }
    $instance = $this->instance($class, OperationAccessInterface::class);
    $answer = AccessAnswer::fromAnswer($instance->access($context, $account));
    if (!$answer->isStatic()) {
      return $answer;
    }
    assert($this->answersAgain($class, $instance, $context, $account, $answer));
    if ($kept !== NULL) {
      $this->staticAnswers[$kept] = $answer;
    }
    return $answer;
  }

  /**
   * Names what a static access answer is kept by.
   *
   * @param string $class
   *   The access class.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context it is asked in.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return string|null
   *   The key: the class, the context's id, whether it creates and what
   *   it knows, and the account's roles and permissions; NULL when none
   *   can be made, so nothing is kept.
   */
  protected function keptAs(string $class, OperationContext $context, AccountInterface $account): ?string {
    if ($this->permissionsHashGenerator === NULL) {
      return NULL;
    }
    $roles = $account->getRoles();
    sort($roles);
    try {
      $kind = [$roles, $this->permissionsHashGenerator->generate($account)];
      return hash('sha256', serialize([$class, $context->id, $context->creates, $context->known, $kind]));
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Asks an access class again, guarded, and holds it to its first answer.
   *
   * Called inside assert(), so only where assertions are on.
   *
   * @param string $class
   *   The access class.
   * @param \Drupal\data_surface\Resource\OperationAccessInterface $instance
   *   The access class as built.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   * @param \Drupal\data_surface\Resource\AccessAnswer $first
   *   What it answered first, which read static.
   *
   * @return bool
   *   TRUE, when it answers the same.
   *
   * @throws \LogicException
   *   When it answers otherwise, or asks for a service reading site state.
   */
  protected function answersAgain(string $class, OperationAccessInterface $instance, OperationContext $context, AccountInterface $account, AccessAnswer $first): bool {
    $again = AccessAnswer::fromAnswer(StaticRefinerContainer::guardAccess($class, static fn (): AccessResultInterface|AccessAnswer => $instance->access($context, $account)));
    if ($again->answer() !== $first->answer() || !$again->isStatic()) {
      throw new \LogicException(sprintf(
        'The access class %s answered %s, static by its cacheability, and asked again for the same account in the same context answered %s, %s. A static answer depends on the account alone and is the same every time it is asked; one that depends on anything else says so in its cacheability.',
        $class,
        $first->answer(),
        $again->answer(),
        $again->kind(),
      ));
    }
    return TRUE;
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
    // The operation's permission is its first static answer: one as
    // written depends on the account alone, which is why a listing can
    // declare it (OperationDefinition::staticPermission()).
    // Decision: see
    // docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability.
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
   * and locked are allowed and omitted is forbidden: a resource locked at
   * the top is built locked whole, shown and fixed, and its pipeline
   * refuses a submit (lockedAtTheTop()).
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
    $result = !$answer->isOmitted()
      ? AccessResult::allowed()
      : AccessResult::forbidden($answer->getReason() ?? sprintf('The access class answered %s.', $answer->answer()));
    return $result->addCacheableDependency($answer);
  }

  /**
   * Says whether any slot's variant discovered has an access class.
   *
   * Building a resource to find its children costs a build, so it is done
   * only when some child could refuse: a slot's variant chosen by a
   * locked deciding key is the only child that may (childAnswers()), and
   * a derived variant has no access class.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure being asked about.
   *
   * @return bool
   *   TRUE when some declared variant other than it names an access class.
   */
  protected function childrenMayAnswer(ResourceDefinition $definition): bool {
    foreach ($this->registry->getDefinitions() as $other) {
      foreach ($other->variants as $variants) {
        foreach ($variants as $class) {
          if ($class !== $definition->name() && $this->registry->hasDefinition($class) && $this->registry->getDefinition($class)->access !== NULL) {
            return TRUE;
          }
        }
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
    $definition = $this->registry->getDefinition($resource);
    $this->fillBlueprints($definition, $this->blueprint($builder, $definition));
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
   * Lists what the build left out, which the parent stores.
   *
   * The keys the owner's blueprint attaches a child or a collection at, or
   * a structure it mixes in brought, that the built resource does not
   * carry: only access omits a key. Inside a child with no storage of its
   * own, which this storage stores, the same, by dotted path. The storage
   * keeps what it holds under each, since the caller could not see it.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource, or a child it stores.
   * @param \Drupal\data_surface\DataSurfaceInterface $built
   *   It as built.
   * @param string $prefix
   *   The child's path in the resource, with its trailing dot.
   *
   * @return string[]
   *   The omitted keys, by dotted path.
   */
  protected function omittedKeys(ResourceDefinition $definition, DataSurfaceInterface $built, string $prefix = ''): array {
    $blueprint = $this->blueprint(new DataSurfaceBuilder(), $definition);
    $this->fillBlueprints($definition, $blueprint);
    // A mixed-in structure's members its access took back out, too.
    // Decision: see docs/decisions.md#a-mixin-is-merged-flat.
    $mixed = [];
    foreach ($blueprint->mixins() as ['blueprint' => $mixin]) {
      array_push($mixed, ...$mixin->keys());
    }
    $omitted = [];
    foreach ([...array_keys($blueprint->attachments()), ...array_keys($blueprint->lists()), ...$mixed] as $key) {
      if ($built->getDefinitions()->entry((string) $key) === NULL) {
        $omitted[] = $prefix . $key;
      }
    }
    // And inside a child this storage stores, by its path.
    foreach ($built->getDefinitions()->entries() as $key => $entry) {
      $child = $entry->attachment?->source === NULL ? NULL : $this->registry->getDefinition((string) $entry->attachment->source);
      if ($child !== NULL && $child->storage === NULL) {
        array_push($omitted, ...$this->omittedKeys($child, $entry->attachment->child, $prefix . $key . '.'));
      }
    }
    return $omitted;
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
   * @param array<string, \Drupal\data_surface\Port> $ports
   *   The ports the owner reads, which a method may watch by name, never
   *   refine.
   * @param string[] $readable
   *   For an alter that attached a structure at its mount, the
   *   structure's keys: its methods may watch them, as keys it mounted,
   *   and refine none.
   * @param array<string, string> $translated
   *   For a structure mixed into an owner, each of its ports mapped to the
   *   owner's key it is wired to: a method watching the port is handed
   *   that key's value, and the edge is the key's.
   *
   * @throws \LogicException
   *   When a method fails a seal-time check, or one that watches nothing
   *   widens what it was given.
   */
  protected function bindRefiners(DataSurfaceBuilderInterface $builder, ResourceDefinition $definition, object|string $instance, array $refiners, array $input_keys, array $output_keys, array $attached = [], ?array $parent = NULL, ?string $module = NULL, array $extended = [], array $mounted = [], array $mountedBy = [], array $lists = [], array $ports = [], array $readable = [], array $translated = []): void {
    if ($refiners === []) {
      return;
    }
    foreach ($refiners as $refiner) {
      // Decision: see docs/decisions.md#a-port-is-a-live-sibling-value.
      if (isset($ports[$refiner->key]) && !in_array($refiner->key, $input_keys, TRUE)) {
        throw new \LogicException(sprintf('%s refines "%s", a port %s reads. A port is its container\'s key, read under a name of the structure\'s own: it is watched, never refined.', $refiner->describe(), $refiner->key, $definition->id));
      }
      // Decision: see docs/decisions.md#an-alter-attaches-at-its-own-mount.
      if (in_array($refiner->key, $readable, TRUE) && !in_array($refiner->key, $input_keys, TRUE)) {
        throw new \LogicException(sprintf('%s refines "%s", a key of the structure its alter attached at its mount on %s. The alter reads the structure\'s keys; what they allow is the structure\'s, refined in its own frame.', $refiner->describe(), $refiner->key, $definition->id));
      }
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
      static::assertWalled($definition, $refiner, [...$input_keys, ...array_keys($ports)], $attached, $parent, $lists);
      static::assertNotWatchingForeignMount($definition, $refiner, $input_keys, [...$mounted, ...$readable], $mountedBy);
      // Decision: see docs/decisions.md#count-is-the-parents.
      $refinable = $own ? [...$input_keys, $refiner->key] : [...$input_keys, ...$lists];
      if ($refiner->mayNotApply) {
        // Decision: see docs/decisions.md#a-key-that-does-not-apply.
        array_push($refinable, ...$attached);
      }
      $watchable = [...$input_keys, ...$mounted, ...$readable, ...array_keys($ports)];
      static::assertRefinable($definition, $refiner, $refinable, $output_keys, $watchable);
      // Decision: see docs/decisions.md#an-alter-watches-its-own-mounted-key.
      $watched = static::mountedWatches($definition, $refiner, $input_keys, $module, [...$mounted, ...$readable]);
      // Decision: see docs/decisions.md#a-mixin-is-merged-flat.
      $watched += array_intersect_key($translated, array_flip($refiner->watched()));
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
   * @param array<string, \Drupal\data_surface\Port> $ports
   *   The ports the owner reads, which a condition may name, and no rule
   *   narrows.
   * @param string[] $readable
   *   For an alter that attached a structure at its mount, the
   *   structure's keys: a condition may name them, and no rule of the
   *   alter's narrows them.
   * @param array<string, string> $translated
   *   For a structure mixed into an owner, each of its ports mapped to the
   *   owner's key it is wired to, where a condition on the port reads.
   *
   * @throws \LogicException
   *   Naming the rule and what is wrong with it.
   */
  protected function bindRules(DataSurfaceBuilderInterface $builder, ResourceDefinition $definition, array $rules, ?string $module, array $input_keys, array $attached, array $lists, array $extended, array $mounted, array $mountedBy, array $ruling, array $ports = [], array $readable = [], array $translated = []): void {
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
      if (!in_array($rule->key, $input_keys, TRUE) && (isset($ports[$rule->key]) || in_array($rule->key, $readable, TRUE))) {
        throw new \LogicException(sprintf(
          '%s narrows "%s", %s. %s',
          $name,
          $rule->key,
          isset($ports[$rule->key]) ? 'a port ' . $definition->id . ' reads' : 'a key of the structure the alter attached at its mount',
          isset($ports[$rule->key]) ? 'A port is its container\'s key: a condition reads it, and no rule narrows it.' : 'The alter reads the structure\'s keys; what they allow is the structure\'s.',
        ));
      }
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
        $mine = $module !== NULL && in_array($key, [...$mounted, ...$readable], TRUE) && !in_array($key, $input_keys, TRUE);
        $port = !$mine && isset($ports[$key]) && !in_array($key, $input_keys, TRUE);
        $problem = match (TRUE) {
          $key === $rule->key => 'the key the rule narrows: a key does not depend on itself',
          in_array($key, $attached, TRUE) => 'a key holding a child, whose values are the child\'s',
          in_array($key, $lists, TRUE) => 'a collection, whose items are each a child',
          !$mine && !$port && !in_array($key, $input_keys, TRUE) => isset($mountedBy[$key])
            ? sprintf('a key the %s module mounted: an alter reads the keys it mounted itself and the owner\'s', implode(', ', $mountedBy[$key]))
            : sprintf('no input of %s', $definition->id),
          default => NULL,
        };
        if ($problem !== NULL) {
          throw new \LogicException(sprintf('%s has a condition on "%s", which is %s.', $name, $key, $problem));
        }
        // A port is read by its own name in this frame; the container that
        // wires it says, at the site, which of its keys that is.
        // Decision: see docs/decisions.md#a-port-is-a-live-sibling-value.
        $path = match (TRUE) {
          $mine => RefinesInputRefiner::path((string) $module, $key),
          // Decision: see docs/decisions.md#a-mixin-is-merged-flat.
          $port && isset($translated[$key]) => $translated[$key],
          default => $key,
        };
        if ($path !== $key) {
          $paths[$key] = $path;
        }
        $watched[] = $path;
        $read = match (TRUE) {
          $mine => $builder->getThirdPartyDefinition((string) $module, $key),
          $port => $this->typedDataManager->createDataDefinition($ports[$key]->type),
          default => $builder->getDefinition($key),
        };
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
   * Makes the blueprint a resource or structure declares into.
   *
   * @param \Drupal\data_surface\DataSurfaceBuilderInterface $builder
   *   The builder it fills.
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure.
   * @param bool $outputs
   *   TRUE for its outputs' blueprint.
   *
   * @return \Drupal\data_surface\ResourceBuild\ResourceBlueprint
   *   The blueprint, which names a structure mixed in by discovery's id.
   */
  protected function blueprint(DataSurfaceBuilderInterface $builder, ResourceDefinition $definition, bool $outputs = FALSE): ResourceBlueprint {
    return (new ResourceBlueprint($builder, $this->typedDataManager, $outputs, $this->registry, $definition->name()))->useShapes($this->shapes());
  }

  /**
   * Gets what resolves a shape, with its alters, for a blueprint.
   *
   * @return \Drupal\data_surface\ResourceBuild\Shapes
   *   The resolver.
   */
  protected function shapes(): Shapes {
    return $this->shapes ??= new Shapes($this->registry, $this->classResolver);
  }

  /**
   * Refuses a key that widens the shape it was declared with.
   *
   * What a key adds to a shape (required, a default, a constraint, a
   * description) is checked once its owner and every alter have declared,
   * by the narrowing check a refiner's answer is held to, against the
   * shape as its alters left it.
   *
   * Decision: see docs/decisions.md#a-shape-is-a-named-definition.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure being built.
   * @param array<string, \Drupal\data_surface\ResourceBuild\BlueprintAdapterBase> $blueprints
   *   Its blueprints, each keyed by the prefix its keys are named with.
   *
   * @throws \LogicException
   *   Naming the key, the shape and what widened.
   */
  protected static function assertShapesNarrowed(ResourceDefinition $definition, array $blueprints): void {
    foreach ($blueprints as $prefix => $blueprint) {
      foreach ($blueprint->shapedKeys() as $key => $declared) {
        $shape = DefinitionMetadata::shapeDefinition($declared);
        assert($shape !== NULL);
        $widened = Narrowing::narrowed($declared, $shape);
        if ($widened !== NULL) {
          throw new \LogicException(sprintf(
            '%s declares "%s" with the shape %s and widens it: %s. A key narrows the shape it uses, as a refiner narrows a key: declare a shape of its own, or narrow less.',
            $definition->id,
            $prefix . $key,
            DefinitionMetadata::shapeOf($declared),
            $widened,
          ));
        }
      }
    }
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
