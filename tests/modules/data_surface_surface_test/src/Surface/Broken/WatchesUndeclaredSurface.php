<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface\Broken;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * Has a refiner watching a key its shape never declares.
 */
#[Surface('surface_test.broken.watches_undeclared')]
final class WatchesUndeclaredSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('name', 'string', 'Name');
  }

  /**
   * Watches a sibling that is not there.
   */
  #[RefinesInput('name')]
  public function nameOfGhost(DataDefinitionInterface $name, string $ghost): DataDefinitionInterface {
    return $name;
  }

}
