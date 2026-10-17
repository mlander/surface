<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Resource\Pantry;

use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\Attribute\Variant;
use Drupal\data_surface\Resource\ShapeInterface;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_surface_test\Storage\PantryTinStorage;

/**
 * A tin's settings: fills the pantry's open slot, stored apart.
 */
#[Resource('surface_test.pantry.tin', storage: PantryTinStorage::class)]
#[Variant(of: PantryResource::class, key: 'kind_settings', value: 'tin')]
final class TinResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('opener', 'boolean', 'Needs an opener', default: FALSE);
    $inputs->add('volume', 'integer', 'Volume', default: 400)->setRequired(TRUE);
  }

}
