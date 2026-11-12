<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Shape\Broken;

use Drupal\surface\Resource\Attribute\Shape;
use Drupal\surface\Resource\ShapeInterface;
use PortableSchema\Builder\Schema;

/**
 * A shape MountingShapeAlter tries to mount a key on: refused when used.
 */
#[Shape('resource_test.broken.mounted')]
final class MountedShape implements ShapeInterface {

  /**
   * {@inheritdoc}
   */
  public static function schema(): Schema {
    return Schema::string()->title('Mounted');
  }

}
