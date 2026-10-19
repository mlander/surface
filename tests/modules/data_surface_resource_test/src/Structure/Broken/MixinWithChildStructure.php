<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Broken;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_resource_test\Structure\Notice\NoticeStructure;

/**
 * Mixes in the notice, which attaches an address of its own.
 */
#[Structure('resource_test.broken.mixin_with_child')]
final class MixinWithChildStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->with(NoticeStructure::class);
  }

}
