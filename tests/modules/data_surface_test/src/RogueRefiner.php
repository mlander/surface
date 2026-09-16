<?php

declare(strict_types=1);

namespace Drupal\data_surface_test;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurfaceRefinerInterface;

/**
 * A refiner that breaks the narrowing contract by changing the type.
 */
final class RogueRefiner implements DataSurfaceRefinerInterface {

  /**
   * {@inheritdoc}
   */
  public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    return DataDefinition::create('integer');
  }

}
