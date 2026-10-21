<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Broken;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * The owner PortCycleAlter adapts into a cycle through a port.
 */
#[Structure('resource_test.broken.port_cycle')]
final class PortCycleStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('layout', 'string', 'Layout')
      ->addConstraint('Choice', ['choices' => ['theatre', 'banquet', 'standing']]);
    $inputs->add('size', 'integer', 'Size', default: 50)
      ->addConstraint('Range', ['min' => 1, 'max' => 1000]);
  }

}
