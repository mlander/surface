<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Structure\Broken;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\ShapeInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Has two providers of one operation id.
 */
#[Structure('surface_test.broken.clashing_operation')]
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
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('name', 'string', 'Name');
  }

}
