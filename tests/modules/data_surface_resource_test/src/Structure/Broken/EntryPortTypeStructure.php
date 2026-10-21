<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Broken;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_resource_test\Keys\WorkshopBenches;
use Drupal\data_surface_resource_test\Structure\Seating\SeatingStructure;

/**
 * Wires the seating's string port at every entry to a map of booleans.
 */
#[Structure('resource_test.broken.entry_port_type')]
final class EntryPortTypeStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addEach('layouts', 'boolean', from: WorkshopBenches::class);
    $inputs->attachEach('seats', SeatingStructure::class, from: WorkshopBenches::class, reads: ['layout' => 'layouts']);
  }

}
