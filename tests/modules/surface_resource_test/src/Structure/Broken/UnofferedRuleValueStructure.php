<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Refinement\Condition;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * Declares a rule keeping a value the key's list does not offer.
 */
#[Structure('resource_test.broken.unoffered_rule_value')]
final class UnofferedRuleValueStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('flag', Schema::bool()
      ->title('Flag'));
    $inputs->add('kind', Schema::options(['a' => 'A', 'b' => 'B'])
      ->title('Kind'));
    $inputs->narrow('kind')
      ->when(Condition::equals('flag', TRUE))
      ->constrain(Schema::enum(['a', 'z']));
  }

}
