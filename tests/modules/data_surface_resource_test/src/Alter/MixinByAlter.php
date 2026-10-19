<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Alter;

use Drupal\data_surface\Resource\AltersInputsInterface;
use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface\Resource\BlueprintAdditionsInterface;
use Drupal\data_surface_resource_test\Structure\Broken\MixinByAlterStructure;
use Drupal\data_surface_resource_test\Structure\Mixin\TimestampsStructure;

/**
 * Mixes a structure in from an alter, which only an owner may do.
 */
#[Alters(MixinByAlterStructure::class)]
final class MixinByAlter implements AltersInputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->with(TimestampsStructure::class);
  }

}
