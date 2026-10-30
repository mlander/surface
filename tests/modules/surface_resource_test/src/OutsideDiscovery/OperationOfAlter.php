<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery;

use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface_resource_test\Resource\RecipeResource;

/**
 * Broken: an alter adding an operation to a resource it does not own.
 */
#[Alters(RecipeResource::class)]
final class OperationOfAlter {

  /**
   * Names the resource with of:, which the attribute no longer takes.
   */
  #[Operation('clone', label: 'Clone a recipe', of: RecipeResource::class)]
  public static function clone(string $kitchen): OperationContext {
    return RecipeResource::create($kitchen);
  }

}
