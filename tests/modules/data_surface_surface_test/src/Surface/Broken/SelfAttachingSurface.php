<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface\Broken;

use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * Attaches itself, which would never finish building.
 */
#[Surface('surface_test.broken.self_attaching')]
final class SelfAttachingSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('name', 'string', 'Name');
    $inputs->attach('again', self::class);
  }

}
