<?php

declare(strict_types=1);

namespace Drupal\data_surface\SurfaceBuild;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\data_surface\Hook\SurfacePluginHooks;
use Drupal\data_surface\Surface\Attribute\AltersSurface;
use Drupal\data_surface\Surface\Attribute\DerivesSurfaces;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\Attribute\Situation;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\Attribute\SurfaceVariant;
use Drupal\data_surface\Surface\DerivedSituation;
use Drupal\data_surface\Surface\HasOutputsInterface;
use Drupal\data_surface\Surface\SurfaceDeriverInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * Every discovered surface, with its situations, alters and variants.
 *
 * The compiler pass lists the classes in src/Surface and src/SurfaceAlter;
 * the plugin definitions list the plugins that are their own surface
 * (#[UsesSurface] with no argument). This reads their attributes, once,
 * and caches what it read in the discovery bin the way a plugin manager
 * caches its definitions. The cache id carries a hash of both lists, so
 * a container rebuilt with a module more or less, or a plugin more or
 * less, is a different entry and needs no clearing; an attribute edited
 * in place needs a cache rebuild, exactly as a plugin attribute does.
 *
 * A deriver (#[DerivesSurfaces]) is asked for its surfaces here too, and
 * each is read into a definition with no class, under the id
 * `<base>:<derivative>`. What it derived from is live site state, so the
 * entry carries the cache tags its derivatives name: invalidating one (a
 * field saved, a form display changed) clears the entry, and, through
 * SurfaceRegistryInvalidator, what this request already read.
 *
 * Two kinds of refusal, kept apart on purpose:
 * - What is wrong with the attributes themselves — a situation with no
 *   surface to belong to, a situation method that is not static, a
 *   surface whose shape method is not static, two surfaces claiming one
 *   id, a reference to a class that exists and is not a surface — is
 *   refused here, when discovery is read, because it cannot be pinned on
 *   one surface's build.
 * - What is wrong with one surface — two situations with one id, a
 *   refiner watching a key the shape never declares — is refused when
 *   that surface is asked for, naming the offender, so one module's
 *   mistake does not take every other surface down with it.
 */
final class SurfaceRegistry {

  /**
   * The cache id prefix in the discovery bin.
   */
  protected const CACHE_PREFIX = 'data_surface:surfaces:';

  /**
   * The definitions, keyed by surface class, once read.
   *
   * @var array<class-string, \Drupal\data_surface\SurfaceBuild\SurfaceDefinition>|null
   */
  protected ?array $definitions = NULL;

  /**
   * The cache tags what is read depends on, once read.
   *
   * @var string[]
   */
  protected array $cacheTags = [];

  /**
   * Constructs a SurfaceRegistry.
   *
   * @param array<string, array<class-string, mixed>> $classes
   *   The class lists the compiler pass wrote, by kind, valued with the
   *   module each class is in; `overrides` is keyed by deriver, then by
   *   the class carrying its override attribute.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The discovery cache bin.
   * @param \Drupal\data_surface\SurfaceBuild\SurfacePlugins|null $plugins
   *   The plugin definitions, for the plugins that are their own surface;
   *   NULL reads none.
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface|null $classResolver
   *   What returns each deriver as the autowired service discovery
   *   registered; NULL asks no deriver.
   */
  public function __construct(
    protected readonly array $classes,
    protected readonly CacheBackendInterface $cache,
    protected readonly ?SurfacePlugins $plugins = NULL,
    protected readonly ?ClassResolverInterface $classResolver = NULL,
  ) {
  }

  /**
   * Gets every discovered surface.
   *
   * @return array<string, \Drupal\data_surface\SurfaceBuild\SurfaceDefinition>
   *   The definitions, keyed by name: a surface class, or a derived
   *   surface's id.
   *
   * @throws \LogicException
   *   When an attribute is misplaced, two surfaces claim one id, or a
   *   deriver is no SurfaceDeriverInterface or names a derivative with a
   *   colon.
   */
  public function getDefinitions(): array {
    if ($this->definitions !== NULL) {
      return $this->definitions;
    }
    $own = $this->plugins?->ownSurfaces() ?? [];
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
   * since, which a request that saves a field and then builds a surface
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
      $attribute = (new \ReflectionClass($class))->getAttributes(DerivesSurfaces::class)[0]->newInstance();
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
   * Answers whether a surface is discovered, by class or by id.
   *
   * @param string $surface
   *   The surface class or its #[Surface] id.
   *
   * @return bool
   *   TRUE when discovery found it.
   */
  public function hasDefinition(string $surface): bool {
    return $this->find($surface) !== NULL;
  }

  /**
   * Gets one surface, by class or by id.
   *
   * @param string $surface
   *   The surface class or its #[Surface] id.
   *
   * @return \Drupal\data_surface\SurfaceBuild\SurfaceDefinition
   *   The definition.
   *
   * @throws \InvalidArgumentException
   *   When no discovered surface has that class or id.
   */
  public function getDefinition(string $surface): SurfaceDefinition {
    return $this->find($surface) ?? throw new \InvalidArgumentException(sprintf(
      'No surface "%s" was discovered: a surface is a class carrying #[Surface] in an enabled module\'s src/Surface directory, or a plugin carrying #[UsesSurface] with no argument.',
      $surface,
    ));
  }

  /**
   * Gets one surface's situations, refusing a clash.
   *
   * @param string $surface
   *   The surface class or id.
   *
   * @return array<string, \Drupal\data_surface\SurfaceBuild\SituationDefinition>
   *   The situations, keyed by id.
   *
   * @throws \LogicException
   *   When two providers declare one situation id, or two situations are
   *   marked the default.
   */
  public function getSituations(string $surface): array {
    $definition = $this->getDefinition($surface);
    $situations = [];
    $default = NULL;
    foreach ($definition->situations as $situation) {
      if ($situation->default) {
        if ($default !== NULL) {
          throw new \LogicException(sprintf(
            'The %s surface marks two situations the default, "%s" from %s and "%s" from %s. The default is the one situation a parent invokes when nothing else picks one, so there is at most one.',
            $definition->id,
            $default->id,
            $default->describe(),
            $situation->id,
            $situation->describe(),
          ));
        }
        $default = $situation;
      }
      if (isset($situations[$situation->id])) {
        throw new \LogicException(sprintf(
          'The %s surface has two situations with the id "%s", from %s and from %s. A situation id is unique within its surface, because it is the operation a context is addressed by.',
          $definition->id,
          $situation->id,
          $situations[$situation->id]->describe(),
          $situation->describe(),
        ));
      }
      $situations[$situation->id] = $situation;
    }
    return $situations;
  }

  /**
   * Gets one situation of one surface.
   *
   * @param string $surface
   *   The surface class or id.
   * @param string $id
   *   The situation id.
   *
   * @return \Drupal\data_surface\SurfaceBuild\SituationDefinition
   *   The situation.
   *
   * @throws \InvalidArgumentException
   *   When the surface has no such situation.
   */
  public function getSituation(string $surface, string $id): SituationDefinition {
    $situations = $this->getSituations($surface);
    return $situations[$id] ?? throw new \InvalidArgumentException(sprintf(
      'The %s surface has no "%s" situation; it has %s.',
      $this->getDefinition($surface)->id,
      $id,
      $situations === [] ? 'none' : implode(', ', array_keys($situations)),
    ));
  }

  /**
   * Gets the alters of one surface, in the order they apply.
   *
   * @param string $surface
   *   The surface class or id.
   *
   * @return \Drupal\data_surface\SurfaceBuild\AlterDefinition[]
   *   The alters.
   */
  public function getAlters(string $surface): array {
    return $this->getDefinition($surface)->alters;
  }

  /**
   * Gets the surfaces marked as variants for one slot of one surface.
   *
   * What fills an open slot: one declared with attachBy() and no
   * children, which the build step fills from here.
   *
   * @param string $surface
   *   The surface class or id.
   * @param string $key
   *   The slot key.
   *
   * @return array<string, class-string>
   *   The variant surface classes, keyed by the deciding value.
   */
  public function getVariants(string $surface, string $key): array {
    return $this->getDefinition($surface)->variants[$key] ?? [];
  }

  /**
   * Finds a surface by class, then by id.
   *
   * @param string $surface
   *   The surface class or id.
   *
   * @return \Drupal\data_surface\SurfaceBuild\SurfaceDefinition|null
   *   The definition, or NULL.
   */
  protected function find(string $surface): ?SurfaceDefinition {
    $definitions = $this->getDefinitions();
    if (isset($definitions[$surface])) {
      return $definitions[$surface];
    }
    foreach ($definitions as $definition) {
      if ($definition->id === $surface) {
        return $definition;
      }
    }
    return NULL;
  }

  /**
   * Reads every attribute on every discovered class.
   *
   * @param array<class-string, array{module: string, id: string}> $own
   *   The plugins that are their own surface, with the module and the id
   *   their plugin definitions give them.
   *
   * @return array<string, \Drupal\data_surface\SurfaceBuild\SurfaceDefinition>
   *   The definitions, keyed by name.
   */
  protected function discover(array $own = []): array {
    $surfaces = [];
    $ids = [];
    $found = [];
    foreach ($this->classes['surfaces'] ?? [] as $class => $module) {
      $found[$class] = [$module, NULL];
    }
    // Decision: see docs/decisions.md#a-plugin-that-is-its-own-surface.
    foreach ($own as $class => ['module' => $module, 'id' => $id]) {
      $found[$class] ??= [$module, $id];
    }
    foreach ($found as $class => [$module, $plugin_id]) {
      $reflection = new \ReflectionClass($class);
      $attribute = ($reflection->getAttributes(Surface::class)[0] ?? NULL)?->newInstance();
      self::assertSurfaceClass($reflection, $plugin_id !== NULL);
      $id = $attribute->id ?? (string) $plugin_id;
      if (isset($ids[$id])) {
        throw new \LogicException(sprintf(
          'Two surfaces claim the id "%s": %s and %s. A surface id is what things outside PHP ask for it by, so it names one class.',
          $id,
          $ids[$id],
          $class,
        ));
      }
      $ids[$id] = $class;
      $surfaces[$class] = new SurfaceDefinition(
        class: $class,
        id: $id,
        module: $module,
        identity: array_values($attribute->identity ?? []),
        target: $attribute?->target,
        access: $attribute?->access,
        refiners: self::refinersOf($reflection),
      );
    }
    $situations = [];
    $bases = [];
    foreach ($this->derive($ids) as $id => [$definition, $derived_situations]) {
      $surfaces[$id] = $definition;
      $situations[$id] = $derived_situations;
      $bases[(string) $definition->base][] = $id;
    }

    // A plugin that is its own surface may carry its situations too.
    $situation_classes = $this->classes['situations'] ?? [];
    foreach ($own as $class => ['module' => $module]) {
      $situation_classes[$class] ??= $module;
    }
    foreach ($situation_classes as $class => $module) {
      foreach ((new \ReflectionClass($class))->getMethods() as $method) {
        foreach ($method->getAttributes(Situation::class) as $attribute) {
          $situation = $attribute->newInstance();
          $of = $situation->of ?? (isset($surfaces[$class]) ? $class : throw new \LogicException(sprintf(
            '#[Situation(\'%s\')] on %s::%s() names no surface: `of` may be left out only on the surface\'s own class.',
            $situation->id,
            $class,
            $method->getName(),
          )));
          if (!$method->isStatic()) {
            throw new \LogicException(sprintf(
              '#[Situation(\'%s\')] on %s::%s() is not static: a situation builds a context before any surface exists to ask.',
              $situation->id,
              $class,
              $method->getName(),
            ));
          }
          if (self::isUndiscovered($of, $surfaces, sprintf('#[Situation(\'%s\')] on %s::%s()', $situation->id, $class, $method->getName()))) {
            continue;
          }
          $situations[$of][] = new SituationDefinition(
            id: $situation->id,
            label: $situation->label,
            surface: $of,
            class: $class,
            method: $method->getName(),
            permission: $situation->permission,
            module: $module,
            parameters: array_map(SituationParameter::fromReflection(...), $method->getParameters()),
            default: $situation->default,
          );
        }
      }
    }

    $alters = [];
    foreach ($this->classes['alters'] ?? [] as $class => $module) {
      $reflection = new \ReflectionClass($class);
      $attribute = $reflection->getAttributes(AltersSurface::class)[0]->newInstance();
      // A deriver's base names every surface it derived.
      $targets = $bases[$attribute->surface] ?? [$attribute->surface];
      $alter = new AlterDefinition(
        class: $class,
        module: $module,
        situations: array_values($attribute->situations),
        refiners: self::refinersOf($reflection),
      );
      foreach ($targets as $target) {
        if (!isset($bases[$attribute->surface]) && self::isUndiscovered($target, $surfaces, sprintf('#[AltersSurface] on %s', $class))) {
          continue;
        }
        $alters[$target][] = $alter;
      }
    }

    $variants = [];
    foreach ($surfaces as $class => $definition) {
      if ($definition->isDerived()) {
        continue;
      }
      foreach ((new \ReflectionClass($class))->getAttributes(SurfaceVariant::class) as $attribute) {
        $variant = $attribute->newInstance();
        if (!self::isUndiscovered($variant->of, $surfaces, sprintf('#[SurfaceVariant] on %s', $class))) {
          $variants[$variant->of][$variant->key][$variant->value] = $class;
        }
      }
    }

    foreach ($surfaces as $class => $definition) {
      $surfaces[$class] = $definition->with($situations[$class] ?? [], $alters[$class] ?? [], $variants[$class] ?? []);
    }
    return $surfaces;
  }

  /**
   * Asks every deriver for its surfaces, and reads each into a definition.
   *
   * @param array<string, class-string> $ids
   *   The ids the class-based surfaces claimed, which a derived surface
   *   may not claim again; extended with each derived id.
   *
   * @return array<string, array{0: \Drupal\data_surface\SurfaceBuild\SurfaceDefinition, 1: \Drupal\data_surface\SurfaceBuild\SituationDefinition[]}>
   *   Each derived surface's definition, and its situations, keyed by id.
   *
   * @throws \LogicException
   *   When a deriver does not implement SurfaceDeriverInterface, a
   *   derivative id holds a colon, or an id is claimed twice.
   */
  protected function derive(array &$ids): array {
    if ($this->classResolver === NULL) {
      return [];
    }
    $derived = [];
    foreach ($this->getDerivers() as $base => ['class' => $class, 'module' => $module, 'overridden' => $overridden]) {
      $deriver = $this->classResolver->getInstanceFromDefinition($class);
      if (!$deriver instanceof SurfaceDeriverInterface) {
        throw new \LogicException(sprintf('%s carries #[DerivesSurfaces] but does not implement %s.', $class, SurfaceDeriverInterface::class));
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
          throw new \LogicException(sprintf('Two surfaces claim the id "%s": %s and %s.', $id, $ids[$id], $class));
        }
        $ids[$id] = $class;
        $refiners = [];
        foreach ($surface->refiners as $refining) {
          array_push($refiners, ...self::refinersOf(new \ReflectionClass($refining)));
        }
        $derived[$id] = [
          new SurfaceDefinition(
            class: NULL,
            id: $id,
            module: $module,
            identity: array_values($surface->identity),
            target: $surface->target,
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
          ),
          array_map(static fn (DerivedSituation $situation): SituationDefinition => new SituationDefinition(
            id: $situation->id,
            label: $situation->label,
            surface: $id,
            class: $situation->context->class,
            method: $situation->context->method,
            permission: $situation->permission,
            module: $module,
            parameters: array_map(
              SituationParameter::fromDeclaration(...),
              array_keys($situation->parameters),
              array_values($situation->parameters),
            ),
            default: $situation->default,
            context: $situation->context,
          ), array_values($surface->situations)),
        ];
      }
    }
    return $derived;
  }

  /**
   * Refuses a discovered class that cannot be a surface.
   *
   * A surface implements SurfaceInterface, whose shape methods are
   * static. PHP itself refuses a class that implements the interface
   * with an instance defineInputs(), so the class that reaches here with
   * one is a class that dropped the interface to get it past the
   * compiler; it is named for what it is.
   *
   * @param \ReflectionClass $class
   *   The class carrying #[Surface], or a plugin that is its own surface.
   * @param bool $plugin
   *   Whether it was found as a plugin rather than in src/Surface.
   *
   * @throws \LogicException
   *   Naming the class and what it is missing.
   */
  protected static function assertSurfaceClass(\ReflectionClass $class, bool $plugin): void {
    $how = $plugin ? 'is its own surface, by #[UsesSurface] with no argument,' : 'carries #[Surface]';
    foreach (['defineInputs' => SurfaceInterface::class, 'defineOutputs' => HasOutputsInterface::class] as $method => $interface) {
      if ($class->hasMethod($method) && !$class->getMethod($method)->isStatic()) {
        throw new \LogicException(sprintf(
          '%s %s and declares %s() as an instance method. A surface\'s shape is a property of its class and takes nothing but the shape to fill: declare it `public static function %s(ShapeInterface $%s): void`, and implement %s.',
          $class->getName(),
          $how,
          $method,
          $method,
          $method === 'defineInputs' ? 'inputs' : 'outputs',
          $interface,
        ));
      }
    }
    if (!$class->implementsInterface(SurfaceInterface::class)) {
      throw new \LogicException(sprintf('%s %s but does not implement %s.', $class->getName(), $how, SurfaceInterface::class));
    }
  }

  /**
   * Answers whether a referenced surface is simply not here.
   *
   * A class that does not load belongs to a module that is not enabled,
   * so whatever names it has nothing to apply to and is skipped; so does
   * a class that loads and is a surface without being discovered — it
   * carries #[Surface], or it is a plugin that is its own surface —
   * because a test's autoloader knows every extension, enabled or not.
   * A class that loads and is no surface at all is a mistake.
   *
   * @param string $class
   *   The referenced surface class.
   * @param array<class-string, \Drupal\data_surface\SurfaceBuild\SurfaceDefinition> $surfaces
   *   The discovered surfaces.
   * @param string $referrer
   *   What referenced it, for the message.
   *
   * @return bool
   *   TRUE when the reference is to a module that is not enabled.
   *
   * @throws \LogicException
   *   When the class loads and is not a discovered surface.
   */
  protected static function isUndiscovered(string $class, array $surfaces, string $referrer): bool {
    if (isset($surfaces[$class])) {
      return FALSE;
    }
    // Decision: see docs/decisions.md#a-class-that-is-not-there.
    if (class_exists($class) && (new \ReflectionClass($class))->getAttributes(Surface::class) === [] && !SurfacePluginHooks::isOwnSurface($class)) {
      throw new \LogicException(sprintf(
        '%s names %s, which is not a discovered surface: a surface carries #[Surface] and lives in its module\'s src/Surface directory, or is a plugin carrying #[UsesSurface] with no argument.',
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
   *   The surface or alter class.
   *
   * @return \Drupal\data_surface\SurfaceBuild\RefinerDefinition[]
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
