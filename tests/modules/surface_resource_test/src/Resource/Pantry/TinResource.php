<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Resource\Pantry;

use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_resource_test\Storage\PantryTinStorage;
use PortableSchema\Builder\Schema;

/**
 * A tin's settings: one of the pantry's variants, stored apart.
 */
#[Resource('resource_test.pantry.tin', storage: PantryTinStorage::class)]
#[Variant(of: PantryResource::class, at: 'kind_settings', for: 'tin')]
final class TinResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('opener', Schema::bool()
      ->title('Needs an opener')
      ->default(FALSE));
    $inputs->add('volume', Schema::integer()
      ->title('Volume')
      ->default(400)
      ->required());
  }

}
