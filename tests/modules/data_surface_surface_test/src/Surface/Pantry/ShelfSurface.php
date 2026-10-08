<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface\Pantry;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * A shelf: a child with a refiner that runs in its own frame.
 */
#[Surface('surface_test.pantry.shelf')]
final class ShelfSurface implements SurfaceInterface {

  /**
   * The tallest shelf, by unit.
   */
  public const MAX = ['cm' => 200, 'in' => 80];

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('unit', 'string', 'Unit', default: 'cm')
      ->addConstraint('Choice', ['choices' => array_keys(self::MAX)]);
    $inputs->add('height', 'integer', 'Height', default: 30)
      ->addConstraint('Range', ['min' => 1]);
  }

  /**
   * A shelf is no taller than the unit allows. Watches its own sibling.
   */
  #[RefinesInput('height')]
  public static function heightInUnit(DataDefinitionInterface $height, string $unit): DataDefinitionInterface {
    $range = $height->getConstraints()['Range'] ?? [];
    $range['max'] = self::MAX[$unit] ?? 1;
    return $height->addConstraint('Range', $range);
  }

}
