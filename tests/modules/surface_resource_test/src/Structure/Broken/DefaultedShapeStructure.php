<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Shape\Broken\DefaultedShape;

/**
 * Uses a shape that declares a default.
 */
#[Structure('resource_test.broken.defaulted_shape')]
final class DefaultedShapeStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('code', DefaultedShape::class, 'Code');
  }

}
