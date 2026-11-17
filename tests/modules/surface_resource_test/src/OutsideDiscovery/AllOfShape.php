<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery;

use PortableSchema\Builder\Attribute\Document;
use Drupal\surface\Resource\ShapeInterface;
use PortableSchema\Builder\Schema;

/**
 * Says its type through allOf, which reads as no one scalar type.
 */
#[Document('resource_test.broken.all_of')]
final class AllOfShape implements ShapeInterface {

  /**
   * {@inheritdoc}
   */
  public static function schema(): Schema {
    return Schema::string()->allOf([Schema::string()->maxLength(3)]);
  }

}
