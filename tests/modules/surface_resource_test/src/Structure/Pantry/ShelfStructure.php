<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Pantry;

use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * A shelf: a child with a refiner that runs in its own frame.
 */
#[Structure('resource_test.pantry.shelf')]
final class ShelfStructure implements StructureInterface {

  /**
   * The tallest shelf, by unit.
   */
  public const MAX = ['cm' => 200, 'in' => 80];

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('unit', Schema::string()
      ->title('Unit')
      ->default('cm')
      ->check('drupal:Choice', ['choices' => array_keys(self::MAX)]));
    $inputs->addSchema('height', Schema::integer()
      ->title('Height')
      ->default(30)
      ->minimum(1));
  }

  /**
   * A shelf is no taller than the unit allows. Watches its own sibling.
   */
  #[RefinesInput('height')]
  public static function heightInUnit(string $unit): Schema {
    return Schema::any()->maximum(self::MAX[$unit] ?? 1);
  }

}
