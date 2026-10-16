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
 * Broken: a parent refiner that names a key inside its collection's items.
 */
#[Surface('surface_test.broken.refines_list_item_key')]
final class RefinesListItemKeySurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('size', 'string', 'Size');
    $inputs->attachList('racks', SpiceRackSurface::class);
  }

  /**
   * Caps every rack's spices, which only the rack may do.
   */
  #[RefinesInput('racks.spices')]
  public static function spicesBySize(DataDefinitionInterface $spices, string $size): DataDefinitionInterface {
    return $spices;
  }

}
