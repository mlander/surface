<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken\Kit;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Variants\KitVariants;

/**
 * Mixes in variants chosen by a list.
 */
#[Structure('resource_test.broken.kit_list_by')]
final class KitListByStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addList('kind', 'string', 'Kinds');
    $inputs->mixInVariants('kind', from: KitVariants::class, arguments: ['a', WeightOnlyStructure::class]);
  }

}
