<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * Has a refiner that watches nothing and would widen.
 *
 * Its one answer is known when the structure is built, so the build
 * refuses it. Before refiners answered with schemas, it turned the key's
 * required flag off, which no answer says.
 */
#[Structure('resource_test.broken.widening')]
final class WideningRefinerStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('name', Schema::string()
      ->title('Name')
      ->maxLength(10)
      ->required());
  }

  /**
   * Watches nothing, and answers a longer maximum than the key's.
   */
  #[RefinesInput('name')]
  public static function nameMayBeLonger(): Schema {
    return Schema::any()->maxLength(40);
  }

}
