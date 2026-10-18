<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Structure\Broken;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * An open slot nothing fills: the deciding key can choose nothing.
 */
#[Structure('surface_test.broken.attaching')]
final class AttachingStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('type', 'string', 'Type');
    $inputs->attachBy('settings', by: 'type');
  }

}
