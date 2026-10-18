<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Structure\Broken;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * A structure whose alter refines with an instance method not runtime.
 *
 * @see \Drupal\data_surface_surface_test\Alter\InstanceRefinerAlter
 */
#[Structure('surface_test.broken.instance_alter_refiner')]
final class InstanceAlterRefinerStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('kind', 'string', 'Kind');
    $inputs->add('name', 'string', 'Name');
  }

}
