<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface;

use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * A surface whose alters watch keys an alter mounted.
 *
 * Its own alter watches a key it mounted itself, which is allowed; the
 * test module's alter watches that same key, another module's, which is
 * refused.
 *
 * @see \Drupal\data_surface_surface_test\SurfaceAlter\MountWatcherAlter
 * @see \Drupal\data_surface_test\SurfaceAlter\ForeignMountWatcherAlter
 */
#[Surface('surface_test.mount_watcher')]
final class MountWatcherSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('size', 'integer', 'Size');
  }

}
