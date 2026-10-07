<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\SurfaceAlter;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Surface\AltersOutputsInterface;
use Drupal\data_surface\Surface\Attribute\AltersSurface;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\ShapeAdditionsInterface;
use Drupal\data_surface\Surface\SurfaceAlterInterface;
use Drupal\data_surface_surface_test\Surface\RecipeSurface;

/**
 * Adds an input and an output to every recipe, and tightens the dish.
 */
#[AltersSurface(RecipeSurface::class)]
final class RecipeAlter implements SurfaceAlterInterface, AltersOutputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(ShapeAdditionsInterface $inputs): void {
    $inputs->add('garnish', 'string', 'Garnish', default: 'parsley');
  }

  /**
   * {@inheritdoc}
   */
  public function alterOutputs(ShapeAdditionsInterface $outputs): void {
    $outputs->add('plated', 'boolean', 'Plated');
  }

  /**
   * No fish in a vegetarian recipe. Runs after the owner's dish refiner.
   */
  #[RefinesInput('dish')]
  public function noFishForVegetarians(DataDefinitionInterface $dish, bool $vegetarian): DataDefinitionInterface {
    if (!$vegetarian) {
      return $dish;
    }
    $choices = $dish->getConstraints()['Choice']['choices'] ?? [];
    return $dish->addConstraint('Choice', ['choices' => array_values(array_diff($choices, ['fish']))]);
  }

}
