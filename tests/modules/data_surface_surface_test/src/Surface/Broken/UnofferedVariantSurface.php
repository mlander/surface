<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface\Broken;

use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * Is filled by a variant its deciding key does not allow.
 *
 * UnofferedTinSurface fills the slot for `tin`, which `kind` refuses.
 */
#[Surface('surface_test.broken.unoffered_variant')]
final class UnofferedVariantSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('kind', 'string', 'Kind')->addConstraint('Choice', ['choices' => ['jar']]);
    $inputs->attachBy('settings', by: 'kind');
  }

}
