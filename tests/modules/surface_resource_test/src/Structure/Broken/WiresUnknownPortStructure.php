<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Structure\Seating\SeatingStructure;

/**
 * Wires a port the seating structure does not read.
 */
#[Structure('resource_test.broken.wires_unknown_port')]
final class WiresUnknownPortStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('layout', 'string', 'Layout')
      ->addConstraint('Choice', ['choices' => ['theatre', 'banquet', 'standing']]);
    $inputs->attach('seating', SeatingStructure::class, reads: ['layout' => 'layout', 'seats' => 'layout']);
  }

}
