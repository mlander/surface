<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface\Broken;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;
use Drupal\data_surface_surface_test\Surface\Larder\SpiceRackSurface;

/**
 * Broken: a parent refiner that reaches inside its collection's items.
 *
 * The list is the parent's to narrow, and each item the child's, so a
 * refiner of the list key that narrows the item it is handed is refused
 * when the surface is refined.
 */
#[Surface('surface_test.broken.narrows_list_items')]
final class NarrowsListItemsSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('size', 'string', 'Size');
    $inputs->attachList('racks', SpiceRackSurface::class);
  }

  /**
   * Narrows every rack, which is the rack's own business.
   */
  #[RefinesInput('racks')]
  public static function racksOfSize(DataDefinitionInterface $racks, string $size): DataDefinitionInterface {
    assert($racks instanceof ListDataDefinitionInterface);
    $racks->getItemDefinition()->addConstraint('NotNull', []);
    return $racks;
  }

}
