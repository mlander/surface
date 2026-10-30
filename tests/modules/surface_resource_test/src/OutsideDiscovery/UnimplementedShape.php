<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\surface\Resource\Attribute\Shape;

/**
 * Carries #[Shape] without implementing ShapeInterface.
 */
#[Shape('resource_test.broken.unimplemented')]
final class UnimplementedShape {

  /**
   * The definition, with no interface to say so.
   */
  public static function define(): DataDefinition {
    return DataDefinition::create('string');
  }

}
