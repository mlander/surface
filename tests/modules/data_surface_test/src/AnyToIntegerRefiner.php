<?php

declare(strict_types=1);

namespace Drupal\data_surface_test;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurfaceRefinerInterface;

/**
 * A refiner that gives an 'any' definition a concrete type.
 *
 * The escape hatch the narrowing contract leaves open: 'any' advertises
 * nothing, so any type is narrower than it.
 */
final class AnyToIntegerRefiner implements DataSurfaceRefinerInterface {

  /**
   * {@inheritdoc}
   */
  public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    return DataDefinition::create('integer')->setRequired(FALSE);
  }

}
