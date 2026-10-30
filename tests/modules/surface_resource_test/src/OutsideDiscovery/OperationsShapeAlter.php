<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery;

use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\AltersInputsInterface;
use Drupal\surface\Resource\BlueprintAdditionsInterface;
use Drupal\surface_resource_test\Shape\Sku;

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
