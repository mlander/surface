<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\data_surface\Hook\ResourcePluginHooks;
use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface\Resource\Attribute\DerivesResources;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\Attribute\Variant;
use Drupal\data_surface\Resource\DerivedOperation;
use Drupal\data_surface\Resource\HasOutputsInterface;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface\Resource\ResourceDeriverInterface;

/**
 * Every resource and structure, with operations, alters and variants.
 *
 * The compiler pass lists the classes in src/Resource, src/Structure and
 * src/Alter; the plugin definitions list the plugins that are their own
 * structure (#[UsesStructure] with no argument). This reads their
 * attributes, once, and caches what it read in the discovery bin the way
 * a plugin manager caches its definitions. The cache id carries a hash
 * of both lists, so a container rebuilt with a module more or less, or a
 * plugin more or less, is a different entry and needs no clearing; an
 * attribute edited in place needs a cache rebuild, exactly as a plugin
 * attribute does.
 *
 * A deriver (#[DerivesResources]) is asked for its resources here too,
 * and each is read into a definition with no class, under the id
 * `<base>:<derivative>`. What it derived from is live site state, so the
 * entry carries the cache tags its derivatives name: invalidating one (a
 * field saved, a form display changed) clears the entry, and, through
 * ResourceRegistryInvalidator, what this request already read.
 *
 * Two kinds of refusal, kept apart on purpose:
 * - What is wrong with the attributes themselves (an operation with
 *   nothing to belong to, an operation method that is not static, a
 *   class whose shape method is not static, two classes claiming one id,
 *   a class with the attribute of one kind and the interface of the
 *   other, a reference to a class that exists and is neither a resource
 *   nor a structure) is refused here, when discovery is read, because it
 *   cannot be pinned on one build.
 * - What is wrong with one resource or structure (two operations with
 *   one id, a refiner watching a key the shape never declares) is
 *   refused when that one is asked for, naming the offender, so one
 *   module's mistake does not take every other one down with it.
 */
final class ResourceRegistry {

  /**
   * The cache id prefix in the discovery bin.
   */
  protected const CACHE_PREFIX = 'data_surface:resources:';

  /**
   * The definitions, keyed by name, once read.
   *
   * @var array<class-string, \Drupal\data_surface\ResourceBuild\ResourceDefinition>|null
   */
  protected ?array $definitions = NULL;

  /**
   * The cache tags what is read depends on, once read.
   *
   * @var string[]
   */
  protected array $cacheTags = [];

  /**
   * Constructs a ResourceRegistry.
   *
   * @param array<string, array<class-string, mixed>> $classes
   *   The class lists the compiler pass wrote, by kind, valued with the
   *   module each class is in; `overrides` is keyed by deriver, then by
   *   the class carrying its override attribute.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The discovery cache bin.
   * @param \Drupal\data_surface\ResourceBuild\ResourcePlugins|null $plugins
   *   The plugin definitions, for the plugins that are their own
   *   structure; NULL reads none.
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface|null $classResolver
   *   What returns each deriver as the autowired service discovery
   *   registered; NULL asks no deriver.
   */
  public function __construct(
    protected readonly array $classes,
    protected readonly CacheBackendInterface $cache,
    protected readonly ?ResourcePlugins $plugins = NULL,
    protected readonly ?ClassResolverInterface $classResolver = NULL,
  ) {
  }

  /**
   * Gets every discovered resource and structure.
   *
   * @return array<string, \Drupal\data_surface\ResourceBuild\ResourceDefinition>
   *   The definitions, keyed by name: a class, or a derived resource's
   *   id.
   *
   * @throws \LogicException
   *   When an attribute is misplaced, two classes claim one id, or a
   *   deriver is no ResourceDeriverInterface or names a derivative with a
   *   colon.
   */
  public function getDefinitions(): array {
    if ($this->definitions !== NULL) {
      return $this->definitions;
    }
    $own = $this->plugins?->ownStructures() ?? [];
    $cid = self::CACHE_PREFIX . hash('xxh3', serialize($own === [] ? $this->classes : [$this->classes, $own]));
    $cached = $this->cache->get($cid);
    $this->definitions = $cached !== FALSE && is_array($cached->data) ? $cached->data : $this->discover($own);
    $this->cacheTags = [];
    foreach ($this->definitions as $definition) {
      $this->cacheTags = Cache::mergeTags($this->cacheTags, $definition->cacheTags);
    }
    if ($cached === FALSE || !is_array($cached->data)) {
      $this->cache->set($cid, $this->definitions, Cache::PERMANENT, $this->cacheTags);
    }
    return $this->definitions;
  }

  /**
   * Forgets what this request read, when what it was read from changed.
   *
   * The discovery bin's entry goes with the tags; this is the copy held
   * since, which a request that saves a field and then builds a resource
   * would otherwise read stale.
   *
   * @param string[] $tags
   *   The tags being invalidated.
   */
  public function invalidateTags(array $tags): void {
    if ($this->definitions !== NULL && array_intersect($tags, $this->cacheTags) !== []) {
      $this->definitions = NULL;
      $this->cacheTags = [];
    }
  }

  /**
   * Lists the derivers discovered, by base id.
   *
   * @return array<string, array{class: class-string, module: string, overrides: class-string|null, overridden: array<class-string, string>}>
   *   Each deriver's class and module, the attribute an override of its
   *   derivation carries and the classes that carry it, keyed by base id
   *   and sorted.
   */
  public function getDerivers(): array {
    $derivers = [];
    foreach ($this->classes['derivers'] ?? [] as $class => $module) {
      $attribute = (new \ReflectionClass($class))->getAttributes(DerivesResources::class)[0]->newInstance();
      $derivers[$attribute->base] = [
        'class' => $class,
        'module' => (string) $module,
        'overrides' => $attribute->overrides,
        'overridden' => $this->classes['overrides'][$class] ?? [],
      ];
    }
    ksort($derivers);
    return $derivers;
  }

  /**
   * Answers whether a resource or structure is discovered, by class or id.
   *
   * @param string $resource
   *   The class, or its #[Resource] or #[Structure] id.
   *
   * @return bool
   *   TRUE when discovery found it.
   */
  public function hasDefinition(string $resource): bool {
    return $this->find($resource) !== NULL;
  }

  /**
   * Gets one resource or structure, by class or by id.
   *
   * @param string $resource
   *   The class, or its #[Resource] or #[Structure] id.
   *
   * @return \Drupal\data_surface\ResourceBuild\ResourceDefinition
   *   The definition.
   *
   * @throws \InvalidArgumentException
   *   When nothing discovered has that class or id.
   */
  public function getDefinition(string $resource): ResourceDefinition {
    return $this->find($resource) ?? throw new \InvalidArgumentException(sprintf(
      'No resource or structure "%s" was discovered: one is a class carrying #[Resource] or #[Structure] in an enabled module\'s src/Resource or src/Structure directory, or a plugin carrying #[UsesStructure] with no argument.',
      $resource,
    ));
  }

  /**
   * Gets the operations of one resource or structure, refusing a clash.
   *
   * @param string $resource
   *   The class or id.
   *
   * @return array<string, \Drupal\data_surface\ResourceBuild\OperationDefinition>
   *   The operations, keyed by id.
   *
   * @throws \LogicException
   *   When two providers declare one operation id, or two operations are
   *   marked the default.
   */
  public function getOperations(string $resource): array {
    $definition = $this->getDefinition($resource);
    $operations = [];
    $default = NULL;
    foreach ($definition->operations as $operation) {
      if ($operation->default) {
        if ($default !== NULL) {
          throw new \LogicException(sprintf(
            '%s marks two operations the default, "%s" from %s and "%s" from %s. The default is the one operation a parent invokes when nothing else picks one, so there is at most one.',
            $definition->id,
            $default->id,
            $default->describe(),
            $operation->id,
            $operation->describe(),
          ));
        }
        $default = $operation;
      }
      if (isset($operations[$operation->id])) {
        throw new \LogicException(sprintf(
          '%s has two operations with the id "%s", from %s and from %s. An operation id is unique within its resource or structure, because it is the id a context is addressed by.',
          $definition->id,
          $operation->id,
          $operations[$operation->id]->describe(),
          $operation->describe(),
        ));
      }
      $operations[$operation->id] = $operation;
    }
    return $operations;
  }

  /**
   * Gets one operation of one resource or structure.
   *
   * @param string $resource
   *   The class or id.
   * @param string $id
   *   The operation id.
   *
   * @return \Drupal\data_surface\ResourceBuild\OperationDefinition
   *   The operation.
   *
   * @throws \InvalidArgumentException
   *   When it has no such operation.
   */
  public function getOperation(string $resource, string $id): OperationDefinition {
    $operations = $this->getOperations($resource);
    return $operations[$id] ?? throw new \InvalidArgumentException(sprintf(
      '%s has no "%s" operation; it has %s.',
      $this->getDefinition($resource)->id,
      $id,
      $operations === [] ? 'none' : implode(', ', array_keys($operations)),
    ));
  }

  /**
   * Gets the alters of one resource or structure, in the order they apply.
   *
   * @param string $resource
   *   The class or id.
   *
   * @return \Drupal\data_surface\ResourceBuild\AlterDefinition[]
   *   The alters.
   */
  public function getAlters(string $resource): array {
    return $this->getDefinition($resource)->alters;
  }

  /**
   * Gets the structures marked as variants for one slot of one class.
   *
   * What fills an open slot: one declared with attachBy() and no
   * children, which the build step fills from here.
   *
   * @param string $resource
   *   The class or id holding the slot.
   * @param string $key
   *   The slot key.
   *
   * @return array<string, class-string>
   *   The variant classes, keyed by the deciding value.
   */
  public function getVariants(string $resource, string $key): array {
    return $this->getDefinition($resource)->variants[$key] ?? [];
  }

  /**
   * Finds a resource or structure by class, then by id.
   *
   * @param string $resource
   *   The class or id.
   *
   * @return \Drupal\data_surface\ResourceBuild\ResourceDefinition|null
   *   The definition, or NULL.
   */
  protected function find(string $resource): ?ResourceDefinition {
    $definitions = $this->getDefinitions();
    if (isset($definitions[$resource])) {
      return $definitions[$resource];
    }
    foreach ($definitions as $definition) {
      if ($definition->id === $resource) {
        return $definition;
      }
    }
    return NULL;
  }

  /**
   * Reads every attribute on every discovered class.
   *
   * @param array<class-string, array{module: string, id: string}> $own
   *   The plugins that are their own structure, with the module and the id
   *   their plugin definitions give them.
   *
   * @return array<string, \Drupal\data_surface\ResourceBuild\ResourceDefinition>
   *   The definitions, keyed by name.
   */
  protected function discover(array $own = []): array {
    $resources = [];
    $ids = [];
    $found = [];
    foreach ($this->classes['resources'] ?? [] as $class => $module) {
      $found[$class] = [$module, NULL];
    }
    // Decision: see docs/decisions.md#a-plugin-that-is-its-own-structure.
    foreach ($own as $class => ['module' => $module, 'id' => $id]) {
      $found[$class] ??= [$module, $id];
    }
    foreach ($found as $class => [$module, $plugin_id]) {
      $reflection = new \ReflectionClass($class);
      $kind = self::assertResourceClass($reflection, $plugin_id !== NULL);
      $attribute = ($reflection->getAttributes($kind === ResourceDefinition::RESOURCE ? Resource::class : Structure::class)[0] ?? NULL)?->newInstance();
      $id = $attribute->id ?? (string) $plugin_id;
      if (isset($ids[$id])) {
        throw new \LogicException(sprintf(
          'Two classes claim the id "%s": %s and %s. A resource or structure id is what things outside PHP ask for it by, so it names one class.',
          $id,
          $ids[$id],
          $class,
        ));
      }
      $ids[$id] = $class;
      $resources[$class] = new ResourceDefinition(
        class: $class,
        id: $id,
        module: $module,
        identity: $attribute instanceof Resource ? array_values($attribute->identity) : [],
        storage: $attribute instanceof Resource ? $attribute->storage : NULL,
        access: $attribute?->access,
        refiners: self::refinersOf($reflection),
        kind: $kind,
      );
      if ($attribute instanceof Resource && $attribute->storage === NULL && $attribute->identity === [] && $plugin_id === NULL && ($this->plugins?->usedBy($class) ?? []) === []) {
        // Decision: see docs/decisions.md#a-resource-has-storage-or-a-host.
        throw new \LogicException(sprintf(
          '%s carries #[Resource] and names no storage, no identity and no plugin that hosts it: everything it says, a structure says. Give it storage: in #[Resource], or carry #[Structure] and implement %s.',
          $class,
          StructureInterface::class,
        ));
      }
    }
    $operations = [];
    $bases = [];
    foreach ($this->derive($ids) as $id => [$definition, $derived_operations]) {
      $resources[$id] = $definition;
      $operations[$id] = $derived_operations;
      $bases[(string) $definition->base][] = $id;
    }

    // A plugin that is its own structure may carry its operations too.
    $operation_classes = $this->classes['operations'] ?? [];
    foreach ($own as $class => ['module' => $module]) {
      $operation_classes[$class] ??= $module;
    }
    foreach ($operation_classes as $class => $module) {
      foreach ((new \ReflectionClass($class))->getMethods() as $method) {
        foreach ($method->getAttributes(Operation::class) as $attribute) {
          $operation = $attribute->newInstance();
          $of = $operation->of ?? (isset($resources[$class]) ? $class : throw new \LogicException(sprintf(
            '#[Operation(\'%s\')] on %s::%s() names no resource or structure: `of` may be left out only on the class carrying #[Resource] or #[Structure] itself.',
            $operation->id,
            $class,
            $method->getName(),
          )));
          if (!$method->isStatic()) {
            throw new \LogicException(sprintf(
              '#[Operation(\'%s\')] on %s::%s() is not static: an operation builds a context before anything is built to ask.',
              $operation->id,
              $class,
              $method->getName(),
            ));
          }
          if (self::isUndiscovered($of, $resources, sprintf('#[Operation(\'%s\')] on %s::%s()', $operation->id, $class, $method->getName()))) {
            continue;
          }
          $operations[$of][] = new OperationDefinition(
            id: $operation->id,
            label: $operation->label,
            resource: $of,
            class: $class,
            method: $method->getName(),
            permission: $operation->permission,
            module: $module,
            parameters: array_map(OperationParameter::fromReflection(...), $method->getParameters()),
            default: $operation->default,
          );
        }
      }
    }

    $alters = [];
    foreach ($this->classes['alters'] ?? [] as $class => $module) {
      $reflection = new \ReflectionClass($class);
      $attribute = $reflection->getAttributes(Alters::class)[0]->newInstance();
      // A deriver's base names everything it derived.
      $named = $bases[$attribute->resource] ?? [$attribute->resource];
      $alter = new AlterDefinition(
        class: $class,
        module: $module,
        operations: array_values($attribute->operations),
        refiners: self::refinersOf($reflection),
      );
      foreach ($named as $altered) {
        if (!isset($bases[$attribute->resource]) && self::isUndiscovered($altered, $resources, sprintf('#[Alters] on %s', $class))) {
          continue;
        }
        $alters[$altered][] = $alter;
      }
    }

    $variants = [];
    foreach ($resources as $class => $definition) {
      if ($definition->isDerived()) {
        continue;
      }
      foreach ((new \ReflectionClass($class))->getAttributes(Variant::class) as $attribute) {
        $variant = $attribute->newInstance();
        if (!self::isUndiscovered($variant->of, $resources, sprintf('#[Variant] on %s', $class))) {
          $variants[$variant->of][$variant->key][$variant->value] = $class;
        }
      }
    }

    foreach ($resources as $class => $definition) {
      $resources[$class] = $definition->with($operations[$class] ?? [], $alters[$class] ?? [], $variants[$class] ?? []);
    }
    return $resources;
  }

  /**
   * Asks every deriver for its resources, and reads each into a definition.
   *
   * @param array<string, class-string> $ids
   *   The ids the classes claimed, which a derived resource may not claim
   *   again; extended with each derived id.
   *
   * @return array<string, array{0: \Drupal\data_surface\ResourceBuild\ResourceDefinition, 1: \Drupal\data_surface\ResourceBuild\OperationDefinition[]}>
   *   Each derived resource's definition, and its operations, keyed by id.
   *
   * @throws \LogicException
   *   When a deriver does not implement ResourceDeriverInterface, a
   *   derivative id holds a colon, or an id is claimed twice.
   */
  protected function derive(array &$ids): array {
    if ($this->classResolver === NULL) {
      return [];
    }
    $derived = [];
    foreach ($this->getDerivers() as $base => ['class' => $class, 'module' => $module, 'overridden' => $overridden]) {
      $deriver = $this->classResolver->getInstanceFromDefinition($class);
      if (!$deriver instanceof ResourceDeriverInterface) {
        throw new \LogicException(sprintf('%s carries #[DerivesResources] but does not implement %s.', $class, ResourceDeriverInterface::class));
      }
      foreach ($deriver->derivatives($overridden) as $derivative => $surface) {
        $id = $base . ':' . $derivative;
        if (str_contains((string) $derivative, ':')) {
          throw new \LogicException(sprintf(
            '%s::derivatives() answered "%s": a derivative id holds no colon, which separates it from the base.',
            $class,
            $derivative,
          ));
        }
        if (isset($ids[$id])) {
          throw new \LogicException(sprintf('Two classes claim the id "%s": %s and %s.', $id, $ids[$id], $class));
        }
        $ids[$id] = $class;
        $refiners = [];
        foreach ($surface->refiners as $refining) {
          array_push($refiners, ...self::refinersOf(new \ReflectionClass($refining)));
        }
        $derived[$id] = [
          new ResourceDefinition(
            class: NULL,
            id: $id,
            module: $module,
            identity: array_values($surface->identity),
            storage: $surface->storage,
            access: $surface->access,
            refiners: $refiners,
            inputs: $surface->inputs,
            outputs: $surface->outputs,
            base: $base,
            deriver: $class,
            label: $surface->label,
            description: $surface->description,
            notes: $surface->notes,
            cacheTags: array_values($surface->cacheTags),
            kind: $surface->kind(),
          ),
          array_map(static fn (DerivedOperation $operation): OperationDefinition => new OperationDefinition(
            id: $operation->id,
            label: $operation->label,
            resource: $id,
            class: $operation->context->class,
            method: $operation->context->method,
            permission: $operation->permission,
            module: $module,
            parameters: array_map(
              OperationParameter::fromDeclaration(...),
              array_keys($operation->parameters),
              array_values($operation->parameters),
            ),
            default: $operation->default,
            context: $operation->context,
          ), array_values($surface->operations)),
        ];
      }
    }
    return $derived;
  }

  /**
   * Refuses a discovered class that cannot be what it says, and says what.
   *
   * A class carries the attribute of its kind and implements the
   * interface of the same kind: #[Resource] and ResourceInterface, or
   * #[Structure] and StructureInterface. A plugin that is its own
   * structure carries neither attribute, or #[Structure], and implements
   * StructureInterface. A class carrying both attributes is refused. Both
   * interfaces extend DefinesInputsInterface, whose shape methods are
   * static. PHP itself refuses a class that implements the interface
   * with an instance defineInputs(), so the class that reaches here with
   * one is a class that dropped the interface to get it past the
   * compiler; it is named for what it is.
   *
   * @param \ReflectionClass $class
   *   The class carrying #[Resource] or #[Structure], or a plugin that is
   *   its own structure.
   * @param bool $plugin
   *   Whether it was found as a plugin rather than in src/Resource or
   *   src/Structure.
   *
   * @return string
   *   Its kind, ResourceDefinition::RESOURCE or ::STRUCTURE.
   *
   * @throws \LogicException
   *   Naming the class and what it is missing.
   */
  protected static function assertResourceClass(\ReflectionClass $class, bool $plugin): string {
    $resource = $class->getAttributes(Resource::class) !== [];
    $structure = $class->getAttributes(Structure::class) !== [];
    if ($resource && $structure) {
      throw new \LogicException(sprintf(
        '%s carries both #[Resource] and #[Structure]. A class is one kind: a resource has identity and storage that operations act on, a structure is held by something else.',
        $class->getName(),
      ));
    }
    $kind = $resource ? ResourceDefinition::RESOURCE : ResourceDefinition::STRUCTURE;
    $interface = $resource ? ResourceInterface::class : StructureInterface::class;
    $how = match (TRUE) {
      $resource => 'carries #[Resource]',
      $structure => 'carries #[Structure]',
      default => 'is its own structure, by #[UsesStructure] with no argument,',
    };
    foreach (['defineInputs' => $interface, 'defineOutputs' => HasOutputsInterface::class] as $method => $needed) {
      if ($class->hasMethod($method) && !$class->getMethod($method)->isStatic()) {
        throw new \LogicException(sprintf(
          '%s %s and declares %s() as an instance method. A shape is a property of its class and takes nothing but the shape to fill: declare it `public static function %s(ShapeInterface $%s): void`, and implement %s.',
          $class->getName(),
          $how,
          $method,
          $method,
          $method === 'defineInputs' ? 'inputs' : 'outputs',
          $needed,
        ));
      }
    }
    if (!$class->implementsInterface($interface)) {
      throw new \LogicException(sprintf('%s %s but does not implement %s.', $class->getName(), $how, $interface));
    }
    return $kind;
  }

  /**
   * Answers whether a referenced resource or structure is simply not here.
   *
   * A class that does not load belongs to a module that is not enabled,
   * so whatever names it has nothing to apply to and is skipped; so does
   * a class that loads and is a resource or structure without being
   * discovered (it carries #[Resource] or #[Structure], or it is a plugin
   * that is its own structure), because a test's autoloader knows every
   * extension, enabled or not. A class that loads and is neither is a
   * mistake.
   *
   * @param string $class
   *   The referenced class.
   * @param array<class-string, \Drupal\data_surface\ResourceBuild\ResourceDefinition> $resources
   *   The discovered resources and structures.
   * @param string $referrer
   *   What referenced it, for the message.
   *
   * @return bool
   *   TRUE when the reference is to a module that is not enabled.
   *
   * @throws \LogicException
   *   When the class loads and is neither a resource nor a structure.
   */
  protected static function isUndiscovered(string $class, array $resources, string $referrer): bool {
    if (isset($resources[$class])) {
      return FALSE;
    }
    // Decision: see docs/decisions.md#a-class-that-is-not-there.
    $reflection = class_exists($class) ? new \ReflectionClass($class) : NULL;
    if ($reflection !== NULL && $reflection->getAttributes(Resource::class) === [] && $reflection->getAttributes(Structure::class) === [] && !ResourcePluginHooks::isOwnStructure($class)) {
      throw new \LogicException(sprintf(
        '%s names %s, which is no discovered resource or structure: one carries #[Resource] or #[Structure] and lives in its module\'s src/Resource or src/Structure directory, or is a plugin carrying #[UsesStructure] with no argument.',
        $referrer,
        $class,
      ));
    }
    return TRUE;
  }

  /**
   * Reads the #[RefinesInput] methods of one class.
   *
   * @param \ReflectionClass $class
   *   The resource, structure or alter class.
   *
   * @return \Drupal\data_surface\ResourceBuild\RefinerDefinition[]
   *   The refiners, in method order.
   */
  protected static function refinersOf(\ReflectionClass $class): array {
    $refiners = [];
    foreach ($class->getMethods() as $method) {
      foreach ($method->getAttributes(RefinesInput::class) as $attribute) {
        $refines = $attribute->newInstance();
        $parameters = $method->getParameters();
        $refiners[] = new RefinerDefinition(
          class: $class->getName(),
          method: $method->getName(),
          key: $refines->key,
          watches: $refines->watches === NULL ? NULL : array_values($refines->watches),
          parameters: array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            array_slice($parameters, 1),
          ),
          takesDefinition: $parameters !== [],
          isStatic: $method->isStatic(),
          runtime: $refines->runtime,
        );
      }
    }
    return $refiners;
  }

}
