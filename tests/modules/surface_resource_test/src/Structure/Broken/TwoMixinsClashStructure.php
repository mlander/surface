<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Structure\Mixin\TimestampsStructure;

/**
 * Mixes in two structures that both declare "created".
 */
#[Structure('resource_test.broken.two_mixins_clash')]
final class TwoMixinsClashStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->mixIn(TimestampsStructure::class);
    $inputs->mixIn(CreatedOnlyStructure::class);
  }

}
