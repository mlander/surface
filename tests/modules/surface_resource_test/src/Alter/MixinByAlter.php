<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Alter;

use Drupal\surface\Resource\AltersInputsInterface;
use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\BlueprintAdditionsInterface;
use Drupal\surface_resource_test\Structure\Broken\MixinByAlterStructure;
use Drupal\surface_resource_test\Structure\Mixin\TimestampsStructure;

/**
 * Mixes a structure in from an alter, which only an owner may do.
 */
#[Alters(MixinByAlterStructure::class)]
final class MixinByAlter implements AltersInputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->mixIn(TimestampsStructure::class);
  }

}
