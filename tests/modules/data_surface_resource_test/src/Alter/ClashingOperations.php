<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Alter;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface_resource_test\Structure\Broken\ClashingOperationStructure;

/**
 * A second provider of the open operation, which the build refuses.
 */
final class ClashingOperations {

  /**
   * The other provider of the open operation.
   */
  #[Operation('open', label: 'Open again', of: ClashingOperationStructure::class)]
  public static function open(): OperationContext {
    return new OperationContext('open');
  }

}
