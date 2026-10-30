<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery;

use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface_resource_test\Resource\RecipeResource;

/**
 * Broken: an alter carrying an operation, which stands on no resource.
 */
#[Alters(RecipeResource::class)]
final class OperationAlter {

  /**
   * An operation on a class that is no resource or structure.
   */
  #[Operation('taste', label: 'Taste a recipe')]
  public static function taste(string $kitchen): OperationContext {
    return RecipeResource::create($kitchen);
  }

}
