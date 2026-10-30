<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Alter;

use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\BlueprintAdditionsInterface;
use Drupal\surface\Resource\AltersInputsInterface;
use Drupal\surface_resource_test\Structure\Pantry\ShelfStructure;

/**
 * Alters the shelf alone, wherever it is attached.
 */
#[Alters(ShelfStructure::class)]
final class ShelfAlter implements AltersInputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->add('sturdy', 'boolean', 'Sturdy', default: TRUE);
    $inputs->describe('height', description: 'Measured inside.');
  }

}
