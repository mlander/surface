<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Refinement\Condition;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * Declares a condition comparing an integer key with text.
 */
#[Structure('resource_test.broken.mistyped_condition')]
final class MistypedConditionStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('count', Schema::integer()
      ->title('Count'));
    $inputs->addSchema('name', Schema::string()
      ->title('Name'));
    $inputs->narrow('name')
      ->when(Condition::equals('count', '5'))
      ->require();
  }

}
