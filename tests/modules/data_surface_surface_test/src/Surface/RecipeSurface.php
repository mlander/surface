<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\Attribute\Situation;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\HasOutputsInterface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceInterface;
use Drupal\data_surface_surface_test\Access\RecipeAccess;
use Drupal\data_surface_surface_test\Target\RecipeTarget;

/**
 * A recipe in a kitchen: every part of the new spelling, in one surface.
 *
 * Two identity keys, two situations, a target, an access class, outputs,
 * and three refiners: one watching a sibling by name, one watching two
 * through `watches:`, and one watching nothing.
 */
#[Surface('surface_test.recipe',
  identity: ['kitchen', 'name'],
  target: RecipeTarget::class,
  access: RecipeAccess::class,
)]
final class RecipeSurface implements SurfaceInterface, HasOutputsInterface {

  /**
   * The dishes each course offers.
   */
  public const DISHES = [
    'starter' => ['soup', 'salad'],
    'main' => ['fish', 'risotto'],
    'dessert' => ['cake'],
  ];

  /**
   * A new recipe in a kitchen.
   */
  #[Situation('add', label: 'Add a recipe', permission: 'cook in %kitchen')]
  public static function add(string $kitchen): SurfaceContext {
    return new SurfaceContext('add', creates: TRUE, known: ['kitchen' => $kitchen]);
  }

  /**
   * An existing recipe.
   */
  #[Situation('edit', label: 'Edit a recipe', permission: 'cook in %kitchen')]
  public static function edit(string $kitchen, string $name): SurfaceContext {
    return self::add($kitchen)
      ->withKnown(['name' => $name])
      ->withOperation('edit', creates: FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('kitchen', 'string', 'Kitchen')->setRequired(TRUE);
    $inputs->add('name', 'string', 'Name')->setRequired(TRUE);
    $inputs->add('course', 'string', 'Course', default: 'main')
      ->addConstraint('Choice', ['choices' => array_keys(self::DISHES)]);
    $inputs->add('dish', 'string', 'Dish')
      ->addConstraint('Choice', ['choices' => array_merge(...array_values(self::DISHES))]);
    $inputs->add('servings', 'integer', 'Servings', default: 2)
      ->addConstraint('Range', ['min' => 1, 'max' => 12]);
    $inputs->add('vegetarian', 'boolean', 'Vegetarian', default: FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function defineOutputs(ShapeInterface $outputs): void {
    $outputs->add('id', 'string', 'Recipe id');
  }

  /**
   * The dish is one the course offers. Watches the course by name.
   */
  #[RefinesInput('dish')]
  public function dishOfCourse(DataDefinitionInterface $dish, string $course): DataDefinitionInterface {
    return $dish->addConstraint('Choice', ['choices' => self::DISHES[$course] ?? []]);
  }

  /**
   * Fewer servings of dessert, and two fewer again for a vegetarian dish.
   *
   * Watches two siblings, listed so a renamed parameter is caught.
   */
  #[RefinesInput('servings', watches: ['course', 'vegetarian'])]
  public function servingsFor(DataDefinitionInterface $servings, string $course, bool $vegetarian): DataDefinitionInterface {
    $max = ($course === 'dessert' ? 8 : 12) - ($vegetarian ? 2 : 0);
    // Only the maximum moves: a situation may have raised the minimum,
    // and replacing the whole constraint would lower it back.
    $range = $servings->getConstraints()['Range'] ?? [];
    $range['max'] = min($range['max'] ?? $max, $max);
    return $servings->addConstraint('Range', $range);
  }

  /**
   * A name fits on a card. Watches nothing, so it runs once, at build.
   */
  #[RefinesInput('name')]
  public function nameFitsOnCard(DataDefinitionInterface $name): DataDefinitionInterface {
    return $name->addConstraint('Length', ['max' => 40]);
  }

}
