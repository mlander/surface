<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * Has a refiner of the shape before answers: handed the key's definition.
 */
#[Structure('resource_test.broken.takes_definition')]
final class TakesDefinitionStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('kind', Schema::string()
      ->title('Kind'));
    $inputs->add('name', Schema::string()
      ->title('Name'));
  }

  /**
   * Takes the definition and returns it.
   */
  #[RefinesInput('name')]
  public static function nameOfKind(DataDefinitionInterface $name, string $kind): DataDefinitionInterface {
    return $name;
  }

}
