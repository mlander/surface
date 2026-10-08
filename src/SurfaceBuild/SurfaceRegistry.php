<?php

declare(strict_types=1);

namespace Drupal\data_surface\SurfaceBuild;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\data_surface\Surface\Attribute\AltersSurface;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\Attribute\Situation;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\Attribute\SurfaceVariant;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * Every discovered surface, with its situations, alters and variants.
 *
 * The compiler pass lists the classes; this reads their attributes, once,
 * and caches what it read in the discovery bin the way a plugin manager
 * caches its definitions. The cache id carries a hash of the class lists,
 * so a container rebuilt with a module more or less is a different entry
 * and needs no clearing; an attribute edited in place needs a cache
 * rebuild, exactly as a plugin attribute does.
 *
 * Two kinds of refusal, kept apart on purpose:
 * - What is wrong with the attributes themselves — a situation with no
 *   surface to belong to, a situation method that is not static, two
 *   surfaces claiming one id, a reference to a class that exists and is
 *   not a surface — is refused here, when discovery is read, because it
 *   cannot be pinned on one surface's build.
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
   * Constructs a SurfaceRegistry.
   *
   * @param array<string, array<class-string, string>> $classes
   *   The class lists the compiler pass wrote, by kind, valued with the
   *   module each class is in.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The discovery cache bin.
   */
  public function __construct(
    protected readonly array $classes,
    protected readonly CacheBackendInterface $cache,
  ) {
  }

  /**
   * Gets every discovered surface.
   *
   * @return array<class-string, \Drupal\data_surface\SurfaceBuild\SurfaceDefinition>
   *   The definitions, keyed by surface class.
   *
   * @throws \LogicException
   *   When an attribute is misplaced or two surfaces claim one id.
   */
  public function getDefinitions(): array {
    if ($this->definitions !== NULL) {
      return $this->definitions;
    }
    $cid = self::CACHE_PREFIX . hash('xxh3', serialize($this->classes));
    $cached = $this->cache->get($cid);
    if ($cached !== FALSE && is_array($cached->data)) {
      return $this->definitions = $cached->data;
    }
    $this->definitions = $this->discover();
    $this->cache->set($cid, $this->definitions);
    return $this->definitions;
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
      'No surface "%s" was discovered: a surface is a class carrying #[Surface] in an enabled module\'s src/Surface directory.',
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
   *   When two providers declare one situation id.
   */
  public function getSituations(string $surface): array {
    $definition = $this->getDefinition($surface);
    $situations = [];
    foreach ($definition->situations as $situation) {
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
   * @return array<class-string, \Drupal\data_surface\SurfaceBuild\SurfaceDefinition>
   *   The definitions, keyed by surface class.
   */
  protected function discover(): array {
    $surfaces = [];
    $ids = [];
    foreach ($this->classes['surfaces'] ?? [] as $class => $module) {
      $reflection = new \ReflectionClass($class);
      $attribute = $reflection->getAttributes(Surface::class)[0]->newInstance();
      if (!$reflection->implementsInterface(SurfaceInterface::class)) {
        throw new \LogicException(sprintf('%s carries #[Surface] but does not implement %s.', $class, SurfaceInterface::class));
      }
      if (isset($ids[$attribute->id])) {
        throw new \LogicException(sprintf(
          'Two surfaces claim the id "%s": %s and %s. A surface id is what things outside PHP ask for it by, so it names one class.',
          $attribute->id,
          $ids[$attribute->id],
          $class,
        ));
      }
      $ids[$attribute->id] = $class;
      $surfaces[$class] = new SurfaceDefinition(
        class: $class,
        id: $attribute->id,
        module: $module,
        identity: array_values($attribute->identity),
        target: $attribute->target,
        access: $attribute->access,
        refiners: self::refinersOf($reflection),
      );
    }

    $situations = [];
    foreach ($this->classes['situations'] ?? [] as $class => $module) {
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
          );
        }
      }
    }

    $alters = [];
    foreach ($this->classes['alters'] ?? [] as $class => $module) {
      $reflection = new \ReflectionClass($class);
      $attribute = $reflection->getAttributes(AltersSurface::class)[0]->newInstance();
      if (self::isUndiscovered($attribute->surface, $surfaces, sprintf('#[AltersSurface] on %s', $class))) {
        continue;
      }
      $alters[$attribute->surface][] = new AlterDefinition(
        class: $class,
        module: $module,
        situations: array_values($attribute->situations),
        refiners: self::refinersOf($reflection),
      );
    }

    $variants = [];
    foreach (array_keys($surfaces) as $class) {
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
   * Answers whether a referenced surface is simply not here.
   *
   * A class that does not load belongs to a module that is not enabled,
   * so whatever names it has nothing to apply to and is skipped; so does
   * a class that loads and carries #[Surface] without being discovered,
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
    // phpcs:ignore Drupal.Files.LineLength.TooLong
    // SKETCH GAP: the sketch does not say what an alter, situation or variant naming a surface that is not there means; a class that does not load, or loads and carries #[Surface] (its module is off, though an autoloader that knows every extension still finds it), is skipped; one that loads and is no surface is refused.
    if (class_exists($class) && (new \ReflectionClass($class))->getAttributes(Surface::class) === []) {
      throw new \LogicException(sprintf(
        '%s names %s, which is not a discovered surface: a surface carries #[Surface] and lives in its module\'s src/Surface directory.',
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
        );
      }
    }
    return $refiners;
  }

}
