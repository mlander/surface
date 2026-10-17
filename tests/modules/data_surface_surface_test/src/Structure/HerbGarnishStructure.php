<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Structure;

use Drupal\data_surface_surface_test\Resource\RecipeResource;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\Attribute\Variant;
use Drupal\data_surface\Resource\ShapeInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Settings for a herb garnish: the variant that fills the recipe's slot.
 */
#[Structure('surface_test.garnish.herb')]
#[Variant(of: RecipeResource::class, key: 'garnish_settings', value: 'herb')]
final class HerbGarnishStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('chopped', 'boolean', 'Chopped', default: TRUE);
  }

}
