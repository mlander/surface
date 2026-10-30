<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure;

use Drupal\surface_resource_test\Resource\RecipeResource;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * Settings for a herb garnish: one of the recipe's garnish variants.
 */
#[Structure('resource_test.garnish.herb')]
#[Variant(of: RecipeResource::class, at: 'garnish_settings', for: 'herb')]
final class HerbGarnishStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('chopped', 'boolean', 'Chopped', default: TRUE);
  }

}
