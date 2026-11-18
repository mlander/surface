<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Refinement\Condition;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * Declares a rule whose maximum is above the key's own.
 */
#[Structure('resource_test.broken.widening_rule')]
final class WideningRuleStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('mode', Schema::string()
      ->title('Mode'));
    $inputs->add('count', Schema::integer()
      ->title('Count')
      ->minimum(1)
      ->maximum(10));
    $inputs->narrow('count')
      ->when(Condition::equals('mode', 'many'))
      ->constrain(Schema::any()->maximum(20));
  }

}
