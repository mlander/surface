<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Alter;

use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface\Resource\AltersInputsInterface;
use Drupal\data_surface\Resource\BlueprintAdditionsInterface;
use Drupal\data_surface_resource_test\Shape\Broken\MountedShape;

/**
 * Adds a key to a shape, which has none: refused when the shape is used.
 */
#[Alters(MountedShape::class)]
final class MountingShapeAlter implements AltersInputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->add('note', 'string', 'Note');
  }

}
