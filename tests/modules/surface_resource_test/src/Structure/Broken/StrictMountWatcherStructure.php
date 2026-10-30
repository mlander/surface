<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * A structure whose alter watches its own mounted key without taking NULL.
 *
 * @see \Drupal\surface_resource_test\Alter\StrictMountWatcherAlter
 */
#[Structure('resource_test.broken.strict_mount_watcher')]
final class StrictMountWatcherStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('size', 'integer', 'Size');
  }

}
