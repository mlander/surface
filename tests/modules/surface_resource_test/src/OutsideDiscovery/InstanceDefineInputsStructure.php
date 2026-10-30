<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;

/**
 * Carries #[Structure] with an instance defineInputs().
 *
 * PHP refuses a class that implements StructureInterface and declares the
 * method non-static, so this one leaves the interface off to get past
 * the compiler. Discovery refuses it, naming it, which is why it lives
 * outside src/Resource and src/Structure: a refusal at discovery is
 * everyone's, and the test hands it to a registry of its own.
 */
#[Structure('resource_test.broken.instance_define_inputs')]
final class InstanceDefineInputsStructure {

  /**
   * Not static, which a structure's defineInputs() has to be.
   *
   * @param \Drupal\surface\Resource\BlueprintInterface $inputs
   *   The input blueprint to fill.
   */
  public function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('name', 'string', 'Name');
  }

}
