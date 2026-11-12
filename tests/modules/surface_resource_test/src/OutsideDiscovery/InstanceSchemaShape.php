<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery;

use Drupal\surface\Resource\Attribute\Shape;
use PortableSchema\Builder\Schema;

/**
 * Declares schema() on the instance, so it cannot take the interface.
 */
#[Shape('resource_test.broken.instance_schema')]
final class InstanceSchemaShape {

  /**
   * The schema, on an instance nothing makes.
   */
  public function schema(): Schema {
    return Schema::string();
  }

}
