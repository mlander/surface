<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Alter;

use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface\Resource\ShapeAdditionsInterface;
use Drupal\data_surface\Resource\AltersInputsInterface;
use Drupal\data_surface_surface_test\Structure\Pantry\ShelfStructure;

/**
 * Alters the shelf alone, wherever it is attached.
 */
#[Alters(ShelfStructure::class)]
final class ShelfAlter implements AltersInputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(ShapeAdditionsInterface $inputs): void {
    $inputs->add('sturdy', 'boolean', 'Sturdy', default: TRUE);
    $inputs->describe('height', description: 'Measured inside.');
  }

}
