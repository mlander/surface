<?php

declare(strict_types=1);

namespace Drupal\data_surface\SurfaceBuild;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DataSurfaceRefinerInterface;

/**
 * The engine's refiner, spoken by one class's #[RefinesInput] methods.
 *
 * One of these per class that refines — the surface, then each alter —
 * registered in the owner's chain of every key it refines, in that
 * order. So the engine runs the owner's methods first and the alters'
 * after, hands each link a deep clone, and checks every link narrower;
 * nothing about refinement, its narrowing check, its cacheability or the
 * form's AJAX and discard machinery knows a method is behind the link.
 *
 * Asked to refine one key, it calls that key's methods on its class in
 * declaration order, each with the definition the one before returned,
 * and with the sibling values each one watches, by parameter order. The
 * engine calls it only once every key the key refines against holds a
 * value; for a key two methods refine, that is the union of what both
 * watch, because the engine gates per key rather than per method.
 *
 * The call is made through reflection, which is what coerces a sibling
 * value the way PHP coerces a scalar argument outside strict mode. A
 * form hands refinement its raw input, so a checkbox arrives as "1" and
 * a number as "5"; a refiner declaring bool and int parameters receives
 * TRUE and 5, rather than a TypeError from this file's strict types.
 *
 * It rides inside the sealed surface, so inside cached forms. A surface's
 * link holds the surface's class name, because a surface's refiners are
 * static and a surface is never instantiated; an alter's link holds the
 * alter, an autowired service whose refiners may be instance methods,
 * and the dependency serialization trait stores a service by its id, so
 * a serialized surface carries no container either way. A static method
 * and an instance method are dispatched alike.
 *
 * An alter's method on a key the alter itself added is bound under the
 * mount, `third_party_settings`, the one key the engine knows the
 * alter's keys by. Asked to refine the mount, the link refines only its
 * own module's property inside it, each method handed that property's
 * definition, so the method reads exactly as it would on an owner's key.
 * A method watching a key its alter added is handed that key's value
 * the same way, read at its dotted path, `third_party_settings.<module>.
 * <key>`, which is the name the engine knows the dependency by.
 *
 * @internal
 */
final class RefinesInputRefiner implements DataSurfaceRefinerInterface {

  use DependencySerializationTrait;

  /**
   * The key an alter's own keys are mounted under.
   */
  public const MOUNT = 'third_party_settings';

  /**
   * Constructs a RefinesInputRefiner.
   *
   * @param object|class-string $instance
   *   The surface class, or the alter instance, the methods are on.
   * @param array<string, \Drupal\data_surface\SurfaceBuild\RefinerDefinition[]> $bindings
   *   The methods, keyed by the input key they refine; an alter's methods
   *   on its own mounted keys under self::MOUNT.
   * @param string|null $module
   *   The alter's module, whose property inside the mount the methods
   *   bound under self::MOUNT refine; NULL for a surface's own link.
   * @param array<string, string> $paths
   *   The keys the alter mounted that its methods watch, each mapped to
   *   the dotted path its value is handed under.
   */
  public function __construct(
    protected object|string $instance,
    protected array $bindings,
    protected ?string $module = NULL,
    protected array $paths = [],
  ) {
  }

  /**
   * Spells the dotted path of a key an alter mounted.
   *
   * @param string $module
   *   The alter's module.
   * @param string $key
   *   The key, as the alter added it.
   *
   * @return string
   *   `third_party_settings.<module>.<key>`.
   */
  public static function path(string $module, string $key): string {
    return self::MOUNT . '.' . $module . '.' . $key;
  }

  /**
   * {@inheritdoc}
   */
  public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    if ($name === self::MOUNT && $this->module !== NULL) {
      return $this->refineMounted($definition, $values);
    }
    foreach ($this->bindings[$name] ?? [] as $refiner) {
      $definition = $this->invoke($refiner, $definition, $values);
    }
    return $definition;
  }

  /**
   * Refines the alter's own keys inside the mount, in place.
   *
   * Decision: see docs/decisions.md#an-alter-refines-its-own-mounted-key.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $mount
   *   The mount's map, a copy the engine handed over.
   * @param array $values
   *   Sibling values, keyed by input key.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The mount, with this module's keys refined.
   */
  protected function refineMounted(DataDefinitionInterface $mount, array $values): DataDefinitionInterface {
    $own = $mount instanceof ComplexDataDefinitionInterface ? $mount->getPropertyDefinition((string) $this->module) : NULL;
    if (!$own instanceof MapDataDefinition) {
      return $mount;
    }
    foreach ($this->bindings[self::MOUNT] ?? [] as $refiner) {
      $key = $own->getPropertyDefinition($refiner->key);
      if ($key !== NULL) {
        $own->setPropertyDefinition($refiner->key, $this->invoke($refiner, $key, $values));
      }
    }
    return $mount;
  }

  /**
   * Calls one method with the definition and the siblings it watches.
   *
   * @param \Drupal\data_surface\SurfaceBuild\RefinerDefinition $refiner
   *   The method.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to refine.
   * @param array $values
   *   Sibling values, keyed by input key, a mounted one by its dotted
   *   path; every watched key is present.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   What the method returned.
   *
   * @throws \LogicException
   *   When the method returned something other than a definition.
   */
  public function invoke(RefinerDefinition $refiner, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    $arguments = [$definition];
    foreach ($refiner->watched() as $key) {
      $arguments[] = $values[$this->paths[$key] ?? $key] ?? NULL;
    }
    $method = new \ReflectionMethod($this->instance, $refiner->method);
    $refined = $method->invokeArgs($method->isStatic() || !is_object($this->instance) ? NULL : $this->instance, $arguments);
    if (!$refined instanceof DataDefinitionInterface) {
      throw new \LogicException(sprintf(
        '%s returned %s; a #[RefinesInput] method returns the definition it was handed, tightened.',
        $refiner->describe(),
        get_debug_type($refined),
      ));
    }
    return $refined;
  }

}
