<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface\Broken;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;
use Drupal\data_surface_surface_test\Surface\Pantry\ShelfSurface;

/**
 * Refines its own subsurface key, which the wall refuses.
 */
#[Surface('surface_test.broken.refines_subsurface')]
final class RefinesSubsurfaceSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->attach('shelf', ShelfSurface::class);
  }

  /**
   * Reaches into the child.
   */
  #[RefinesInput('shelf')]
  public function intoTheShelf(DataDefinitionInterface $shelf): DataDefinitionInterface {
    return $shelf;
  }

}
