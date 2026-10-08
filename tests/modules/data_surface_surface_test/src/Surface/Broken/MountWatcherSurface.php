<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface\Broken;

use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * A surface whose alter watches a key it mounted, which is refused.
 *
 * @see \Drupal\data_surface_surface_test\SurfaceAlter\MountWatcherAlter
 */
#[Surface('surface_test.broken.mount_watcher')]
final class MountWatcherSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('size', 'integer', 'Size');
  }

}
