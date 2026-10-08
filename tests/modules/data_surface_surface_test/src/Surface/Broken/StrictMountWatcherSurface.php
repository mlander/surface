<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface\Broken;

use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * A surface whose alter watches its own mounted key without taking NULL.
 *
 * @see \Drupal\data_surface_surface_test\SurfaceAlter\StrictMountWatcherAlter
 */
#[Surface('surface_test.broken.strict_mount_watcher')]
final class StrictMountWatcherSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('size', 'integer', 'Size');
  }

}
