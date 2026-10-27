<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\OutsideDiscovery;

use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface\Resource\AltersInputsInterface;
use Drupal\data_surface\Resource\BlueprintAdditionsInterface;

/**
 * Broken: names the SKU shape by its id, where it has a class.
 */
#[Alters('resource_test.sku')]
final class SkuByIdAlter implements AltersInputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
  }

}
