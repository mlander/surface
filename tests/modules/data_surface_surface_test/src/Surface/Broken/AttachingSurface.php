<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface\Broken;

use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * An open slot nothing fills: the deciding key can choose nothing.
 */
#[Surface('surface_test.broken.attaching')]
final class AttachingSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('type', 'string', 'Type');
    $inputs->attachBy('settings', by: 'type');
  }

}
