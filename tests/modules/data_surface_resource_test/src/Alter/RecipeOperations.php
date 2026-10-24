<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Alter;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface_resource_test\Resource\RecipeResource;

/**
 * Ways to ask for a recipe that its owner never wrote.
 */
final class RecipeOperations {

  /**
   * A new recipe that starts from another one's values.
   */
  #[Operation('clone', label: 'Clone a recipe', of: RecipeResource::class, permission: 'cook in %kitchen', creates: TRUE)]
  public static function clone(string $kitchen, string $course, string $dish): OperationContext {
    return RecipeResource::create($kitchen)
      ->withId('clone')
      ->withDefaults(['course' => $course, 'dish' => $dish]);
  }

  /**
   * Forgets to say which operation it is.
   */
  #[Operation('mislabeled', label: 'Mislabeled', of: RecipeResource::class)]
  public static function mislabeled(string $kitchen): OperationContext {
    return RecipeResource::create($kitchen);
  }

}
