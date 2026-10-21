<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Broken;

use Drupal\data_surface\Refinement\Condition;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Declares a condition comparing an integer key with text.
 */
#[Structure('resource_test.broken.mistyped_condition')]
final class MistypedConditionStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('count', 'integer', 'Count');
    $inputs->add('name', 'string', 'Name');
    $inputs->narrow('name')
      ->when(Condition::equals('count', '5'))
      ->require();
  }

}
