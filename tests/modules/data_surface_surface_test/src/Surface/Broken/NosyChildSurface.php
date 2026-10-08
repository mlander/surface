<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface\Broken;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * Watches a key only its parent declares, which the wall refuses.
 */
#[Surface('surface_test.broken.nosy_child')]
final class NosyChildSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('height', 'integer', 'Height');
  }

  /**
   * Reads the parent's pantry.
   */
  #[RefinesInput('height')]
  public static function heightInPantry(DataDefinitionInterface $height, string $pantry): DataDefinitionInterface {
    return $height;
  }

}
