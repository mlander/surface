<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Keys\ShiftingBenches;
use Drupal\surface_resource_test\Structure\Seating\SeatingStructure;

/**
 * Wires the seating's port at every entry to a map that lists one entry fewer.
 */
#[Structure('resource_test.broken.entry_port_unmatched')]
final class EntryPortUnmatchedStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addEach('layouts', 'string', from: ShiftingBenches::class);
    $inputs->attachEach('seats', SeatingStructure::class, from: ShiftingBenches::class, reads: ['layout' => 'layouts']);
  }

}
