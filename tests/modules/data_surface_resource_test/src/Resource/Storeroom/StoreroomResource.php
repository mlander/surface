<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Resource\Storeroom;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_resource_test\Storage\StoreroomStorage;
use Drupal\data_surface_resource_test\Structure\Storeroom\AisleStructure;

/**
 * A storeroom: a collection whose items hold a collection of their own.
 *
 * Each aisle has shelves, each shelf a unit its height is held to: a list
 * inside an item, and a refiner inside an item of that list.
 */
#[Resource('resource_test.storeroom', storage: StoreroomStorage::class)]
final class StoreroomResource implements ResourceInterface {

  /**
   * Edits the storeroom, which always exists.
   */
  #[Operation('update', label: 'Edit the storeroom', permission: 'keep a larder')]
  public static function update(): OperationContext {
    return new OperationContext('update');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('name', 'string', 'Name', default: 'Storeroom');
    $inputs->attachList('aisles', AisleStructure::class, max: 3)
      ->setLabel('Aisles');
  }

}
