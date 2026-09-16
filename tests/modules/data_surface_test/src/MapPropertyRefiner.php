<?php

declare(strict_types=1);

namespace Drupal\data_surface_test;

use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurfaceRefinerInterface;

/**
 * A refiner that tightens one property inside a map.
 *
 * The case a shallow clone gets wrong: core's MapDataDefinition has no
 * __clone of its own, so a cloned map shares its property definitions
 * with the original and this refiner would tighten the sealed surface
 * rather than the copy it was handed.
 */
final class MapPropertyRefiner implements DataSurfaceRefinerInterface {

  /**
   * The property this refiner tightens.
   */
  public const PROPERTY = 'note';

  /**
   * {@inheritdoc}
   */
  public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    if ($definition instanceof ComplexDataDefinitionInterface) {
      $property = $definition->getPropertyDefinitions()[self::PROPERTY] ?? NULL;
      $property?->addConstraint('Length', ['max' => 3]);
    }
    return $definition;
  }

}
