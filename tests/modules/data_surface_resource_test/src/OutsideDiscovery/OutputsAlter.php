<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\OutsideDiscovery;

use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface\Resource\BlueprintAdditionsInterface;
use Drupal\data_surface_resource_test\Resource\RecipeResource;

/**
 * Broken: an alter that adds outputs, which an alter no longer may.
 */
#[Alters(RecipeResource::class)]
final class OutputsAlter {

  /**
   * Adds an output to every recipe.
   */
  public function alterOutputs(BlueprintAdditionsInterface $outputs): void {
    $outputs->add('plated', 'boolean', 'Plated');
  }

}
