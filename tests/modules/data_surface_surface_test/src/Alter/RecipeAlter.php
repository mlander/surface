<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Alter;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Resource\AltersOutputsInterface;
use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\BlueprintAdditionsInterface;
use Drupal\data_surface\Resource\AltersInputsInterface;
use Drupal\data_surface_surface_test\Resource\RecipeResource;

/**
 * Adds an input and an output to every recipe, and tightens the dish.
 */
#[Alters(RecipeResource::class)]
final class RecipeAlter implements AltersInputsInterface, AltersOutputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->add('garnish', 'string', 'Garnish', default: 'parsley');
  }

  /**
   * {@inheritdoc}
   */
  public function alterOutputs(BlueprintAdditionsInterface $outputs): void {
    $outputs->add('plated', 'boolean', 'Plated');
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
