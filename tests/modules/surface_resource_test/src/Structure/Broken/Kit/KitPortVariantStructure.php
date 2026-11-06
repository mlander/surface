<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken\Kit;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Structure\Mixin\OwnershipStructure;
use Drupal\surface_resource_test\Variants\KitVariants;

/**
 * Mixes in a structure that reads a port as a variant.
 */
#[Structure('resource_test.broken.kit_port_variant')]
final class KitPortVariantStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('kind', 'string', 'Kind');
    $inputs->mixInVariants('kind', from: KitVariants::class, arguments: ['owned', OwnershipStructure::class]);
  }

}
