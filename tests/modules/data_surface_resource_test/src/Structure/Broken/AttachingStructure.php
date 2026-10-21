<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Broken;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Variants nothing declares: the deciding key can choose nothing.
 */
#[Structure('resource_test.broken.attaching')]
final class AttachingStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('type', 'string', 'Type');
    $inputs->attachVariants('settings', by: 'type');
  }

}
