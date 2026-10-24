<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Broken;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Has two providers of one operation id: the class and a method.
 */
#[Structure('resource_test.broken.clashing_operation')]
#[Operation('open', label: 'Open again')]
final class ClashingOperationStructure implements StructureInterface {

  /**
   * One provider of the open operation.
   */
  #[Operation('open', label: 'Open')]
  public static function open(): OperationContext {
    return new OperationContext('open');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('name', 'string', 'Name');
  }

}
