<?php

declare(strict_types=1);

namespace Drupal\data_surface_test;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurfaceRefinerInterface;

/**
 * Narrows a labeled choice to the subset a basic scope offers.
 *
 * Narrowing is replacing the constraint with a smaller one: the labels
 * travel with the values they belong to, so a refined definition stays
 * self-contained and nothing has to be kept in step with it.
 */
final class ModeSubsetRefiner implements DataSurfaceRefinerInterface {

  /**
   * The choices a basic scope keeps.
   */
  public const BASIC = [0, 1];

  /**
   * {@inheritdoc}
   */
  public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    if ($name === 'mode' && ($values['scope'] ?? NULL) === 'basic') {
      $declared = $definition->getConstraints()['LabeledChoice'] ?? [];
      $definition->addConstraint('LabeledChoice', [
        'choices' => array_values(array_intersect($declared['choices'] ?? [], self::BASIC)),
        'labels' => array_intersect_key($declared['labels'] ?? [], array_flip(self::BASIC)),
      ]);
    }
    return $definition;
  }

}
