<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Alter;

use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\AltersInputsInterface;
use Drupal\surface\Resource\BlueprintAdditionsInterface;
use Drupal\surface_resource_test\Shape\Broken\MountedShape;

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
