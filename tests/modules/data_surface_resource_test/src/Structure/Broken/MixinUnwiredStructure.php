<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Broken;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_resource_test\Structure\Mixin\OwnershipStructure;

/**
 * Mixes the ownership in without wiring the audience it reads.
 */
#[Structure('resource_test.broken.mixin_unwired')]
final class MixinUnwiredStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('visibility', 'string', 'Visibility');
    $inputs->mixIn(OwnershipStructure::class);
  }

}
