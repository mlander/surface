<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\SurfaceAlter;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Surface\Attribute\AltersSurface;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\ShapeAdditionsInterface;
use Drupal\data_surface\Surface\SurfaceAlterInterface;
use Drupal\data_surface_surface_test\Surface\Broken\InstanceAlterRefinerSurface;

/**
 * Refines with an instance method that does not declare itself runtime.
 *
 * An instance method can read the services its alter was built with, so
 * it may only be a runtime refiner; this one is refused when the surface
 * is built.
 */
#[AltersSurface(InstanceAlterRefinerSurface::class)]
final class InstanceRefinerAlter implements SurfaceAlterInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(ShapeAdditionsInterface $inputs): void {
  }

  /**
   * An instance method, declared static by default.
   */
  #[RefinesInput('name')]
  public function nameOfKind(DataDefinitionInterface $name, string $kind): DataDefinitionInterface {
    return $name->addConstraint('Length', ['max' => 10]);
  }

}
