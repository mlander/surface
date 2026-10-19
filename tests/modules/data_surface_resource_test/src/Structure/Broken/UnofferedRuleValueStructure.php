<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Broken;

use Drupal\data_surface\Refinement\Condition;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Declares a rule keeping a value the key's list does not offer.
 */
#[Structure('resource_test.broken.unoffered_rule_value')]
final class UnofferedRuleValueStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('flag', 'boolean', 'Flag');
    $inputs->add('kind', 'string', 'Kind')->addConstraint('LabeledChoice', ['choices' => ['a' => 'A', 'b' => 'B']]);
    $inputs->narrow('kind')
      ->when(Condition::equals('flag', TRUE))
      ->constrain('Choice', ['choices' => ['a', 'z']]);
  }

}
