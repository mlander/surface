<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery;

use Drupal\surface\Resource\Attribute\Shape;
use PortableSchema\Builder\Schema;

/**
 * Carries #[Shape] without implementing ShapeInterface.
 */
#[Shape('resource_test.broken.unimplemented')]
final class UnimplementedShape {

  /**
   * The schema, with no interface to say so.
   */
  public static function schema(): Schema {
    return Schema::string();
  }

}
