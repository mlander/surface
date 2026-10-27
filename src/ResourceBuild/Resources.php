<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\PermissionsHashGeneratorInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Resolve;
use Drupal\data_surface\SettledCacheability;
use Drupal\data_surface\Pipeline\DataSurfaceStorageInterface;
use Drupal\data_surface\Reference\References;
use Drupal\data_surface\Refinement\WatchedValueCheckInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceStorageInterface;
use Drupal\data_surface\Storage\ConfigEntityStorage;
use Drupal\data_surface\Storage\ConfigObjectStorage;

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
 *    own structure. A structure mixed in with mixIn() declares its members
 *    into the same builder, where the call stands.
 * 2. The alters #[Alters] names for this class, in discovery order,
 *    skipping one whose operations leave this context's id out:
 *    alterInputs() over a ResourceBlueprintAdditions,
 *    which mounts what they add under the alter's module. A structure an
 *    alter attached at its own mount is built here, in its own frame, its
 *    keys the mount's and its ports wired to the owner's keys
 *    (Attaching::mountStructure()), before anything is bound, since the
 *    alter's glue reads its keys.
 * 3. The seal-time checks on the shape: every identity key is a declared
 *    input, and no operation id is provided twice.
 * 4. The site's defaults, `data_surface.defaults`, written over the
 *    declared ones (SiteDefaults). Then the context: each constraint is
 *    added to its key and held to the narrowing check; each identity key
 *    the context knows becomes that key's default and is locked, which is
 *    the engine's lock: the value fixed to the declared default.
 * 5. Each structure mixed in (Attaching::mixIn()): its ports' wiring
 *    checked, built by itself for its def and its access, which holds its
 *    members to what is stored or takes them back out; then what of the
 *    owner's names its members is checked
 *    (Attaching::assertMixinsUnnamed()), and its refiners, rules and
 *    alters are bound in the owner's frame first (Binding::bindMixin()).
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
 *    its ports wired to the parent's keys (Attaching::assertWiring(),
 *    ::wirePorts()), each invoked the way one asked on its own is:
 *    through one of its own operations when one applies
 *    (Declaring::invokeChild()), otherwise in the context its parent's
 *    hands it or in its parent's own. Its access then decides whether the
 *    child is open, locked or left out for the current account
 *    (Gating::childAccess()): the permission of an operation the
 *    build resolved, its access class however it was invoked, then each
 *    of its alters that answers access, the stricter standing.
 *    A collection's child is built the same way, once for the list, and
 *    its access answers once for the list. Built for the account alone
 *    (buildStatic()), an access class's answer that is runtime is set
 *    aside and its keys marked, and only the static ones apply.
 * 8. The seal.
 *
 * A variant the site lists (a provider's, or one read from a schema) is
 * not built in step 7: its access is asked, and it is sealed as what
 * builds it (VariantToSettle), built the first time a door reads it.
 *
 * The engine's builder is the whole state of a build. A build for no
 * account (buildDeclared()) is kept across requests in the build cache,
 * under the tags of everything it was read from (keep()); what that state
 * is, a shape or an alter says with addCacheableDependency(). Two things
 * are kept within a request: a static access answer, an access class's
 * or an alter's, which by its own cacheability depends on nothing but the
 * account, asked once per context and account kind (the account's roles
 * and permissions) and reused; and each sealed build asked for at the top,
 * by resource, context, kind and account kind, reused while none of the
 * tags it read is invalidated, never when it expires or varies by more
 * than the account (memo()). Both are bounded, so a worker that outlives a
 * request keeps only the latest.
 *
 * Each phase is a class of its own, which this one constructs and calls in
 * order; it keeps the order, the memo and the build cache:
 *
 * - Declaring: steps 1 to 4, the blueprint filled from the class, its
 *   alters, the site's defaults and the context, and the context a child
 *   is invoked in.
 * - Attaching: steps 5 and 7, each child, collection, variant, map of keys
 *   from the site, structure mixed in and structure at an alter's mount,
 *   each built through this class again in its own frame.
 * - Binding: step 6, the refiners and rules each contributor declared.
 * - Gating: who may ask and what they may see, the permission, the gates,
 *   the access classes and the alters answering access, at the top and
 *   at each child, and what access() answers.
 *
 * Decision: see
 * docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
 * Decision: see docs/decisions.md#the-build-is-read-by-phase.
 */
final class Resources implements ResourcesInterface {

  /**
   * What builds reference keys, where withRegistry() was handed one.
   */
  protected ?References $heldReferences = NULL;

  /**
   * The blueprint filled from the class, the context and the site.
   */
  protected Declaring $declaring;

  /**
   * The refiners and rules each contributor declared, bound into the builder.
   */
  protected Binding $binding;

  /**
   * Who may ask, and what they may see.
   */
  protected Gating $gating;

  /**
   * The children, collections, variants, site lists, mixins and mounts.
   */
  protected Attaching $attaching;

  /**
   * How many sealed builds are kept, the least recently asked let go.
   */
  protected const BUILDS_KEPT = 32;

  /**
   * The cache contexts a kept build may vary by: the account, its kind.
   *
   * The memo is keyed by both; a build varying by anything else (the
   * route, the URL, the time) is built every time it is asked.
   */
  protected const KEPT_CONTEXTS = ['user', 'user.permissions', 'user.roles'];

  /**
   * The sealed builds asked for lately, by memo key, the latest last.
   *
   * @var array<string, array{surface: \Drupal\data_surface\DataSurfaceInterface, tags: string[]}>
   */
  protected array $builds = [];

  /**
   * How many read structures are kept, the least recently built let go.
   */
  protected const READ_KEPT = 4096;

  /**
   * The structures read from a schema built lately, by how they were asked.
   *
   * A read structure is its schema and nothing more: no class, alter,
   * storage, access class or provider of its own, and its name holds the
   * hash of the document it was read from. So one built in a context is
   * the same wherever it is attached, and a def a document uses at many
   * sites (a component's props in every slot that offers it) is built
   * once and held at each.
   *
   * @var array<string, array{surface: \Drupal\data_surface\DataSurfaceInterface, tags: string[]}>
   */
  protected array $readBuilds = [];

  /**
   * How many builds were made and how many were answered from the memo.
   *
   * With how many were read from the cache bin, how many variants held
   * unsettled were built when read, and the builds made of each
   * resource, by name.
   *
   * @var array{built: int, reused: int, cached: int, settled: int, resources: array<string, int>}
   */
  protected array $buildCounts = ['built' => 0, 'reused' => 0, 'cached' => 0, 'settled' => 0, 'resources' => []];

  /**
   * The resources and structures the build under way built, by name.
   *
   * What the build cache tags a kept build with, beside what the build
   * read: each one's discovery entry.
   *
   * @var array<string, true>|null
   */
  protected ?array $visited = NULL;

  /**
   * The cache tags every kept build carries, whatever it read.
   *
   * Which modules are installed, the module's settings (the limits) and
   * the site's defaults.
   */
  protected const KEPT_TAGS = ['config:core.extension', 'config:data_surface.settings', 'config:data_surface.defaults'];

  /**
   * The containers a kept build must not hold, which a whole service brings.
   */
  protected const CONTAINERS = [
    'Drupal\\Component\\DependencyInjection\\Container',
    'Drupal\\Core\\DependencyInjection\\Container',
    'Symfony\\Component\\DependencyInjection\\Container',
  ];

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
   * @param \Drupal\data_surface\Refinement\WatchedValueCheckInterface $watchedValueCheck
   *   What a watched value passes before a refiner is handed it, sealed
   *   into every data surface and every child this step builds.
   * @param \Drupal\Core\Session\PermissionsHashGeneratorInterface|null $permissionsHashGenerator
   *   What tells two accounts' permissions apart, which a static access
   *   answer is kept by; NULL keeps none.
   * @param \Drupal\data_surface\ResourceBuild\SiteDefaults|null $siteDefaults
   *   The defaults the site sets over the declared ones; NULL reads none.
   * @param \Drupal\Core\Cache\CacheBackendInterface|null $buildCache
   *   The bin a build for no account is kept in across requests; NULL
   *   keeps none.
   * @param \Drupal\Core\Language\LanguageManagerInterface|null $languageManager
   *   What tells the language a kept build's labels were read in.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface|null $typedConfig
   *   The config schema, which says what a key declared with one of its
   *   data types (`machine_name`) is; NULL reads none.
   */
  public function __construct(
    protected readonly ResourceRegistry $registry,
    protected readonly TypedDataManagerInterface $typedDataManager,
    protected readonly ClassResolverInterface $classResolver,
    protected readonly AccountInterface $currentUser,
    protected readonly OperationArguments $operationArguments,
    protected readonly WatchedValueCheckInterface $watchedValueCheck,
    protected readonly ?PermissionsHashGeneratorInterface $permissionsHashGenerator = NULL,
    protected readonly ?SiteDefaults $siteDefaults = NULL,
    protected readonly ?CacheBackendInterface $buildCache = NULL,
    protected readonly ?LanguageManagerInterface $languageManager = NULL,
    protected readonly ?TypedConfigManagerInterface $typedConfig = NULL,
  ) {
    $this->phases();
  }

  /**
   * Constructs the four phases over this build step's services.
   *
   * Called again by withRegistry() on the build step it makes, once that
   * one holds the references it was handed.
   */
  protected function phases(): void {
    $this->declaring = new Declaring($this->registry, $this->typedDataManager, $this->classResolver, $this->watchedValueCheck, $this->siteDefaults, $this->heldReferences, $this, $this->typedConfig);
    $this->binding = new Binding($this->typedDataManager, $this->declaring);
    $this->gating = new Gating($this->registry, $this->typedDataManager, $this->classResolver, $this->currentUser, $this->permissionsHashGenerator, $this->declaring, $this);
    $this->attaching = new Attaching($this->registry, $this->typedDataManager, $this->watchedValueCheck, $this->declaring, $this->binding, $this->gating, $this, $this->buildResource(...), $this->buildRead(...));
  }

  /**
   * Gives a build step reading another registry, its classes built otherwise.
   *
   * For the config schema generator alone, which reads modules that are
   * not installed: their classes are in a registry of their own, and
   * their alters, storage and access classes are no services yet.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceRegistry $registry
   *   The registry.
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface $classResolver
   *   What builds the classes discovery names.
   * @param \Drupal\data_surface\Reference\References|null $references
   *   What builds reference keys, knowing the entity types those modules
   *   declare (References::knowing()); NULL for the service.
   *
   * @return static
   *   A build step with nothing built yet.
   *
   * @internal
   *
   * @see \Drupal\data_surface\Storage\ConfigSchemaGenerator::including()
   */
  public function withRegistry(ResourceRegistry $registry, ClassResolverInterface $classResolver, ?References $references = NULL): static {
    $resources = new static($registry, $this->typedDataManager, $classResolver, $this->currentUser, $this->operationArguments, $this->watchedValueCheck, $this->permissionsHashGenerator, $this->siteDefaults, typedConfig: $this->typedConfig);
    $resources->heldReferences = $references;
    $resources->phases();
    return $resources;
  }

  /**
   * {@inheritdoc}
   */
  public function build(string $resource, OperationContext $context): DataSurfaceInterface {
    return $this->buildAs($resource, $context, FALSE, FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function buildStatic(string $resource, OperationContext $context): DataSurfaceInterface {
    // Decision: see
    // docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability.
    return $this->buildAs($resource, $context, TRUE, FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function buildDeclared(string $resource, OperationContext $context): DataSurfaceInterface {
    // Decision: see docs/decisions.md#a-static-tool-definition-is-no-accounts.
    return $this->buildAs($resource, $context, TRUE, TRUE);
  }

  /**
   * Builds what a resource declares, for its config schema to be written from.
   *
   * What buildDeclared() builds, without holding the resource to the
   * config schema its storage writes: that schema is what is being
   * generated, and the one on disk may not describe the resource yet.
   * For the config schema generator alone.
   *
   * @param string $resource
   *   The class, or its #[Resource] id.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   Where it is being asked for.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The sealed data surface.
   *
   * @internal
   *
   * @see \Drupal\data_surface\Storage\ConfigSchemaGenerator
   */
  public function declaration(string $resource, OperationContext $context): DataSurfaceInterface {
    $was = $this->declaring->holdsToSchema;
    $this->declaring->holdsToSchema = FALSE;
    try {
      return $this->buildDeclared($resource, $context);
    }
    finally {
      $this->declaring->holdsToSchema = $was;
    }
  }

  /**
   * Builds at the top, with the access answers applied as asked.
   *
   * @param string $resource
   *   The class, or its id.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   Where it is being asked for.
   * @param bool $alone
   *   TRUE to set a runtime answer aside (buildStatic()).
   * @param bool $declaring
   *   TRUE to set every answer aside, asking none (buildDeclared()).
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The sealed data surface.
   */
  protected function buildAs(string $resource, OperationContext $context, bool $alone, bool $declaring): DataSurfaceInterface {
    if ($context->id === '') {
      throw new \LogicException(sprintf(
        'A context with no id was handed to build %s. An #[Operation] method may leave its id out for the attribute\'s to be stamped on it, so ask for the operation through operation() rather than calling its method.',
        $resource,
      ));
    }
    $key = $this->memoKey($resource, $context, $alone, $declaring);
    if ($key !== NULL && isset($this->builds[$key])) {
      // The latest asked last, so the oldest is the one let go.
      $kept = $this->builds[$key];
      unset($this->builds[$key]);
      $this->builds[$key] = $kept;
      $this->buildCounts['reused']++;
      return $kept['surface'];
    }
    // A build for no account is the same in every request until a tag it
    // was read from is invalidated: kept in the build cache.
    // Decision: see
    // docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
    $cid = $declaring && $key !== NULL ? $this->cacheId($resource, $key) : NULL;
    $built = $cid === NULL ? NULL : $this->cached($cid);
    if ($built !== NULL) {
      $this->buildCounts['cached']++;
    }
    else {
      [$was_alone, $was_declaring, $outer] = [$this->gating->accountAlone, $this->gating->forNoAccount, $this->visited];
      [$this->gating->accountAlone, $this->gating->forNoAccount, $this->visited] = [$alone, $declaring, []];
      try {
        $built = $this->buildResource($resource, $context, [], top: TRUE);
        $this->declaring->assertHeldToSchema($resource, $context, $built);
        $visited = $this->visited;
      }
      finally {
        $this->gating->accountAlone = $was_alone;
        $this->gating->forNoAccount = $was_declaring;
        // What an inner build built, its outer one built too.
        $this->visited = $outer === NULL ? NULL : $outer + ($this->visited ?? []);
      }
      $this->buildCounts['built']++;
      $name = $this->registry->getDefinition($resource)->name();
      $this->buildCounts['resources'][$name] = ($this->buildCounts['resources'][$name] ?? 0) + 1;
      if ($cid !== NULL) {
        $this->keep($cid, $resource, $context, $built, $visited);
      }
    }
    if ($key !== NULL && static::keepable($built)) {
      $this->builds[$key] = ['surface' => $built, 'tags' => $built->getCacheTags()];
      if (count($this->builds) > self::BUILDS_KEPT) {
        unset($this->builds[array_key_first($this->builds)]);
      }
    }
    return $built;
  }

  /**
   * Names what a sealed build is kept by within the request.
   *
   * The resource's name, everything the context says, the kind of build,
   * whether it is held to its config schema, the limit up to which a
   * reference key lists its candidates, and the account kind: none
   * for a build for no account, the roles and permissions for one for the
   * account alone, and the account itself beside them for a build with
   * every answer applied, which a runtime answer may read.
   *
   * @param string $resource
   *   The class, or its id.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context.
   * @param bool $alone
   *   Whether runtime answers are set aside.
   * @param bool $declaring
   *   Whether every answer is set aside.
   *
   * @return string|null
   *   The key, or NULL when none can be made and the build is not kept.
   */
  protected function memoKey(string $resource, OperationContext $context, bool $alone, bool $declaring): ?string {
    try {
      $name = $this->registry->getDefinition($resource)->name();
      $kind = [];
      if (!$declaring) {
        if ($this->permissionsHashGenerator === NULL) {
          return NULL;
        }
        $account = $this->currentUser;
        $roles = $account->getRoles();
        sort($roles);
        $kind = [$roles, $this->permissionsHashGenerator->generate($account), $alone ? NULL : (string) $account->id()];
      }
      // Whether a reference key lists its candidates is the limit's.
      // Decision: see
      // docs/decisions.md#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up.
      return hash('xxh3', serialize([
        $name,
        $context,
        $alone,
        $declaring,
        $this->declaring->holdsToSchema,
        $kind,
        $this->declaring->references()->limit(),
      ]));
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Answers whether a sealed build may be kept for the rest of the request.
   *
   * Only what says it stays true until a tag of it is invalidated, and
   * varies by nothing but the account the memo is keyed by: a build that
   * expires, or varies by the route or the time, is built every time.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $built
   *   The build.
   *
   * @return bool
   *   TRUE when it may be kept.
   */
  protected static function keepable(DataSurfaceInterface $built): bool {
    if ($built->getCacheMaxAge() !== Cache::PERMANENT) {
      return FALSE;
    }
    foreach ($built->getCacheContexts() as $context) {
      if (!in_array($context, self::KEPT_CONTEXTS, TRUE) && !str_starts_with($context, 'user.roles:')) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Names a build for no account in the build cache.
   *
   * The memo key, which says the resource, the context and its
   * parameters, the kind of build and the reference limit, with the
   * languages a label read from configuration is in.
   *
   * @param string $resource
   *   The class, or its id.
   * @param string $key
   *   Its memo key.
   *
   * @return string|null
   *   The cache id, or NULL when there is no bin, or the build is not the
   *   container's (another registry's, for the config schema generator).
   */
  protected function cacheId(string $resource, string $key): ?string {
    if ($this->buildCache === NULL || !$this->declaring->holdsToSchema) {
      return NULL;
    }
    $languages = $this->languageManager === NULL ? [] : [
      $this->languageManager->getCurrentLanguage()->getId(),
      $this->languageManager->getConfigOverrideLanguage()->getId(),
    ];
    return 'declared:' . $this->registry->getDefinition($resource)->id . ':' . hash('xxh3', serialize([$key, $languages]));
  }

  /**
   * Reads a build for no account from the build cache.
   *
   * @param string $cid
   *   Its cache id.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface|null
   *   The sealed build, or NULL when none is kept.
   */
  protected function cached(string $cid): ?DataSurfaceInterface {
    $hit = $this->buildCache?->get($cid);
    if ($hit === FALSE || $hit === NULL || !is_string($hit->data)) {
      return NULL;
    }
    // The module's own bin, written by keep() alone.
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
    $kept = unserialize($hit->data);
    if (!is_array($kept) || !($kept['surface'] ?? NULL) instanceof DataSurfaceInterface) {
      return NULL;
    }
    // What it names that only building it registers.
    $this->registry->rememberRead($kept['read'] ?? []);
    return $kept['surface'];
  }

  /**
   * Collects the structures a build names that only building registers.
   *
   * Every child built at every depth, by the name it was built by; those
   * read from a schema or made for a reference key are what a request
   * that did not build their owner cannot name. Not a variant held
   * unsettled: the request that builds it registers it again from its
   * owner's list (settleVariant()), so the document the site's list read
   * (a theme's components, every one a def of it) is not stored with
   * every build that holds them.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The build.
   * @param array<string, \Drupal\data_surface\ResourceBuild\ResourceDefinition> $read
   *   Filled with those definitions, by name.
   * @param array<int, true> $seen
   *   The frames walked, by object id.
   */
  protected function readNamed(DataSurfaceInterface $surface, array &$read, array &$seen = []): void {
    if (isset($seen[spl_object_id($surface)])) {
      return;
    }
    $seen[spl_object_id($surface)] = TRUE;
    foreach ($surface->getDefinitions()->entries() as $entry) {
      $children = [
        ...($entry->attachment === NULL ? [] : [$entry->attachment]),
        ...array_values($entry->mounted),
        ...array_values($entry->variants->variants ?? []),
        ...($entry->list === NULL ? [] : [$entry->list->item]),
      ];
      foreach ($children as $attachment) {
        if ($attachment->isDeferred() && !$attachment->isSettled()) {
          continue;
        }
        if ($attachment->source !== NULL && !isset($read[$attachment->source])) {
          $definition = $this->registry->readDefinition($attachment->source);
          if ($definition !== NULL) {
            $read[$attachment->source] = $definition;
          }
        }
        $child = $attachment->settledChild();
        if ($child !== NULL) {
          $this->readNamed($child, $read, $seen);
        }
      }
    }
    foreach ($surface->getDefinitions()->mixins() as $mixin) {
      $child = $mixin->structure->settledChild();
      if ($mixin->structure->source !== NULL && ($definition = $this->registry->readDefinition($mixin->structure->source)) !== NULL) {
        $read[$mixin->structure->source] = $definition;
      }
      if ($child !== NULL) {
        $this->readNamed($child, $read, $seen);
      }
    }
  }

  /**
   * Keeps a build for no account in the build cache, or refuses it.
   *
   * Only one that stays true until a tag of it is invalidated and varies
   * by nothing: no expiry and no cache context. Tagged with what it read
   * (each provider's list, the site's defaults), what discovery found for
   * each resource and structure it built, discovery's index, and
   * KEPT_TAGS.
   *
   * @param string $cid
   *   Its cache id.
   * @param string $resource
   *   The class, or its id.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   Its context.
   * @param \Drupal\data_surface\DataSurfaceInterface $built
   *   The build.
   * @param array<string, true> $visited
   *   What it built, by name.
   *
   * @throws \LogicException
   *   When it holds what cannot be stored: a closure, or a service held as
   *   an object rather than by its id.
   */
  protected function keep(string $cid, string $resource, OperationContext $context, DataSurfaceInterface $built, array $visited): void {
    if ($built->getCacheMaxAge() !== Cache::PERMANENT || $built->getCacheContexts() !== []) {
      return;
    }
    $tags = Cache::mergeTags($built->getCacheTags(), $this->registry->getIndexCacheTags(), self::KEPT_TAGS);
    foreach (array_keys($visited) as $name) {
      if ($this->registry->hasDefinition($name)) {
        $tags = Cache::mergeTags($tags, $this->registry->getDefinition($name)->cacheTags);
      }
    }
    $id = $this->registry->getDefinition($resource)->id;
    $read = [];
    $this->readNamed($built, $read);
    try {
      $serialized = serialize(['surface' => $built, 'read' => $this->registry->exportRead($read)]);
    }
    catch (\Throwable $e) {
      throw new \LogicException(sprintf(
        'The %s resource, built for its "%s" operation, cannot be kept in the build cache: %s. A sealed data surface holds definitions, class and method names, services by their id and plain data, never a closure or an object that cannot be stored; find what its declaration, an alter or a refiner put on it.',
        $id,
        $context->id,
        $e->getMessage(),
      ), 0, $e);
    }
    foreach (self::CONTAINERS as $container) {
      if (str_contains($serialized, '"' . $container . '"')) {
        throw new \LogicException(sprintf(
          'The %s resource, built for its "%s" operation, cannot be kept in the build cache: it holds the service container, which a service held as an object rather than by its id brings with it. An alter, a refiner or a storage layout that holds services composes core\'s DependencySerializationTrait, or is a service the container built.',
          $id,
          $context->id,
        ));
      }
    }
    $this->buildCache?->set($cid, $serialized, Cache::PERMANENT, $tags);
  }

  /**
   * Forgets the builds that read what is being invalidated.
   *
   * ResourceRegistryInvalidator calls this for every invalidation, so a
   * request that saves a field and then builds the resource builds it
   * again.
   *
   * @param string[] $tags
   *   The tags being invalidated.
   *
   * @internal
   */
  public function invalidateTags(array $tags): void {
    // What a kept build read, with what each variant built since read.
    // Decision: see
    // docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
    foreach ($this->builds as $key => $kept) {
      if (array_intersect($tags, SettledCacheability::of($kept['surface'])->getCacheTags()) !== []) {
        unset($this->builds[$key]);
      }
    }
    // A read structure built since it was held is kept here by itself:
    // only when one of them read a tag are those holding it asked.
    $dropped = FALSE;
    foreach ($this->readBuilds as $key => $kept) {
      if (array_intersect($tags, $kept['tags']) !== []) {
        unset($this->readBuilds[$key]);
        $dropped = TRUE;
      }
    }
    if ($dropped) {
      foreach ($this->readBuilds as $key => $kept) {
        if (array_intersect($tags, SettledCacheability::of($kept['surface'])->getCacheTags()) !== []) {
          unset($this->readBuilds[$key]);
        }
      }
    }
  }

  /**
   * Forgets every build kept, when discovery itself was read again.
   *
   * With what each resource was found to hold, which a derived resource's
   * fields decide.
   *
   * @internal
   */
  public function forgetBuilds(): void {
    $this->builds = [];
    $this->readBuilds = [];
    $this->attaching->forget();
    $this->gating->forget();
  }

  /**
   * Reports how many builds were made and how many were reused.
   *
   * For a performance budget, never for behavior: counts since the
   * service was made, and the builds made of each resource.
   *
   * @return array{built: int, reused: int, cached: int, settled: int, resources: array<string, int>}
   *   The counts.
   *
   * @internal
   */
  public function buildCounts(): array {
    return $this->buildCounts;
  }

  /**
   * Gives what declares an operation's keys: the resource, or a structure.
   *
   * An operation that names `structure:` takes the keys of that structure in
   * place of the resource's: a listing's paging and filter, a reorder's
   * list. The structure has no identity and no storage of its own; the
   * resource's storage stores what it takes.
   *
   * Decision: see
   * docs/decisions.md#a-listing-is-a-read-only-operation-with-outputs-a-reorder-is-an-update-over-the-collection.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The operation it is asked for in.
   *
   * @return \Drupal\data_surface\ResourceBuild\ResourceDefinition
   *   The structure the operation names, or the resource itself.
   *
   * @throws \LogicException
   *   When the operation names a class that is no structure, or one with
   *   identity or storage.
   */
  public function shapeOf(ResourceDefinition $definition, OperationContext $context): ResourceDefinition {
    $values = $this->registry->getOperations($definition->name())[$context->id]->structure ?? NULL;
    if ($values === NULL) {
      return $definition;
    }
    $shape = $this->registry->hasDefinition($values) ? $this->registry->getDefinition($values) : NULL;
    if ($shape === NULL || $shape->isResource() || $shape->identity !== [] || $shape->storage !== NULL) {
      throw new \LogicException(sprintf(
        'The "%s" operation of %s takes the keys of %s, which is no structure: what an operation\'s `structure:` names is a #[Structure] class, with no identity and no storage, which the resource\'s storage stores.',
        $context->id,
        $definition->id,
        $values,
      ));
    }
    return $shape;
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
   * @param array{resource: string, at: string, values: array<string, mixed>}|null $siteDefaults
   *   For a child attached at a fixed key, the defaults the site sets on
   *   its keys, from its parent's (SiteDefaults::write()); NULL reads the
   *   site's for one built at the top, and none for any other.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The sealed data surface.
   */
  protected function buildResource(string $resource, OperationContext $context, array $ancestry, string|\Stringable|null $locked = NULL, bool $top = FALSE, ?array $siteDefaults = NULL): DataSurfaceInterface {
    $definition = $this->registry->getDefinition($resource);
    if ($this->visited !== NULL) {
      $this->visited[$definition->name()] = TRUE;
    }
    // Refuses two providers of one operation id, whichever is asked for.
    $this->registry->getOperations($definition->name());
    // The resource the operation is asked of: its permission, its storage,
    // its access and how its operation behaves stay its own when the
    // operation takes the keys of a structure in place of the resource's.
    // Decision: see
    // docs/decisions.md#a-listing-is-a-read-only-operation-with-outputs-a-reorder-is-an-update-over-the-collection.
    $own = $definition;
    if ($top) {
      $definition = $this->shapeOf($definition, $context);
      if ($this->visited !== NULL) {
        $this->visited[$definition->name()] = TRUE;
      }
    }

    [$builder, $inputs, $outputs, $links, $additions] = $this->declaring->declareShape($definition, $context);

    $input_keys = $inputs->keys();
    $attached = array_merge(array_keys($inputs->attachments()), array_keys($inputs->variants()), array_keys($inputs->fromSite()));
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
    $variantKeys = $this->attaching->variantKeysOf($definition, $inputs, $input_keys, $builder);

    // The site's defaults over the declared ones, before a known identity
    // key is locked to what the context knows.
    // Decision: see docs/decisions.md#a-site-sets-a-default-in-configuration.
    $childDefaults = $this->declaring->writeSiteDefaults($builder, $definition, $inputs, $top, $siteDefaults);
    $build = new Build($builder, $definition, $context, $ancestry, $input_keys, $outputs->keys(), $attached, $lists, $inputs->ports());
    $this->declaring->applyContext($build);
    $frame = ['class' => $definition->name(), 'id' => $definition->id, 'key' => '', 'inputs' => $input_keys];
    $permission = $this->registry->getOperations($own->name())[$context->id]->permission ?? NULL;
    $frame['permission'] = $permission === NULL ? NULL : Gating::resolvePermission($permission, $context);
    // Which keys the storage always holds, asked of the storage that
    // stores them: this resource's own, or its parent's, under the key
    // the parent stores it at.
    // Decision: see docs/decisions.md#a-key-its-storage-always-holds.
    // An operation's own values are no keys the storage holds.
    $frame['held'] = $definition === $own ? $this->binding->heldBy($definition, $context, $ancestry) : NULL;
    $this->binding->markAlwaysHeld($builder, $input_keys, $frame['held']);
    $build = $build->withFrame($frame);
    // Each structure mixed in, built by itself for its def and its access,
    // which locks its members or takes them back out; then nothing of the
    // owner's may name what that access may take away, or rule a member
    // out.
    // Decision: see docs/decisions.md#a-mixin-is-merged-flat.
    $mixins = $this->attaching->mixIn($build, $inputs);
    $owned_rules = [$inputs->rules()];
    $wirings = [];
    foreach (array_keys($inputs->attachments()) as $key) {
      $wirings[(string) $key] = $inputs->wiring((string) $key);
    }
    foreach ($additions as $blueprint) {
      $owned_rules[] = $blueprint->rules();
      foreach (array_keys($blueprint->attachments()) as $key) {
        $wirings[(string) $key] = $blueprint->wiring((string) $key);
      }
    }
    $this->attaching->assertMixinsUnnamed($definition, $mixins, $links, array_merge(...$owned_rules), $wirings);
    $input_keys = array_values(array_filter($input_keys, static fn (string $key): bool => $builder->getDefinition($key) !== NULL));
    $build = $build->withInputKeys($input_keys);
    // Each alter's structure at its own mount, built before any refiner or
    // rule is bound, since the alter's glue reads its keys.
    // Decision: see docs/decisions.md#an-alter-attaches-at-its-own-mount.
    [$readable, $mounted_by] = $this->attaching->mountAlters($build, $additions);
    // Each structure mixed in first, then each contributor's coded
    // refiners and the rules it declared, the owner's before any alter's.
    $build = $this->binding->bind($build, $inputs, $links, $additions, $mixins, $readable, $mounted_by);

    // The children, each through this same build step in its own frame:
    // the fixed ones, the maps of keys from the site, the variants, then
    // the collections.
    $this->attaching->attachChildren($build, $inputs, $childDefaults);
    $this->attaching->attachMaps($build, $inputs);
    $this->attaching->attachVariants($build, $variantKeys);
    // A gate the operation asks once per item is settled per thing: built
    // for the account alone or for none, the list is marked.
    // Decision: see docs/decisions.md#a-gate-is-borrowed-never-copied.
    $gated_lists = $top && ($this->gating->accountAlone || $this->gating->forNoAccount) ? $this->gating->itemGatedLists($own, $context) : [];
    $this->attaching->attachLists($build, $inputs, $gated_lists);

    // Decision: see docs/decisions.md#a-resource-evolves-in-the-open.
    $builder->setVersion($definition->version);
    // Decision: see docs/decisions.md#an-operation-states-how-it-behaves.
    if ($top && ($this->registry->getOperations($own->name())[$context->id]->readOnly ?? FALSE)) {
      $builder->markChangesNothing();
    }
    // Decision: see docs/decisions.md#locked-at-the-top.
    $locked ??= $top ? $this->gating->lockedAtTheTop($builder, $own, $context) : NULL;
    if ($locked !== NULL) {
      $builder->lockWhole($locked);
    }
    return $builder->seal();
  }

  /**
   * Builds a variant the build held unsettled, now that a door reads it.
   *
   * In the build it was held in: for no account, for the account alone
   * or with every answer, for the account that build was for, and held to
   * its config schema as that build was; what the variant's own build
   * refuses is refused here, its wiring among it.
   *
   * Decision: see
   * docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
   *
   * @param \Drupal\data_surface\ResourceBuild\VariantsToSettle $at
   *   Where the variant sits and the build it was held in.
   * @param string $name
   *   The variant, by name.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context it is invoked in.
   * @param string|null $locked
   *   Why its access locked it whole, or NULL.
   * @param bool $read
   *   Whether it is a structure read from a schema, built through
   *   buildRead().
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The sealed variant.
   *
   * @internal
   */
  public function settleVariant(VariantsToSettle $at, string $name, OperationContext $context, ?string $locked, bool $read): DataSurfaceInterface {
    $ancestry = $at->ancestry;
    // A storage a frame names is a service, kept by its class when the
    // variants were stored.
    foreach ($ancestry as $level => $frame) {
      if (is_array($frame['held'] ?? NULL) && !isset($frame['held']['storage'])) {
        $ancestry[$level]['held']['storage'] = $this->declaring->instance($frame['held']['class'], ResourceStorageInterface::class);
      }
    }
    $parent = $ancestry[count($ancestry) - 1];
    if (!$this->registry->hasDefinition($name)) {
      // Kept with a build read from the build cache: a structure the
      // owner's list reads from a schema is registered by listing it.
      $this->attaching->registerVariantsOf($parent['class']);
    }
    $was = [
      $this->gating->accountAlone,
      $this->gating->forNoAccount,
      $this->declaring->holdsToSchema,
      $this->gating->settlingAs,
    ];
    $this->gating->accountAlone = $at->alone;
    $this->gating->forNoAccount = $at->declaring;
    $this->declaring->holdsToSchema = $at->holdsToSchema;
    $this->gating->settlingAs = $at->account;
    try {
      // Nothing wires a variant's ports yet.
      $this->attaching->assertWiring(new Build(new DataSurfaceBuilder(), $this->registry->getDefinition($parent['class']), $context), NULL, $at->key, $name, [], 'variant');
      $built = $read
        ? $this->buildRead($name, $context, $ancestry)
        : $this->buildResource($name, $context, $ancestry, $locked);
    }
    finally {
      [
        $this->gating->accountAlone,
        $this->gating->forNoAccount,
        $this->declaring->holdsToSchema,
        $this->gating->settlingAs,
      ] = $was;
    }
    $this->buildCounts['settled']++;
    return $built;
  }

  /**
   * Builds a structure read from a schema once for every site it is at.
   *
   * Kept for the rest of the request by its name, its context and the
   * kind of build, as a top build is (memo()), when it reads no port of a
   * container, which each site would bind its own way, and may be kept.
   *
   * @param string $name
   *   The read structure's name.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context it is invoked in.
   * @param array $ancestry
   *   Where it is first attached, for messages and the cycle check.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The sealed structure.
   */
  protected function buildRead(string $name, OperationContext $context, array $ancestry): DataSurfaceInterface {
    $key = hash('xxh3', serialize([
      $name,
      $context,
      $this->gating->accountAlone,
      $this->gating->forNoAccount,
      $this->declaring->holdsToSchema,
      $this->declaring->references()->limit(),
    ]));
    if (isset($this->readBuilds[$key])) {
      $this->buildCounts['reused']++;
      return $this->readBuilds[$key]['surface'];
    }
    $built = $this->buildResource($name, $context, $ancestry);
    if ($built->getPortValues() === [] && static::keepable($built)) {
      $this->readBuilds[$key] = ['surface' => $built, 'tags' => $built->getCacheTags()];
      if (count($this->readBuilds) > self::READ_KEPT) {
        unset($this->readBuilds[array_key_first($this->readBuilds)]);
      }
    }
    return $built;
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
   * {@inheritdoc}
   */
  public function operation(string $resource, string $operation, array $arguments = []): OperationContext {
    $found = $this->registry->getOperation($resource, $operation);
    $resolved = $this->operationArguments->resolve($found, $arguments);
    // One on the class is its attribute's facts and nothing else.
    // Decision: see docs/decisions.md#an-operation-on-the-class.
    if ($found->isBare()) {
      return (new OperationContext())->stamped($found->id, $found->creates);
    }
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
    // Decision: see docs/decisions.md#a-contexts-id-is-the-operation-id.
    return $found->stamp($context);
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
    return $this->gating->access($resource, $context, $account);
  }

  /**
   * {@inheritdoc}
   */
  public function isRuntimeAtTheTop(string $resource, OperationContext $context, AccountInterface $account): bool {
    return $this->gating->isRuntimeAtTheTop($resource, $context, $account);
  }

  /**
   * {@inheritdoc}
   */
  public function permissionAccess(string $resource, string $operation, ?AccountInterface $account = NULL, ?OperationContext $context = NULL): ?AccessResultInterface {
    return $this->gating->permissionAccess($resource, $operation, $account, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function defaults(string $resource): array {
    $builder = new DataSurfaceBuilder();
    $definition = $this->registry->getDefinition($resource);
    $this->declaring->fillBlueprints($definition, $this->declaring->blueprint($builder, $definition));
    return $builder->seal()->getDefaultValues();
  }

  /**
   * {@inheritdoc}
   */
  public function storage(string $resource, OperationContext $context, ?DataSurfaceInterface $built = NULL, ?AccountInterface $account = NULL): DataSurfaceStorageInterface {
    $definition = $this->registry->getDefinition($resource);
    if ($definition->storage === NULL) {
      throw new \LogicException(sprintf(
        $definition->isResource()
          ? '%s names no storage in #[Resource(storage:)]: its host or its parent stores it, the way the block host stores a plugin\'s configuration.'
          : '%s is a structure, which has no storage of its own: its host or its parent stores it, the way the block host stores a plugin\'s configuration.',
        $definition->id,
      ));
    }
    return $this->storageFor($definition, $context, $built ?? $this->build($definition->name(), $context), $account ?? $this->currentUser, $this->gating->itemGates($definition, $context));
  }

  /**
   * {@inheritdoc}
   *
   * Decision: see docs/decisions.md#a-key-may-stand-for-another-at-use.
   */
  public function resolved(string $resource, string $operation = 'configure', array $arguments = []): array {
    $context = $this->operation($resource, $operation, $arguments);
    $built = $this->build($resource, $context);
    return Resolve::apply($built, $this->storage($resource, $context, $built)->load($built));
  }

  /**
   * Composes a resource's storage with its children's, along the tree.
   *
   * Each child whose class names storage of its own is routed to it, in
   * the context it is invoked in (invokeChild()), and recursively for the
   * child's own children; a child without it stays in its parent's
   * values, stored by the parent under its key. A key with variants
   * routes per variant, by what its deciding key holds.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource, which has storage.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   Its context.
   * @param \Drupal\data_surface\DataSurfaceInterface $built
   *   The resource as built, whose entries carry the children.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account each level writes as.
   * @param \Drupal\data_surface\ResourceBuild\ItemGates|null $gates
   *   The gates the operation asks per item, for the top level alone.
   *
   * @return \Drupal\data_surface\ResourceBuild\StorageAdapter
   *   The storage.
   */
  protected function storageFor(ResourceDefinition $definition, OperationContext $context, DataSurfaceInterface $built, AccountInterface $account, ?ItemGates $gates = NULL): StorageAdapter {
    $routes = [];
    foreach ($built->getDefinitions()->entries() as $key => $entry) {
      $children = $entry->attachment !== NULL
        ? [StorageAdapter::ATTACHED => $entry->attachment]
        : ($entry->variants !== NULL ? $entry->variants->variants : []);
      foreach ($children as $id => $attachment) {
        $child = $attachment->source === NULL ? NULL : $this->registry->getDefinition($attachment->source);
        if ($child?->storage !== NULL) {
          $routes[(string) $key][(string) $id] = $this->storageFor($child, $this->declaring->invokeChild($child, $context, (string) $key, $definition->id)[0], $attachment->child, $account);
        }
      }
    }
    $operation = $this->registry->getOperations($definition->name())[$context->id] ?? NULL;
    $storage = $this->storageInstance($definition->name());
    return new StorageAdapter(
      $storage,
      $context,
      $routes,
      $definition->identity,
      $this->declaring->omittedKeys($this->shapeOf($definition, $context), $built),
      $operation?->kind() === OperationDefinition::DELETE,
      $account,
      $gates,
      // Decision: see
      // docs/decisions.md#a-config-object-stores-a-reference-by-uuid.
      $storage instanceof ConfigObjectStorage || $storage instanceof ConfigEntityStorage ? $this->declaring->references() : NULL,
    );
  }

  /**
   * Gets the storage class a resource names, as the service it is.
   *
   * What the config schema generator asks which keys the storage keeps
   * otherwise than they are asked for (translatedKeys()).
   *
   * @param string $resource
   *   The resource, by name.
   *
   * @return \Drupal\data_surface\Resource\ResourceStorageInterface
   *   Its storage.
   *
   * @throws \LogicException
   *   When it names none.
   */
  public function storageInstance(string $resource): ResourceStorageInterface {
    $definition = $this->registry->getDefinition($resource);
    if ($definition->storage === NULL) {
      throw new \LogicException(sprintf('%s names no storage.', $definition->id));
    }
    return $this->declaring->instance((string) $definition->storage, ResourceStorageInterface::class);
  }

}
