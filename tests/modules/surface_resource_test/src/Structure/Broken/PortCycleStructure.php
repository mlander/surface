<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * The owner PortCycleAlter adapts into a cycle through a port.
 */
#[Structure('resource_test.broken.port_cycle')]
final class PortCycleStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('layout', Schema::enum(['theatre', 'banquet', 'standing'])
      ->title('Layout'));
    $inputs->add('size', Schema::integer()
      ->title('Size')
      ->default(50)
      ->minimum(1)
      ->maximum(1000));
  }

}
