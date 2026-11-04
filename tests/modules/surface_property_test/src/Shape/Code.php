<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Shape;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\surface\Resource\Attribute\Shape;
use Drupal\surface\Resource\ShapeInterface;

/**
 * A short code of capital letters, the shape a key names as its type.
 */
#[Shape('property_test.code')]
final class Code implements ShapeInterface {

  /**
   * {@inheritdoc}
   */
  public static function define(): DataDefinition {
    return DataDefinition::create('string')
      ->setLabel(t('Code'))
      ->addConstraint('Regex', ['pattern' => '/^[A-Z]{2,8}$/D']);
  }

}
