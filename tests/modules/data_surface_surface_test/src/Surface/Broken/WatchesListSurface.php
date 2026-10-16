<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface\Broken;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;
use Drupal\data_surface_surface_test\Surface\Larder\SpiceRackSurface;

/**
 * Broken: a parent refiner that watches its own collection.
 *
 * Every item of a collection is a child, and a parent does not read its
 * children's values.
 */
#[Surface('surface_test.broken.watches_list')]
final class WatchesListSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('note', 'string', 'Note');
    $inputs->attachList('racks', SpiceRackSurface::class);
  }

  /**
   * Reads the racks, which belong to the racks.
   */
  #[RefinesInput('note')]
  public static function noteOfRacks(DataDefinitionInterface $note, array $racks): DataDefinitionInterface {
    return $note;
  }

}
