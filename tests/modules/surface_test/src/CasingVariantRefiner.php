<?php

declare(strict_types=1);

namespace Drupal\surface_test;

use Drupal\surface\Contract\PortableKeywords;
use Drupal\surface\Refinement\ChoiceSet;
use PortableSchema\Builder\Schema;
use Drupal\surface\SurfaceRefinerInterface;

/**
 * The declaring class's refiner in engine tests: choices depend on casing.
 */
final class CasingVariantRefiner implements SurfaceRefinerInterface {

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
  public function refine(string $name, Schema $key, array $values): array {
    if ($name !== 'variant' || !isset(self::VARIANTS[$values['casing']])) {
      return [];
    }
    $declared = ChoiceSet::ofSchema($key, 'Choice');
    // Narrowed against what it was handed when the key advertises a
    // list, and a fresh list when it advertises none: either way the
    // values are the declaring class's own.
    if ($declared === NULL) {
      return [Schema::any()->with(PortableKeywords::CHECK, ['drupal:Choice' => ['choices' => self::VARIANTS[$values['casing']]]])];
    }
    return [Schema::enum(array_values(array_intersect($declared->values, self::VARIANTS[$values['casing']])))];
  }

}
