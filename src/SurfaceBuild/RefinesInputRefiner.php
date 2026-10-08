<?php

declare(strict_types=1);

namespace Drupal\data_surface\SurfaceBuild;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\TypedData\DataDefinitionInterface;
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
 * It rides inside the sealed surface, so inside cached forms. The class
 * instance is the one property that may be a service — an alter is an
 * autowired service — and the dependency serialization trait stores a
 * service by its id, so a serialized surface carries no container.
 *
 * @internal
 */
final class RefinesInputRefiner implements DataSurfaceRefinerInterface {

  use DependencySerializationTrait;

  /**
   * Constructs a RefinesInputRefiner.
   *
   * @param object $instance
   *   The surface or alter instance the methods are called on.
   * @param array<string, \Drupal\data_surface\SurfaceBuild\RefinerDefinition[]> $bindings
   *   The methods, keyed by the input key they refine.
   */
  public function __construct(
    protected object $instance,
    protected array $bindings,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    foreach ($this->bindings[$name] ?? [] as $refiner) {
      $definition = $this->invoke($refiner, $definition, $values);
    }
    return $definition;
  }

  /**
   * Calls one method with the definition and the siblings it watches.
   *
   * @param \Drupal\data_surface\SurfaceBuild\RefinerDefinition $refiner
   *   The method.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to refine.
   * @param array $values
   *   Sibling values, keyed by input key; every watched key is present.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   What the method returned.
   *
   * @throws \LogicException
   *   When the method returned something other than a definition.
   */
  public function invoke(RefinerDefinition $refiner, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    // phpcs:ignore Drupal.Files.LineLength.TooLong
    // SKETCH GAP: the sketch types sibling parameters but forms hand refinement raw input; values are passed through reflection, so PHP's non-strict scalar coercion applies ("1" reaches a bool as TRUE).
    $arguments = [$definition];
    foreach ($refiner->watched() as $key) {
      $arguments[] = $values[$key] ?? NULL;
    }
    $refined = (new \ReflectionMethod($this->instance, $refiner->method))->invokeArgs($this->instance, $arguments);
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
