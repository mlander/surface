<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Refinement\Condition;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Structure\Mixin\TimestampsStructure;

/**
 * Rules a member of the timestamps out, which is the timestamps' to say.
 */
#[Structure('resource_test.broken.mixin_rules_out')]
final class MixinRulesOutStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('kind', 'string', 'Kind')
      ->addConstraint('Choice', ['choices' => ['draft', 'final']]);
    $inputs->mixIn(TimestampsStructure::class);
    $inputs->narrow('changed')
      ->when(Condition::equals('kind', 'draft'))
      ->doesNotApply();
  }

}
