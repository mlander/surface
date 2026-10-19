<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Alter;

use Drupal\data_surface\Refinement\Condition;
use Drupal\data_surface\Resource\AltersInputsInterface;
use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface\Resource\BlueprintAdditionsInterface;
use Drupal\data_surface_resource_test\Structure\Broken\PortCycleStructure;
use Drupal\data_surface_resource_test\Structure\Seating\SeatingStructure;

/**
 * Wires the seating's guests to the owner's size, which reads the rows.
 *
 * The rows read the guests port, wired to the size, and a rule on the
 * size reads the rows: a cycle through the port, which the build refuses.
 */
#[Alters(PortCycleStructure::class)]
final class PortCycleAlter implements AltersInputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->attach('third_party_settings.data_surface_resource_test', SeatingStructure::class, reads: [
      'layout' => 'layout',
      'guests' => 'size',
    ]);
    $inputs->narrow('size')
      ->when(Condition::atLeast('rows', 20))
      ->constrain('Range', ['max' => 500]);
  }

}
