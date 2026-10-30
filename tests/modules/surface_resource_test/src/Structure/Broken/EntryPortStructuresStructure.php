<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Keys\WorkshopBenches;
use Drupal\surface_resource_test\Structure\Seating\SeatingStructure;
use Drupal\surface_resource_test\Structure\Workshop\BenchStructure;

/**
 * Wires the seating's port at every entry to a map of structures.
 */
#[Structure('resource_test.broken.entry_port_structures')]
final class EntryPortStructuresStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->attachEach('benches', BenchStructure::class, from: WorkshopBenches::class);
    $inputs->attachEach('seats', SeatingStructure::class, from: WorkshopBenches::class, reads: ['layout' => 'benches']);
  }

}
