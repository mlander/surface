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
 * Reads its child's value from a refiner, which the wall refuses.
 */
#[Surface('surface_test.broken.watches_subsurface')]
final class WatchesSubsurfaceSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('note', 'string', 'Note');
    $inputs->attach('shelf', ShelfSurface::class);
  }

  /**
   * Reads the shelf.
   */
  #[RefinesInput('note')]
  public static function noteForShelf(DataDefinitionInterface $note, array $shelf): DataDefinitionInterface {
    return $note;
  }

}
