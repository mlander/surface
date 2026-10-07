<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\SurfaceAlter;

use Drupal\data_surface\Surface\Attribute\AltersSurface;
use Drupal\data_surface\Surface\ShapeAdditionsInterface;
use Drupal\data_surface\Surface\SurfaceAlterInterface;
use Drupal\data_surface_surface_test\Surface\RecipeSurface;

/**
 * Adds a revision note, only where a recipe is edited.
 */
#[AltersSurface(RecipeSurface::class, situations: ['edit'])]
final class EditOnlyRecipeAlter implements SurfaceAlterInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(ShapeAdditionsInterface $inputs): void {
    $inputs->add('revision_note', 'string', 'Revision note');
  }

}
