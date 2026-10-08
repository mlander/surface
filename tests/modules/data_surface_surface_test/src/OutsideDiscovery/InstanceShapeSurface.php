<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\OutsideDiscovery;

use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;

/**
 * Carries #[Surface] with an instance defineInputs().
 *
 * PHP refuses a class that implements SurfaceInterface and declares the
 * method non-static, so this one leaves the interface off to get past
 * the compiler. Discovery refuses it, naming it, which is why it lives
 * outside src/Surface: a refusal at discovery is every surface's, and
 * the test hands it to a registry of its own.
 */
#[Surface('surface_test.broken.instance_shape')]
final class InstanceShapeSurface {

  /**
   * Not static, which a surface's shape has to be.
   *
   * @param \Drupal\data_surface\Surface\ShapeInterface $inputs
   *   The input shape to fill.
   */
  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('name', 'string', 'Name');
  }

}
