<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Resource\Larder\SpiceRackResource;

/**
 * Broken: a parent refiner that reaches inside its collection's items.
 *
 * The list is the parent's to narrow, and each item the child's, so a
 * refiner of the list key that narrows the item it is handed is refused
 * when the structure is refined.
 */
#[Structure('resource_test.broken.narrows_list_items')]
final class NarrowsListItemsStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('size', 'string', 'Size');
    $inputs->attachList('racks', SpiceRackResource::class);
  }

  /**
   * Narrows every rack, which is the rack's own business.
   */
  #[RefinesInput('racks')]
  public static function racksOfSize(DataDefinitionInterface $racks, string $size): DataDefinitionInterface {
    assert($racks instanceof ListDataDefinitionInterface);
    $racks->getItemDefinition()->addConstraint('NotNull', []);
    return $racks;
  }

}
