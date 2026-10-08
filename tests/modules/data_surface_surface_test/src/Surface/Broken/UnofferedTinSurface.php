<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface\Broken;

use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\Attribute\SurfaceVariant;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * Fills UnofferedVariantSurface's slot for `tin`.
 */
#[Surface('surface_test.broken.unoffered_variant.tin')]
#[SurfaceVariant(of: UnofferedVariantSurface::class, key: 'settings', value: 'tin')]
final class UnofferedTinSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('size', 'integer', 'Size');
  }

}
