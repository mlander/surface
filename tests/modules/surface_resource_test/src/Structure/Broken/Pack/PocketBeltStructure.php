<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken\Pack;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Structure\Pack\PocketStructure;

/**
 * Holds a pocket at "belt", where another variant holds a hip belt.
 */
#[Structure('resource_test.broken.pack.pocket_belt')]
final class PocketBeltStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->attach('belt', PocketStructure::class);
  }

}
