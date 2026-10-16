<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface\Cupboard;

use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;
use Drupal\data_surface_surface_test\Surface\Pantry\ShelfSurface;

/**
 * A cupboard: the same child class at two keys, twice over.
 *
 * Two shelves, a child with a refiner of its own and an alter, and two
 * bins, a child with an identity key, which a context may lock in one of
 * them and not the other. Built by hand in the tests, so it declares no
 * situation and no target.
 */
#[Surface('surface_test.cupboard')]
final class CupboardSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->attach('upper', ShelfSurface::class)
      ->setLabel('Upper shelf');
    $inputs->attach('lower', ShelfSurface::class)
      ->setLabel('Lower shelf');
    $inputs->attach('left', BinSurface::class)
      ->setLabel('Left bin');
    $inputs->attach('right', BinSurface::class)
      ->setLabel('Right bin');
  }

}
