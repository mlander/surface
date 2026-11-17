<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Structure\Seating\SeatingStructure;
use PortableSchema\Builder\Schema;

/**
 * Wires the string layout port to an integer.
 */
#[Structure('resource_test.broken.wires_wrong_type')]
final class WiresWrongTypeStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('size', Schema::integer()
      ->title('Size'));
    $inputs->attach('seating', SeatingStructure::class, reads: ['layout' => 'size']);
  }

}
