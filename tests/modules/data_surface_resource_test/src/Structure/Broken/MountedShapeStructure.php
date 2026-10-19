<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Broken;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_resource_test\Shape\Broken\MountedShape;

/**
 * Uses the shape an alter tries to mount a key on.
 */
#[Structure('resource_test.broken.mounted_shape')]
final class MountedShapeStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('code', MountedShape::class, 'Code');
  }

}
