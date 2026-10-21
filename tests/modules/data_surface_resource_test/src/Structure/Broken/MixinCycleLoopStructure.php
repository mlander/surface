<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Broken;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * The other half of the cycle: mixes MixinCycleStructure back in.
 */
#[Structure('resource_test.broken.mixin_cycle_loop')]
final class MixinCycleLoopStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('second', 'string', 'Second');
    $inputs->mixIn(MixinCycleStructure::class);
  }

}
