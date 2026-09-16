<?php

declare(strict_types=1);

namespace Drupal\data_surface_test;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurfaceRefinerInterface;

/**
 * The provider-side refiner: variant choices depend on casing.
 */
final class CasingVariantRefiner implements DataSurfaceRefinerInterface {

  /**
   * Variant choices per casing.
   */
  public const VARIANTS = [
    'uppercase' => ['bold', 'strong'],
    'lowercase' => ['quiet', 'muted'],
  ];

  /**
   * {@inheritdoc}
   */
  public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    if ($name !== 'variant' || !isset(self::VARIANTS[$values['casing']])) {
      return $definition;
    }
    $declared = $definition->getConstraints()['Choice']['choices'] ?? NULL;
    // Narrowed against what it was handed when the key advertises a
    // list, and a fresh list when it advertises none: either way the
    // values are the owner's own.
    $definition->addConstraint('Choice', [
      'choices' => $declared === NULL
        ? self::VARIANTS[$values['casing']]
        : array_values(array_intersect($declared, self::VARIANTS[$values['casing']])),
    ]);
    return $definition;
  }

}
