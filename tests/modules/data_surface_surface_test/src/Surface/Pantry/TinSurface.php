<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface\Pantry;

use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\Attribute\SurfaceVariant;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;
use Drupal\data_surface_surface_test\Target\PantryTinTarget;

/**
 * A tin's settings: fills the pantry's open slot, stored apart.
 */
#[Surface('surface_test.pantry.tin', target: PantryTinTarget::class)]
#[SurfaceVariant(of: PantrySurface::class, key: 'kind_settings', value: 'tin')]
final class TinSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('opener', 'boolean', 'Needs an opener', default: FALSE);
    $inputs->add('volume', 'integer', 'Volume', default: 400)->setRequired(TRUE);
  }

}
