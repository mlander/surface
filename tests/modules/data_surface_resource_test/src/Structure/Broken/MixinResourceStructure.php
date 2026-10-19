<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Broken;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_resource_test\Resource\Journal\LedgerResource;

/**
 * Mixes in a resource, which has storage of its own.
 */
#[Structure('resource_test.broken.mixin_resource')]
final class MixinResourceStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->with(LedgerResource::class);
  }

}
