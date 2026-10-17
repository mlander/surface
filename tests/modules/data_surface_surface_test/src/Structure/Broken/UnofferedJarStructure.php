<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Structure\Broken;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\Attribute\Variant;
use Drupal\data_surface\Resource\ShapeInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Fills UnofferedVariantStructure's slot for `jar`.
 */
#[Structure('surface_test.broken.unoffered_variant.jar')]
#[Variant(of: UnofferedVariantStructure::class, key: 'settings', value: 'jar')]
final class UnofferedJarStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('size', 'integer', 'Size');
  }

}
