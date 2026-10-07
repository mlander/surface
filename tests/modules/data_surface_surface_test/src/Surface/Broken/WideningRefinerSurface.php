<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface\Broken;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * Has a refiner that watches nothing and widens.
 */
#[Surface('surface_test.broken.widening')]
final class WideningRefinerSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('name', 'string', 'Name')->setRequired(TRUE);
  }

  /**
   * Watches nothing, and widens what it was given.
   */
  #[RefinesInput('name')]
  public function nameIsOptional(DataDefinitionInterface $name): DataDefinitionInterface {
    if ($name instanceof DataDefinition) {
      $name->setRequired(FALSE);
    }
    return $name;
  }

}
