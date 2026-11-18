<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * Has a #[RefinesInput] method that is an instance method.
 */
#[Structure('resource_test.broken.instance_refiner')]
final class InstanceRefinerStructure implements StructureInterface {

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
   * Not static, which a structure's refiner has to be.
   */
  #[RefinesInput('name')]
  public function nameOfKind(string $kind): Schema {
    return Schema::any()->maxLength(10);
  }

}
