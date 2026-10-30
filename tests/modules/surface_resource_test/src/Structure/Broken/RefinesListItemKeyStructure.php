<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Resource\Larder\SpiceRackResource;

/**
 * Broken: a parent refiner that names a key inside its collection's items.
 */
#[Structure('resource_test.broken.refines_list_item_key')]
final class RefinesListItemKeyStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('size', 'string', 'Size');
    $inputs->attachList('racks', SpiceRackResource::class);
  }

  /**
   * Caps every rack's spices, which only the rack may do.
   */
  #[RefinesInput('racks.spices')]
  public static function spicesBySize(DataDefinitionInterface $spices, string $size): DataDefinitionInterface {
    return $spices;
  }

}
