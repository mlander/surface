<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Structure\Broken\Pack\WiredOwnershipStructure;

/**
 * Mixes in a structure that wires the ports of the child it attaches.
 */
#[Structure('resource_test.broken.mixin_wires_child')]
final class MixinWiresChildStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->mixIn(WiredOwnershipStructure::class);
  }

}
