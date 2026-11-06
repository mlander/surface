<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken\Pack;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * A capacity of ten at least, beside one of one at least.
 */
#[Structure('resource_test.broken.pack.large_capacity')]
final class LargeCapacityStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('capacity', 'integer', 'Capacity')
      ->addConstraint('Range', ['min' => 10]);
  }

}
