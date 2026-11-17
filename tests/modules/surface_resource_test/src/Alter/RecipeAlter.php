<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Alter;

use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\BlueprintAdditionsInterface;
use Drupal\surface\Resource\AltersInputsInterface;
use Drupal\surface_resource_test\Resource\RecipeResource;
use Drupal\surface\Refinement\ChoiceSet;
use PortableSchema\Builder\Schema;

/**
 * Adds an input to every recipe, and tightens the dish.
 */
#[Alters(RecipeResource::class)]
final class RecipeAlter implements AltersInputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->add('garnish', 'string', 'Garnish', default: 'parsley');
  }

  /**
   * No fish in a vegetarian recipe. Runs after the owner's dish refiner.
   */
  #[RefinesInput('dish')]
  public static function noFishForVegetarians(Schema $dish, bool $vegetarian): Schema {
    if (!$vegetarian) {
      return Schema::any();
    }
    return Schema::enum(array_values(array_diff(ChoiceSet::of($dish)->values ?? [], ['fish'])));
  }

}
