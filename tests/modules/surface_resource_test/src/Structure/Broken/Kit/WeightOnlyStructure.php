<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken\Kit;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * A weight alone, which the refusals list as a variant and mix in.
 */
#[Structure('resource_test.broken.kit.weight_only')]
final class WeightOnlyStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('weight', 'integer', 'Weight');
  }

}
