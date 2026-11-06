<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken\Kit;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Structure\Broken\RefinesOutputStructure;
use Drupal\surface_resource_test\Variants\KitVariants;

/**
 * Mixes in a structure that declares outputs as a variant.
 */
#[Structure('resource_test.broken.kit_outputs_variant')]
final class KitOutputsVariantStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('kind', 'string', 'Kind');
    $inputs->mixInVariants('kind', from: KitVariants::class, arguments: ['out', RefinesOutputStructure::class]);
  }

}
