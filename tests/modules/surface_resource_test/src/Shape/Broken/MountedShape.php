<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Shape\Broken;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\surface\Resource\Attribute\Shape;
use Drupal\surface\Resource\ShapeInterface;

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
