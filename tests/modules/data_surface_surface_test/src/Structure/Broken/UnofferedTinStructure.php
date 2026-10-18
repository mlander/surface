<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Structure\Broken;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\Attribute\Variant;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Fills UnofferedVariantStructure's slot for `tin`.
 */
#[Structure('surface_test.broken.unoffered_variant.tin')]
#[Variant(of: UnofferedVariantStructure::class, key: 'settings', value: 'tin')]
final class UnofferedTinStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('size', 'integer', 'Size');
  }

}
