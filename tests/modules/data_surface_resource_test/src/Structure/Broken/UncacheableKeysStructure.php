<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Broken;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_resource_test\Keys\UncacheableKeys;

/**
 * Asks for keys from a provider that cannot be cached.
 */
#[Structure('resource_test.broken.uncacheable_keys')]
final class UncacheableKeysStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addEach('moments', 'boolean', from: UncacheableKeys::class);
  }

}
