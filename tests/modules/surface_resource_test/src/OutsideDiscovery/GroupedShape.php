<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery;

use PortableSchema\Builder\Attribute\Document;
use Drupal\surface\Resource\ShapeInterface;
use PortableSchema\Builder\Schema;

/**
 * Declares an object, a group of keys, which is a structure.
 */
#[Document('resource_test.broken.grouped')]
final class GroupedShape implements ShapeInterface {

  /**
   * {@inheritdoc}
   */
  public static function schema(): Schema {
    return Schema::object(['code' => Schema::string()]);
  }

}
