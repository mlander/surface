<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface\Crate;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\Attribute\SurfaceVariant;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * A crate of fruit: how many layers it holds depends on the fruit.
 *
 * The fruit has no default, so nothing refines the layers until one is
 * chosen.
 */
#[Surface('surface_test.crate.fruit')]
#[SurfaceVariant(of: CrateSurface::class, key: 'contents_settings', value: 'fruit')]
final class FruitCrateSurface implements SurfaceInterface {

  /**
   * The most layers of each fruit a crate holds.
   */
  public const LAYERS = ['apple' => 4, 'peach' => 2];

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
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
