<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken\Kit;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * A #[Variant] at "kind", which KitBothStructure both nests and mixes in.
 */
#[Structure('resource_test.broken.kit.both_variant')]
#[Variant(of: KitBothStructure::class, at: 'kind', for: 'a')]
final class KitBothVariantStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('weight', 'integer', 'Weight');
  }

}
