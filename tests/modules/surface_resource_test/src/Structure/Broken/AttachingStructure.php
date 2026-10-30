<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

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
