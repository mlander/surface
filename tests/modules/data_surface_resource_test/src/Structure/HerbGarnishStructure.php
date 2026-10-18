<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure;

use Drupal\data_surface_resource_test\Resource\RecipeResource;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\Attribute\Variant;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Settings for a herb garnish: the variant that fills the recipe's slot.
 */
#[Structure('resource_test.garnish.herb')]
#[Variant(of: RecipeResource::class, key: 'garnish_settings', value: 'herb')]
final class HerbGarnishStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('chopped', 'boolean', 'Chopped', default: TRUE);
  }

}
