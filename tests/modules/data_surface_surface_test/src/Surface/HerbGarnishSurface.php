<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface;

use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\Attribute\SurfaceVariant;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * Settings for a herb garnish: a variant, collected for step 2.
 */
#[Surface('surface_test.garnish.herb')]
#[SurfaceVariant(of: RecipeSurface::class, key: 'garnish_settings', value: 'herb')]
final class HerbGarnishSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('chopped', 'boolean', 'Chopped', default: TRUE);
  }

}
