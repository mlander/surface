<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken\Flat;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * The flat mixin clash's variant for "a": a weight, which its mixin brings.
 */
#[Structure('resource_test.broken.flat.mixed_weight')]
#[Variant(of: FlatMixinClashStructure::class, at: 'gear', for: 'a')]
final class FlatMixedWeightStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('weight', 'integer', 'Weight');
  }

}
