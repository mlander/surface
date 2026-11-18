<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Alter;

use Drupal\surface\Refinement\Condition;
use Drupal\surface\Resource\AltersInputsInterface;
use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\BlueprintAdditionsInterface;
use Drupal\surface_resource_test\Structure\Broken\PortCycleStructure;
use Drupal\surface_resource_test\Structure\Seating\SeatingStructure;
use PortableSchema\Builder\Schema;

/**
 * Wires the seating's guests to the declaring class's size, read from the rows.
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
    $inputs->attach('third_party_settings.surface_resource_test', SeatingStructure::class, reads: [
      'layout' => 'layout',
      'guests' => 'size',
    ]);
    $inputs->narrow('size')
      ->when(Condition::atLeast('rows', 20))
      ->constrain(Schema::any()->maximum(500));
  }

}
