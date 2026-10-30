<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

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
