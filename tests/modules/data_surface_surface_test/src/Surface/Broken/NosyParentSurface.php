<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface\Broken;

use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * Attaches a child that watches one of this surface's keys.
 */
#[Surface('surface_test.broken.nosy_parent')]
final class NosyParentSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('pantry', 'string', 'Pantry');
    $inputs->attach('nosy', NosyChildSurface::class);
  }

}
