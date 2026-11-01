<?php

declare(strict_types=1);

namespace Drupal\surface_test;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\SurfaceRefinerInterface;

/**
 * A refiner that gives an `any` definition a concrete type.
 *
 * What the narrowing contract allows the variants' placeholder alone: it
 * advertises nothing of its own, so any type is narrower than it. Handed
 * any other `any`, the engine refuses what it returns.
 */
final class AnyToIntegerRefiner implements SurfaceRefinerInterface {

  /**
   * {@inheritdoc}
   */
  public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    return DataDefinition::create('integer');
  }

}
