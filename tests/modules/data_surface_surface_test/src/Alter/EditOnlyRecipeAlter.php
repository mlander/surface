<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Alter;

use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface\Resource\ShapeAdditionsInterface;
use Drupal\data_surface\Resource\AltersInputsInterface;
use Drupal\data_surface_surface_test\Resource\RecipeResource;

/**
 * Adds a revision note, only where a recipe is edited.
 */
#[Alters(RecipeResource::class, operations: ['edit'])]
final class EditOnlyRecipeAlter implements AltersInputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(ShapeAdditionsInterface $inputs): void {
    $inputs->add('revision_note', 'string', 'Revision note');
  }

}
