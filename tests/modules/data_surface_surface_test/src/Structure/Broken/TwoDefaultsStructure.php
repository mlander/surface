<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Structure\Broken;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\ShapeInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Broken: two operations marked the default.
 */
#[Structure('surface_test.broken.two_defaults')]
final class TwoDefaultsStructure implements StructureInterface {

  /**
   * One default.
   */
  #[Operation('first', label: 'First', default: TRUE)]
  public static function first(): OperationContext {
    return new OperationContext('first');
  }

  /**
   * Another.
   */
  #[Operation('second', label: 'Second', default: TRUE)]
  public static function second(): OperationContext {
    return new OperationContext('second');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('name', 'string', 'Name');
  }

}
