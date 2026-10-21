<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Broken;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_resource_test\Structure\Seating\SeatingStructure;

/**
 * Wires the string layout port to an integer.
 */
#[Structure('resource_test.broken.wires_wrong_type')]
final class WiresWrongTypeStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('size', 'integer', 'Size');
    $inputs->attach('seating', SeatingStructure::class, reads: ['layout' => 'size']);
  }

}
