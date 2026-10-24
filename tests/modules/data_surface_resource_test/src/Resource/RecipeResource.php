<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Resource;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\DefinesOutputsInterface;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_resource_test\Access\RecipeAccess;
use Drupal\data_surface_resource_test\Storage\RecipeStorage;

/**
 * A recipe in a kitchen: every part of a resource, in one.
 *
 * Two identity keys, two operations, storage, an access class, outputs,
 * and three refiners: one watching a sibling by name, one watching two
 * through `watches:`, and one watching nothing.
 */
#[Resource('resource_test.recipe',
  identity: ['kitchen', 'name'],
  storage: RecipeStorage::class,
  access: RecipeAccess::class,
)]
final class RecipeResource implements ResourceInterface, DefinesOutputsInterface {

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
  #[Operation('create', label: 'Add a recipe', permission: 'cook in %kitchen', creates: TRUE)]
  public static function create(string $kitchen): OperationContext {
    return new OperationContext('create', creates: TRUE, known: ['kitchen' => $kitchen]);
  }

  /**
   * An existing recipe.
   */
  #[Operation('update', label: 'Edit a recipe', permission: 'cook in %kitchen')]
  public static function update(string $kitchen, string $name): OperationContext {
    return self::create($kitchen)
      ->withKnown(['name' => $name])
      ->withId('update', creates: FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
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
  public static function defineOutputs(BlueprintInterface $outputs): void {
    $outputs->add('id', 'string', 'Recipe id');
  }

  /**
   * The dish is one the course offers. Watches the course by name.
   */
  #[RefinesInput('dish')]
  public static function dishOfCourse(DataDefinitionInterface $dish, string $course): DataDefinitionInterface {
    return $dish->addConstraint('Choice', ['choices' => self::DISHES[$course] ?? []]);
  }

  /**
   * Fewer servings of dessert, and two fewer again for a vegetarian dish.
   *
   * Watches two siblings, listed so a renamed parameter is caught.
   */
  #[RefinesInput('servings', watches: ['course', 'vegetarian'])]
  public static function servingsFor(DataDefinitionInterface $servings, string $course, bool $vegetarian): DataDefinitionInterface {
    $max = ($course === 'dessert' ? 8 : 12) - ($vegetarian ? 2 : 0);
    // Only the maximum moves: an operation may have raised the minimum,
    // and replacing the whole constraint would lower it back.
    $range = $servings->getConstraints()['Range'] ?? [];
    $range['max'] = min($range['max'] ?? $max, $max);
    return $servings->addConstraint('Range', $range);
  }

  /**
   * A name fits on a card. Watches nothing, so it runs once, at build.
   */
  #[RefinesInput('name')]
  public static function nameFitsOnCard(DataDefinitionInterface $name): DataDefinitionInterface {
    return $name->addConstraint('Length', ['max' => 40]);
  }

}
