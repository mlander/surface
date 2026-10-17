<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Structure\Broken;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\ShapeInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * A structure whose alter watches its own mounted key without taking NULL.
 *
 * @see \Drupal\data_surface_surface_test\Alter\StrictMountWatcherAlter
 */
#[Structure('surface_test.broken.strict_mount_watcher')]
final class StrictMountWatcherStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('size', 'integer', 'Size');
  }

}
