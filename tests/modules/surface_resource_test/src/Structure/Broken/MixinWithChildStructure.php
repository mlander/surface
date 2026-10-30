<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Structure\Notice\NoticeStructure;

/**
 * Mixes in the notice, which attaches an address of its own.
 */
#[Structure('resource_test.broken.mixin_with_child')]
final class MixinWithChildStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->mixIn(NoticeStructure::class);
  }

}
