<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Structure\Broken;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\ShapeInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Has a #[RefinesInput] method that is an instance method.
 */
#[Structure('surface_test.broken.instance_refiner')]
final class InstanceRefinerStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('kind', 'string', 'Kind');
    $inputs->add('name', 'string', 'Name');
  }

  /**
   * Not static, which a structure's refiner has to be.
   */
  #[RefinesInput('name')]
  public function nameOfKind(DataDefinitionInterface $name, string $kind): DataDefinitionInterface {
    return $name->addConstraint('Length', ['max' => 10]);
  }

}
