<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface\Cupboard;

use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * A bin in a cupboard: a child whose identity a context may know.
 */
#[Surface('surface_test.cupboard.bin', identity: ['bin'])]
final class BinSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('bin', 'string', 'Bin')
      ->setRequired(TRUE);
    $inputs->add('size', 'integer', 'Size', default: 5)
      ->addConstraint('Range', ['min' => 1, 'max' => 10]);
  }

}
