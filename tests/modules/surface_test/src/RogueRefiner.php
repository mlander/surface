<?php

declare(strict_types=1);

namespace Drupal\surface_test;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\SurfaceRefinerInterface;

/**
 * A refiner that breaks the narrowing contract by changing the type.
 */
final class RogueRefiner implements SurfaceRefinerInterface {

  /**
   * {@inheritdoc}
   */
  public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    return DataDefinition::create('integer');
  }

}
