<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\SurfaceAlter;

use Drupal\data_surface\Surface\Attribute\AltersSurface;
use Drupal\data_surface\Surface\ShapeAdditionsInterface;
use Drupal\data_surface\Surface\SurfaceAlterInterface;
use Drupal\data_surface_surface_test\Surface\Pantry\ShelfSurface;

/**
 * Alters the shelf alone, wherever it is attached.
 */
#[AltersSurface(ShelfSurface::class)]
final class ShelfAlter implements SurfaceAlterInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(ShapeAdditionsInterface $inputs): void {
    $inputs->add('sturdy', 'boolean', 'Sturdy', default: TRUE);
    $inputs->describe('height', description: 'Measured inside.');
  }

}
