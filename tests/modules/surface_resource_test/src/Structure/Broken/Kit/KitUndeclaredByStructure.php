<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken\Kit;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Variants\KitVariants;

/**
 * Mixes in variants chosen by a key it never declares.
 */
#[Structure('resource_test.broken.kit_undeclared_by')]
final class KitUndeclaredByStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->mixInVariants('kind', from: KitVariants::class, arguments: ['a', WeightOnlyStructure::class]);
  }

}
