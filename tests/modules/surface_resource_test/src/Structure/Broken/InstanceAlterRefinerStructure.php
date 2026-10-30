<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * A structure whose alter refines with an instance method not runtime.
 *
 * @see \Drupal\surface_resource_test\Alter\InstanceRefinerAlter
 */
#[Structure('resource_test.broken.instance_alter_refiner')]
final class InstanceAlterRefinerStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('kind', 'string', 'Kind');
    $inputs->add('name', 'string', 'Name');
  }

}
