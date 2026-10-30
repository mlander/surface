<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Alter;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\BlueprintAdditionsInterface;
use Drupal\surface\Resource\AltersInputsInterface;
use Drupal\surface_resource_test\Resource\RecipeResource;

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
  public static function noFishForVegetarians(DataDefinitionInterface $dish, bool $vegetarian): DataDefinitionInterface {
    if (!$vegetarian) {
      return $dish;
    }
    $choices = $dish->getConstraints()['Choice']['choices'] ?? [];
    return $dish->addConstraint('Choice', ['choices' => array_values(array_diff($choices, ['fish']))]);
  }

}
