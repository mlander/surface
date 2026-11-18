<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Structure\Seating\SeatingStructure;
use PortableSchema\Builder\Schema;

/**
 * Attaches the seating structure without wiring the port it requires.
 */
#[Structure('resource_test.broken.unwired_port')]
final class UnwiredPortStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('layout', Schema::enum(['theatre', 'banquet', 'standing'])
      ->title('Layout'));
    $inputs->attach('seating', SeatingStructure::class);
  }

}
