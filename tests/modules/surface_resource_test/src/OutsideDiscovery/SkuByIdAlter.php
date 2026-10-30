<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery;

use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\AltersInputsInterface;
use Drupal\surface\Resource\BlueprintAdditionsInterface;

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
