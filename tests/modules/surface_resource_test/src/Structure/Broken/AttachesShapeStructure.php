<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Shape\Sku;

/**
 * Attaches a collection of a shape, as if it were a child.
 */
#[Structure('resource_test.broken.attaches_shape')]
final class AttachesShapeStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->attachList('codes', Sku::class);
  }

}
