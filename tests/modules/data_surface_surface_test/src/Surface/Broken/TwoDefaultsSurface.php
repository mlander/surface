<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface\Broken;

use Drupal\data_surface\Surface\Attribute\Situation;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * Broken: two situations marked the default.
 */
#[Surface('surface_test.broken.two_defaults')]
final class TwoDefaultsSurface implements SurfaceInterface {

  /**
   * One default.
   */
  #[Situation('first', label: 'First', default: TRUE)]
  public static function first(): SurfaceContext {
    return new SurfaceContext('first');
  }

  /**
   * Another.
   */
  #[Situation('second', label: 'Second', default: TRUE)]
  public static function second(): SurfaceContext {
    return new SurfaceContext('second');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('name', 'string', 'Name');
  }

}
