<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Broken;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_resource_test\Structure\Mixin\TimestampsStructure;

/**
 * Declares "created" itself, then mixes in a structure that declares it.
 */
#[Structure('resource_test.broken.mixin_clash')]
final class MixinClashStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('created', 'string', 'Created');
    $inputs->with(TimestampsStructure::class);
  }

}
