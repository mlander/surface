<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Broken;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_resource_test\Structure\Seating\SeatingStructure;

/**
 * Lists seatings, whose required port nothing wires.
 */
#[Structure('resource_test.broken.ports_in_collection')]
final class PortsInCollectionStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->attachList('seatings', SeatingStructure::class);
  }

}
