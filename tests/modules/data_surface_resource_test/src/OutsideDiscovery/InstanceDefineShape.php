<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\OutsideDiscovery;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\Resource\Attribute\Shape;

/**
 * Declares define() on the instance, so it cannot take the interface.
 */
#[Shape('resource_test.broken.instance_define')]
final class InstanceDefineShape {

  /**
   * The definition, on an instance nothing makes.
   */
  public function define(): DataDefinition {
    return DataDefinition::create('string');
  }

}
