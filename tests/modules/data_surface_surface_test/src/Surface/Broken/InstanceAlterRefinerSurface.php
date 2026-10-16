<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface\Broken;

use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * A surface whose alter refines with an instance method not runtime.
 *
 * @see \Drupal\data_surface_surface_test\SurfaceAlter\InstanceRefinerAlter
 */
#[Surface('surface_test.broken.instance_alter_refiner')]
final class InstanceAlterRefinerSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('kind', 'string', 'Kind');
    $inputs->add('name', 'string', 'Name');
  }

}
