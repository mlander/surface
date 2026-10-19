<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Alter;

use Drupal\data_surface\Resource\AltersInputsInterface;
use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface\Resource\BlueprintAdditionsInterface;
use Drupal\data_surface_resource_test\Structure\Broken\MountingMixinStructure;

/**
 * Mounts a key on a structure, which some owner then mixes in.
 */
#[Alters(MountingMixinStructure::class)]
final class MountingMixinAlter implements AltersInputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->add('color', 'string', 'Color');
  }

}
