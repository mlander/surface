<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Broken;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_resource_test\Keys\WorkshopBenches;
use Drupal\data_surface_resource_test\Structure\Seating\SeatingStructure;

/**
 * Holds the seating structure, which requires a port, at every bench.
 */
#[Structure('resource_test.broken.unwired_entry')]
final class UnwiredEntryStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('layout', 'string', 'Layout');
    $inputs->attachEach('seats', SeatingStructure::class, from: WorkshopBenches::class);
  }

}
