<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Broken;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_resource_test\Structure\Seating\SeatingStructure;

/**
 * Wires a port to a key inside the child it is the port of.
 */
#[Structure('resource_test.broken.wires_inside_child')]
final class WiresInsideChildStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('layout', 'string', 'Layout')
      ->addConstraint('Choice', ['choices' => ['theatre', 'banquet', 'standing']]);
    $inputs->attach('seating', SeatingStructure::class, reads: ['layout' => 'seating.aisle']);
  }

}
