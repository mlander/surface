<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface\Broken;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\HasOutputsInterface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * Has a refiner naming an output key.
 */
#[Surface('surface_test.broken.refines_output')]
final class RefinesOutputSurface implements SurfaceInterface, HasOutputsInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('count', 'integer', 'Count');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineOutputs(ShapeInterface $outputs): void {
    $outputs->add('total', 'integer', 'Total');
  }

  /**
   * Tries to refine an output.
   */
  #[RefinesInput('total')]
  public static function totalOfCount(DataDefinitionInterface $total, int $count): DataDefinitionInterface {
    return $total;
  }

}
