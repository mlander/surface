<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Alter;

use Drupal\data_surface\Resource\AltersInputsInterface;
use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface\Resource\BlueprintAdditionsInterface;
use Drupal\data_surface_resource_test\Structure\Broken\AlterAttachTargetStructure;
use Drupal\data_surface_resource_test\Structure\Seating\SeatingStructure;

/**
 * Attaches the seating structure at the owner's level, which is refused.
 *
 * An alter attaches at its own mount and only there.
 */
#[Alters(AlterAttachTargetStructure::class)]
final class OwnerLevelAttachAlter implements AltersInputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->attach('seating', SeatingStructure::class, reads: ['layout' => 'layout']);
  }

}
