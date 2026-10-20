<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Crate;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\Attribute\Variant;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * A crate of fruit: how many layers it holds depends on the fruit.
 *
 * The fruit has no default, so nothing refines the layers until one is
 * chosen.
 */
#[Structure('resource_test.crate.fruit')]
#[Variant(of: CrateStructure::class, at: 'contents_settings', for: 'fruit')]
final class FruitCrateStructure implements StructureInterface {

  /**
   * The most layers of each fruit a crate holds.
   */
  public const LAYERS = ['apple' => 4, 'peach' => 2];

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('fruit', 'string', 'Fruit')
      ->addConstraint('Choice', ['choices' => array_keys(self::LAYERS)]);
    $inputs->add('layers', 'integer', 'Layers', default: 1)
      ->addConstraint('Range', ['min' => 1, 'max' => 10]);
  }

  /**
   * No more layers than the fruit bears.
   */
  #[RefinesInput('layers')]
  public static function layersOfFruit(DataDefinitionInterface $layers, string $fruit): DataDefinitionInterface {
    return $layers->addConstraint('Range', ['min' => 1, 'max' => self::LAYERS[$fruit] ?? 1]);
  }

}
