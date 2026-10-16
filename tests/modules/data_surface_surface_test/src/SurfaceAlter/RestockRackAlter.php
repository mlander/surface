<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\SurfaceAlter;

use Drupal\data_surface\Surface\Attribute\AltersSurface;
use Drupal\data_surface\Surface\ShapeAdditionsInterface;
use Drupal\data_surface\Surface\SurfaceAlterInterface;
use Drupal\data_surface_surface_test\Surface\Larder\SpiceRackSurface;

/**
 * Adds a supplier to a spice rack, only where it is restocked.
 *
 * The rack is a child; its restock situation is invoked only when its
 * parent names it, so this applies at that key and at no other.
 */
#[AltersSurface(SpiceRackSurface::class, situations: ['restock'])]
final class RestockRackAlter implements SurfaceAlterInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(ShapeAdditionsInterface $inputs): void {
    $inputs->add('supplier', 'string', 'Supplier');
  }

}
