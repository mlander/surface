<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Shape\Broken;

use PortableSchema\Builder\Attribute\Document;
use Drupal\surface\Resource\ShapeInterface;
use PortableSchema\Builder\Schema;

/**
 * Declares a default, which belongs to the key: refused when used.
 */
#[Document('resource_test.broken.defaulted')]
final class DefaultedShape implements ShapeInterface {

  /**
   * {@inheritdoc}
   */
  public static function schema(): Schema {
    return Schema::string()->title('Defaulted')->default('x');
  }

}
