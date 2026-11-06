<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken\Pack;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Structure\Pack\BeltStructure;

/**
 * Holds a hip belt at "belt".
 */
#[Structure('resource_test.broken.pack.belted')]
final class BeltedStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->attach('belt', BeltStructure::class);
  }

}
