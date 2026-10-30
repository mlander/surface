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
 * Broken: a parent refiner that watches its own collection.
 *
 * Every item of a collection is a child, and a parent does not read its
 * children's values.
 */
#[Structure('resource_test.broken.watches_list')]
final class WatchesListStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('note', 'string', 'Note');
    $inputs->attachList('racks', SpiceRackResource::class);
  }

  /**
   * Reads the racks, which belong to the racks.
   */
  #[RefinesInput('note')]
  public static function noteOfRacks(DataDefinitionInterface $note, array $racks): DataDefinitionInterface {
    return $note;
  }

}
