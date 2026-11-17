<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery;

use PortableSchema\Builder\Attribute\Document;
use Drupal\surface\Resource\ShapeInterface;
use PortableSchema\Builder\Schema;

/**
 * Says what its members are and no type, which reads as no one scalar type.
 */
#[Document('resource_test.broken.members')]
final class MembersShape implements ShapeInterface {

  /**
   * {@inheritdoc}
   */
  public static function schema(): Schema {
    return Schema::any()->additionalProperties(Schema::string());
  }

}
