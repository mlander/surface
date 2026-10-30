<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery;

use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\AltersInputsInterface;
use Drupal\surface\Resource\BlueprintAdditionsInterface;

/**
 * Names an id nothing claims, as of a module that is off.
 */
#[Alters('resource_test.not_there')]
final class UnknownIdAlter implements AltersInputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
  }

}
