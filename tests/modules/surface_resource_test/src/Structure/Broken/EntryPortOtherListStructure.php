<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Keys\GalleryRooms;
use Drupal\surface_resource_test\Keys\WorkshopBenches;
use Drupal\surface_resource_test\Structure\Seating\SeatingStructure;

/**
 * Wires the seating's port at every entry to a map another provider lists.
 */
#[Structure('resource_test.broken.entry_port_other_list')]
final class EntryPortOtherListStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addEach('layouts', 'string', from: GalleryRooms::class);
    $inputs->attachEach('seats', SeatingStructure::class, from: WorkshopBenches::class, reads: ['layout' => 'layouts']);
  }

}
