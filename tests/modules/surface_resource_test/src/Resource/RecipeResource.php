<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Resource;

use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\DefinesOutputsInterface;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_resource_test\Access\RecipeAccess;
use Drupal\surface_resource_test\Storage\RecipeStorage;
use PortableSchema\Builder\Schema;

/**
 * A recipe in a kitchen: every part of a resource, in one.
 *
 * Two identity keys, three operations (one returning a context that says
 * another id), storage, an access class, outputs, and three refiners: one
 * watching a sibling, one watching two, and one watching nothing.
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
   * Forgets to say which operation it is.
   */
  #[Operation('mislabeled', label: 'Mislabeled')]
  public static function mislabeled(string $kitchen): OperationContext {
    return self::create($kitchen);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('kitchen', Schema::string()
      ->title('Kitchen')
      ->required());
    $inputs->add('name', Schema::string()
      ->title('Name')
      ->required());
    $inputs->add('course', Schema::string()
      ->title('Course')
      ->default('main')
      ->check('drupal:Choice', ['choices' => array_keys(self::DISHES)]));
    $inputs->add('dish', Schema::string()
      ->title('Dish')
      ->check('drupal:Choice', ['choices' => array_merge(...array_values(self::DISHES))]));
    $inputs->add('servings', Schema::integer()
      ->title('Servings')
      ->default(2)
      ->minimum(1)
      ->maximum(12));
    $inputs->add('vegetarian', Schema::bool()
      ->title('Vegetarian')
      ->default(FALSE));
  }

  /**
   * {@inheritdoc}
   */
  public static function defineOutputs(BlueprintInterface $outputs): void {
    $outputs->add('id', Schema::string()
      ->title('Recipe id'));
  }

  /**
   * The dish is one the course offers. Watches the course by name.
   */
  #[RefinesInput('dish')]
  public static function dishOfCourse(string $course): Schema {
    return Schema::enum(self::DISHES[$course] ?? []);
  }

  /**
   * Fewer servings of dessert, and two fewer again for a vegetarian dish.
   *
   * Watches two siblings, by its parameter names.
   */
  #[RefinesInput('servings')]
  public static function servingsFor(string $course, bool $vegetarian): Schema {
    // Of the key's maximum and this one, the stricter stands: an operation
    // may have lowered it, and its minimum stands.
    return Schema::any()->maximum(($course === 'dessert' ? 8 : 12) - ($vegetarian ? 2 : 0));
  }

  /**
   * A name fits on a card. Watches nothing, so it runs once, at build.
   */
  #[RefinesInput('name')]
  public static function nameFitsOnCard(): Schema {
    return Schema::any()->maxLength(40);
  }

}
