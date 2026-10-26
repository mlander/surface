<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\OutsideDiscovery;

use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface\Resource\AltersInputsInterface;
use Drupal\data_surface\Resource\BlueprintAdditionsInterface;
use Drupal\data_surface_resource_test\Shape\Sku;

/**
 * Alters a shape in one operation only, which a shape has none of.
 */
#[Alters(Sku::class, operations: ['update'])]
final class OperationsShapeAlter implements AltersInputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
  }

}
