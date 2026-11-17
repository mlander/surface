<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery;

use PortableSchema\Builder\Attribute\Document;
use PortableSchema\Builder\Schema;

/**
 * Carries #[Document] without implementing ShapeInterface.
 */
#[Document('resource_test.broken.unimplemented')]
final class UnimplementedShape {

  /**
   * The schema, with no interface to say so.
   */
  public static function schema(): Schema {
    return Schema::string();
  }

}
