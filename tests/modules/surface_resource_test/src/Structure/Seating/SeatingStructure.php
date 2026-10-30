<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Seating;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Refinement\Condition;
use Drupal\surface\Refinement\DoesNotApply;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * How a room is seated, reading two values from whatever holds it.
 *
 * Two ports: the layout it is seated for, which every container must
 * wire, and the number of guests, which a container may leave unwired.
 * A coded refiner reads the layout (the aisle per layout, ruled out for a
 * standing room), a declared rule reads the guests (ten rows at most for
 * a hundred). The venue attaches it at `seating`, the hall at `chairs`,
 * and HallSeatingAlter at its own mount on the hall, each wiring the
 * ports to keys of its own.
 *
 * @see docs/resources.md#ports
 */
#[Structure('resource_test.seating')]
final class SeatingStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->reads('layout', 'string', required: TRUE);
    $inputs->reads('guests', 'integer');
    $inputs->add('rows', 'integer', t('Rows'), default: 5)
      ->setRequired(TRUE)
      ->addConstraint('Range', ['min' => 1, 'max' => 40]);
    $inputs->add('aisle', 'string', t('Aisle'))
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', [
        'choices' => [
          'centre' => t('Centre'),
          'side' => t('Side'),
          'none' => t('None'),
        ],
      ]);
    $inputs->add('ushers', 'integer', t('Ushers'), default: 0)
      ->addConstraint('Range', ['min' => 0, 'max' => 20]);
    // Up to a hundred guests, ten rows at most.
    $inputs->narrow('rows')
      ->when(Condition::atMost('guests', 100))
      ->constrain('Range', ['max' => 10])
      ->because(t('Up to 10 rows for up to 100 guests.'));
    // Over two hundred guests, the ushers are said, even if none.
    $inputs->narrow('ushers')
      ->when(Condition::above('guests', 200))
      ->require();
  }

  /**
   * The aisles a layout has: none at all for a standing room.
   */
  #[RefinesInput('aisle')]
  public static function aisleForLayout(DataDefinitionInterface $aisle, string $layout): DataDefinitionInterface|DoesNotApply {
    if ($layout === 'standing') {
      return DoesNotApply::here();
    }
    if (!$aisle instanceof DataDefinition) {
      return $aisle;
    }
    return $aisle->addConstraint('LabeledChoice', [
      'choices' => $layout === 'theatre'
        ? ['centre' => t('Centre'), 'side' => t('Side')]
        : ['side' => t('Side'), 'none' => t('None')],
    ]);
  }

}
