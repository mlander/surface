<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Alter;

use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\BlueprintAdditionsInterface;
use Drupal\surface\Resource\AltersInputsInterface;
use Drupal\surface_resource_test\Resource\RecipeResource;

/**
 * Adds a revision note, only where a recipe is edited.
 */
#[Alters(RecipeResource::class, operations: ['update'])]
final class EditOnlyRecipeAlter implements AltersInputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->add('revision_note', 'string', 'Revision note');
  }

}
