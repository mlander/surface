<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Alter;

use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface\Resource\BlueprintAdditionsInterface;
use Drupal\data_surface\Resource\AltersInputsInterface;
use Drupal\data_surface_resource_test\Resource\Larder\SpiceRackResource;

/**
 * Adds a supplier to a spice rack, only where it is restocked.
 *
 * The rack is a child; its restock operation is invoked only when its
 * parent names it, so this applies at that key and at no other.
 */
#[Alters(SpiceRackResource::class, operations: ['restock'])]
final class RestockRackAlter implements AltersInputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->add('supplier', 'string', 'Supplier');
  }

}
