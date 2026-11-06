<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken\Kit;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * Holds variants at "kind" and mixes in the variants "kind" would choose.
 */
#[Structure('resource_test.broken.kit_both')]
final class KitBothStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('type', 'string', 'Type');
    $inputs->attachVariants('kind', by: 'type');
    $inputs->mixInVariants('kind');
  }

}
