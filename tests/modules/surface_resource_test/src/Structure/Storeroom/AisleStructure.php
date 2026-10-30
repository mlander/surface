<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Storeroom;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Structure\Pantry\ShelfStructure;

/**
 * An aisle of the storeroom, with a list of shelves of its own.
 */
#[Structure('resource_test.storeroom.aisle')]
final class AisleStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('label', 'string', 'Label', default: 'Aisle');
    $inputs->attachList('shelves', ShelfStructure::class, max: 2)
      ->setLabel('Shelves');
  }

}
