<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * Attaches a child that watches one of this structure's keys.
 */
#[Structure('resource_test.broken.nosy_parent')]
final class NosyParentStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('pantry', 'string', 'Pantry');
    $inputs->attach('nosy', NosyChildStructure::class);
  }

}
