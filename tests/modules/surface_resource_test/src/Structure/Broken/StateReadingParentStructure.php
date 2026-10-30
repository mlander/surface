<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * Attaches a structure whose access answer is not what it says it is.
 */
#[Structure('resource_test.broken.state_reading_access_parent')]
final class StateReadingParentStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('title', 'string', 'Title');
    $inputs->attach('child', StateReadingStructure::class);
  }

}
