<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Alter;

use Drupal\surface\Refinement\Condition;
use Drupal\surface\Resource\AltersInputsInterface;
use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\BlueprintAdditionsInterface;
use Drupal\surface_resource_test\Resource\Venue\HallResource;
use Drupal\surface_resource_test\Structure\Seating\SeatingStructure;

/**
 * An adapter: the seating structure at this module's mount on the hall.
 *
 * Its ports read the hall's style and guests, other keys than the hall's
 * own chairs read; a rule on the hall's guests reads the structure's
 * aisle, glue for this one owner.
 */
#[Alters(HallResource::class)]
final class HallSeatingAlter implements AltersInputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->attach('third_party_settings.surface_resource_test', SeatingStructure::class, reads: [
      'layout' => 'style',
      'guests' => 'guests',
    ]);
    $inputs->describe('third_party_settings.surface_resource_test', label: 'Overflow seating');
    // With no aisle, the overflow seats no more than 300 guests.
    $inputs->narrow('guests')
      ->when(Condition::equals('aisle', 'none'))
      ->constrain('Range', ['max' => 300]);
  }

}
