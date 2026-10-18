<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Structure\Broken;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Attaches a child that watches one of this structure's keys.
 */
#[Structure('surface_test.broken.nosy_parent')]
final class NosyParentStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('pantry', 'string', 'Pantry');
    $inputs->attach('nosy', NosyChildStructure::class);
  }

}
