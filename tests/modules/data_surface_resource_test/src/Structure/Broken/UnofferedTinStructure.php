<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Broken;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\Attribute\Variant;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * The variant of UnofferedVariantStructure's settings for `tin`.
 */
#[Structure('resource_test.broken.unoffered_variant.tin')]
#[Variant(of: UnofferedVariantStructure::class, at: 'settings', for: 'tin')]
final class UnofferedTinStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('size', 'integer', 'Size');
  }

}
