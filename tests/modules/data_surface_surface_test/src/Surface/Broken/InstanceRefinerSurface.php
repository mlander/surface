<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface\Broken;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * Has a #[RefinesInput] method that is an instance method.
 */
#[Surface('surface_test.broken.instance_refiner')]
final class InstanceRefinerSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('kind', 'string', 'Kind');
    $inputs->add('name', 'string', 'Name');
  }

  /**
   * Not static, which a surface's refiner has to be.
   */
  #[RefinesInput('name')]
  public function nameOfKind(DataDefinitionInterface $name, string $kind): DataDefinitionInterface {
    return $name->addConstraint('Length', ['max' => 10]);
  }

}
