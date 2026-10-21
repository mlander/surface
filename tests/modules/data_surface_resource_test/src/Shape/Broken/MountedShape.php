<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Shape\Broken;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\Resource\Attribute\Shape;
use Drupal\data_surface\Resource\ShapeInterface;

/**
 * A shape MountingShapeAlter tries to mount a key on: refused when used.
 */
#[Shape('resource_test.broken.mounted')]
final class MountedShape implements ShapeInterface {

  /**
   * {@inheritdoc}
   */
  public static function define(): DataDefinition {
    return DataDefinition::create('string')->setLabel('Mounted');
  }

}
