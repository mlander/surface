<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Alter;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\BlueprintAdditionsInterface;
use Drupal\surface\Resource\AltersInputsInterface;
use Drupal\surface_resource_test\Structure\Broken\InstanceAlterRefinerStructure;

/**
 * Refines with an instance method that does not declare itself runtime.
 *
 * An instance method can read the services its alter was built with, so
 * it may only be a runtime refiner; this one is refused when the
 * structure is built.
 */
#[Alters(InstanceAlterRefinerStructure::class)]
final class InstanceRefinerAlter implements AltersInputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
  }

  /**
   * An instance method, declared static by default.
   */
  #[RefinesInput('name')]
  public function nameOfKind(DataDefinitionInterface $name, string $kind): DataDefinitionInterface {
    return $name->addConstraint('Length', ['max' => 10]);
  }

}
