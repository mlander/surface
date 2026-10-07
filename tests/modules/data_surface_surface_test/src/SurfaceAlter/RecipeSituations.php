<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\SurfaceAlter;

use Drupal\data_surface\Surface\Attribute\Situation;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface_surface_test\Surface\RecipeSurface;

/**
 * Ways to ask for a recipe that its owner never wrote.
 */
final class RecipeSituations {

  /**
   * A new recipe that starts from another one's values.
   */
  #[Situation('clone', label: 'Clone a recipe', of: RecipeSurface::class, permission: 'cook in %kitchen')]
  public static function clone(string $kitchen, string $course, string $dish): SurfaceContext {
    return RecipeSurface::add($kitchen)
      ->withOperation('clone')
      ->withStarting(['course' => $course, 'dish' => $dish]);
  }

  /**
   * Forgets to say which situation it is.
   */
  #[Situation('mislabeled', label: 'Mislabeled', of: RecipeSurface::class)]
  public static function mislabeled(string $kitchen): SurfaceContext {
    return RecipeSurface::add($kitchen);
  }

}
