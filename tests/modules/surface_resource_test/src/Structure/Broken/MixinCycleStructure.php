<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * Mixes in a structure that mixes this one back in.
 */
#[Structure('resource_test.broken.mixin_cycle')]
final class MixinCycleStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('first', Schema::string()
      ->title('First'));
    $inputs->mixIn(MixinCycleLoopStructure::class);
  }

}
