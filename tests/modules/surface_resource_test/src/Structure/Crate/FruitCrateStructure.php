<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Crate;

use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * A crate of fruit: how many layers it holds depends on the fruit.
 *
 * The fruit has no default, so nothing refines the layers until one is
 * chosen.
 */
#[Structure('resource_test.crate.fruit')]
#[Variant(of: CrateStructure::class, at: 'contents_settings', for: 'fruit', label: 'Fruit')]
final class FruitCrateStructure implements StructureInterface {

  /**
   * The most layers of each fruit a crate holds.
   */
  public const LAYERS = ['apple' => 4, 'peach' => 2];

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('fruit', Schema::string()
      ->title('Fruit')
      ->check('drupal:Choice', ['choices' => array_keys(self::LAYERS)]));
    $inputs->add('layers', Schema::integer()
      ->title('Layers')
      ->default(1)
      ->minimum(1)
      ->maximum(10));
  }

  /**
   * No more layers than the fruit bears.
   */
  #[RefinesInput('layers')]
  public static function layersOfFruit(string $fruit): Schema {
    return Schema::any()->minimum(1)->maximum(self::LAYERS[$fruit] ?? 1);
  }

}
