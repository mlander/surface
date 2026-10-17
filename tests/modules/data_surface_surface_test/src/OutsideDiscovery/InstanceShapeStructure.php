<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\OutsideDiscovery;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\ShapeInterface;

/**
 * Carries #[Structure] with an instance defineInputs().
 *
 * PHP refuses a class that implements StructureInterface and declares the
 * method non-static, so this one leaves the interface off to get past
 * the compiler. Discovery refuses it, naming it, which is why it lives
 * outside src/Resource and src/Structure: a refusal at discovery is
 * everyone's, and the test hands it to a registry of its own.
 */
#[Structure('surface_test.broken.instance_shape')]
final class InstanceShapeStructure {

  /**
   * Not static, which a shape has to be.
   *
   * @param \Drupal\data_surface\Resource\ShapeInterface $inputs
   *   The input shape to fill.
   */
  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('name', 'string', 'Name');
  }

}
