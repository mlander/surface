<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Shape;

use Drupal\surface\Resource\Attribute\Shape;
use Drupal\surface\Resource\ShapeInterface;
use PortableSchema\Builder\Schema;

/**
 * A short code of capital letters, the shape a key names as its type.
 */
#[Shape('property_test.code')]
final class Code implements ShapeInterface {

  /**
   * {@inheritdoc}
   */
  public static function schema(): Schema {
    return Schema::string()->title(t('Code'))->pattern('^[A-Z]{2,8}$');
  }

}
