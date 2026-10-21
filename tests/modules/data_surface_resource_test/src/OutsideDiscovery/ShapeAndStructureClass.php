<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\OutsideDiscovery;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\Resource\Attribute\Shape;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\ShapeInterface;

/**
 * Carries both #[Shape] and #[Structure], which discovery refuses.
 */
#[Shape('resource_test.broken.shape_and_structure')]
#[Structure('resource_test.broken.shape_and_structure')]
final class ShapeAndStructureClass implements ShapeInterface {

  /**
   * {@inheritdoc}
   */
  public static function define(): DataDefinition {
    return DataDefinition::create('string');
  }

}
