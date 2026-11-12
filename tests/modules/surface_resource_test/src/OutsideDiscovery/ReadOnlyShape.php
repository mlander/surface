<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery;

use Drupal\surface\Resource\Attribute\Shape;
use Drupal\surface\Resource\ShapeInterface;
use PortableSchema\Builder\Schema;

/**
 * Says readOnly, which belongs to the key that uses a shape.
 */
#[Shape('resource_test.broken.read_only')]
final class ReadOnlyShape implements ShapeInterface {

  /**
   * {@inheritdoc}
   */
  public static function schema(): Schema {
    return Schema::string()->title('Fixed')->readOnly(TRUE);
  }

}
