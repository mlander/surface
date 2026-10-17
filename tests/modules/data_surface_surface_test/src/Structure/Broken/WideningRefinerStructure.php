<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Structure\Broken;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\ShapeInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Has a refiner that watches nothing and widens.
 */
#[Structure('surface_test.broken.widening')]
final class WideningRefinerStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('name', 'string', 'Name')->setRequired(TRUE);
  }

  /**
   * Watches nothing, and widens what it was given.
   */
  #[RefinesInput('name')]
  public static function nameIsOptional(DataDefinitionInterface $name): DataDefinitionInterface {
    if ($name instanceof DataDefinition) {
      $name->setRequired(FALSE);
    }
    return $name;
  }

}
