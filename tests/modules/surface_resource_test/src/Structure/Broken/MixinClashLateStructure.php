<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Structure\Mixin\TimestampsStructure;

/**
 * Mixes a structure in, then declares one of its members itself.
 */
#[Structure('resource_test.broken.mixin_clash_late')]
final class MixinClashLateStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->mixIn(TimestampsStructure::class);
    $inputs->add('changed', 'string', 'Changed');
  }

}
