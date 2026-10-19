<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Broken;

use Drupal\data_surface\Refinement\Condition;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Declares a rule whose maximum is above the key's own.
 */
#[Structure('resource_test.broken.widening_rule')]
final class WideningRuleStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('mode', 'string', 'Mode');
    $inputs->add('count', 'integer', 'Count')->addConstraint('Range', ['min' => 1, 'max' => 10]);
    $inputs->narrow('count')
      ->when(Condition::equals('mode', 'many'))
      ->constrain('Range', ['max' => 20]);
  }

}
