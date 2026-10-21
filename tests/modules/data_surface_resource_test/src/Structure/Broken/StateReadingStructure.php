<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Broken;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_resource_test\Access\Broken\StateReadingAccess;

/**
 * Its access class says it reads the account alone and reads State.
 *
 * Attached by StateReadingParentStructure, where it is asked.
 */
#[Structure('resource_test.broken.state_reading_access', access: StateReadingAccess::class)]
final class StateReadingStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('note', 'string', 'Note');
  }

}
