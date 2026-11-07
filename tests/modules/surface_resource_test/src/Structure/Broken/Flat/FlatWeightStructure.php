<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken\Flat;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * The flat owner clash's variant for "a": a weight, which its owner has.
 */
#[Structure('resource_test.broken.flat.weight')]
#[Variant(of: FlatOwnerClashStructure::class, at: 'gear', for: 'a')]
final class FlatWeightStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('weight', 'integer', 'Weight');
  }

}
