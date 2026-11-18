<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Structure\Seating\SeatingStructure;
use PortableSchema\Builder\Schema;

/**
 * Wires a port to a key it does not declare.
 */
#[Structure('resource_test.broken.wires_missing_key')]
final class WiresMissingKeyStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('layout', Schema::enum(['theatre', 'banquet', 'standing'])
      ->title('Layout'));
    $inputs->attach('seating', SeatingStructure::class, reads: ['layout' => 'shape']);
  }

}
