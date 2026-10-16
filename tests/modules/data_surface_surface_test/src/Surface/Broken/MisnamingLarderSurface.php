<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface\Broken;

use Drupal\data_surface\Surface\Attribute\Situation;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceInterface;
use Drupal\data_surface_surface_test\Surface\Larder\SpiceRackSurface;

/**
 * Broken: names a situation its child does not declare.
 */
#[Surface('surface_test.broken.misnaming_larder')]
final class MisnamingLarderSurface implements SurfaceInterface {

  /**
   * Names the rack's "dust" situation, which there is none of.
   */
  #[Situation('clean', label: 'Clean')]
  public static function clean(): SurfaceContext {
    return (new SurfaceContext('clean'))->withChild('rack', situation: 'dust');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->attach('rack', SpiceRackSurface::class);
  }

}
