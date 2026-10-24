<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\OutsideDiscovery;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Broken: an operation that says default:, which the attribute no longer takes.
 */
#[Structure('resource_test.broken.default_operation')]
final class DefaultOperationStructure implements StructureInterface {

  /**
   * Marked the default.
   */
  #[Operation('first', label: 'First', default: TRUE)]
  public static function first(): OperationContext {
    return new OperationContext('first');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('name', 'string', 'Name');
  }

}
