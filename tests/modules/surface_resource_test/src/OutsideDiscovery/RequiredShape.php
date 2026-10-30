<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\surface\Resource\Attribute\Shape;
use Drupal\surface\Resource\ShapeInterface;

/**
 * Requires itself, which is each key's to say.
 */
#[Shape('resource_test.broken.required')]
final class RequiredShape implements ShapeInterface {

  /**
   * {@inheritdoc}
   */
  public static function define(): DataDefinition {
    return DataDefinition::create('string')->setRequired(TRUE);
  }

}
