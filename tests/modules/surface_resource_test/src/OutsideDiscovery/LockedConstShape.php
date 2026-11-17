<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery;

use PortableSchema\Builder\Attribute\Document;
use Drupal\surface\Resource\ShapeInterface;
use PortableSchema\Builder\Schema;

/**
 * Says readOnly beside const, which reads as a default.
 */
#[Document('resource_test.broken.locked_const')]
final class LockedConstShape implements ShapeInterface {

  /**
   * {@inheritdoc}
   */
  public static function schema(): Schema {
    return Schema::const('a')->readOnly(TRUE);
  }

}
