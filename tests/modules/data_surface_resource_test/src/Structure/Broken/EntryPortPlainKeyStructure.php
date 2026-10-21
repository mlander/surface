<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Broken;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_resource_test\Keys\WorkshopBenches;
use Drupal\data_surface_resource_test\Structure\Seating\SeatingStructure;

/**
 * Wires the seating's port at every entry to a plain key.
 */
#[Structure('resource_test.broken.entry_port_plain_key')]
final class EntryPortPlainKeyStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('layout', 'string', 'Layout');
    $inputs->attachEach('seats', SeatingStructure::class, from: WorkshopBenches::class, reads: ['layout' => 'layout']);
  }

}
