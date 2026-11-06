<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken\Kit;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Variants\KitVariants;

/**
 * Declares "weight" itself, then mixes in a variant that declares it.
 */
#[Structure('resource_test.broken.kit_owner_clash')]
final class KitOwnerClashStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('kind', 'string', 'Kind');
    $inputs->add('weight', 'integer', 'Weight');
    $inputs->mixInVariants('kind', from: KitVariants::class, arguments: ['a', WeightOnlyStructure::class]);
  }

}
