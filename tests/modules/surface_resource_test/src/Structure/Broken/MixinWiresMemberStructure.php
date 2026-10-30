<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Structure\Mixin\OwnershipStructure;

/**
 * Wires the ownership's audience to a member the ownership brought.
 */
#[Structure('resource_test.broken.mixin_wires_member')]
final class MixinWiresMemberStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->mixIn(OwnershipStructure::class, reads: ['audience' => 'owner']);
  }

}
