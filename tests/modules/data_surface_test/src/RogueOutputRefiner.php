<?php

declare(strict_types=1);

namespace Drupal\data_surface_test;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurfaceOutputRefinerInterface;

/**
 * An output refiner that breaks the narrowing contract by widening.
 *
 * It hands back a value the definition it was given did not allow,
 * which on the output side means advertising less than is emitted — the
 * one thing a declared output exists to rule out.
 */
final class RogueOutputRefiner implements DataSurfaceOutputRefinerInterface {

  /**
   * The value this refiner tries to add to whatever it is handed.
   */
  public const CHOICE = 'epic';

  /**
   * {@inheritdoc}
   */
  public function refineOutputDefinition(string $name, DataDefinitionInterface $definition, array $input_values): DataDefinitionInterface {
    $declared = $definition->getConstraints()['Choice']['choices'] ?? [];
    $definition->addConstraint('Choice', [
      'choices' => array_merge($declared, [self::CHOICE]),
    ]);
    return $definition;
  }

}
