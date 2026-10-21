<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Broken;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * A plain structure MountingMixinAlter mounts a key on.
 *
 * Fine by itself, or attached; mixed in, the key its alter mounts would
 * be mounted on the owner.
 */
#[Structure('resource_test.broken.mounting_mixin')]
final class MountingMixinStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('label', 'string', 'Label');
  }

}
