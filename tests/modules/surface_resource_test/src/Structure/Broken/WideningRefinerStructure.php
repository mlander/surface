<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * Has a refiner that watches nothing and widens.
 */
#[Structure('resource_test.broken.widening')]
final class WideningRefinerStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
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
