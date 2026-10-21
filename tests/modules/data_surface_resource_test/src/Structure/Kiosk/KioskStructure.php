<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Kiosk;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\Refinement\Condition;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * A kiosk: a declared rule's message on each bound it may set.
 *
 * Unlicensed, the capacity stops at a hundred and says so in its rule's
 * words; licensed, the code is at least four characters and a day is
 * chosen, each in its rule's words. The queue's rule says the maximum
 * the key already holds, so the key's own message stands.
 */
#[Structure('resource_test.kiosk')]
final class KioskStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('licensed', 'boolean', 'Licensed', default: FALSE);
    $inputs->add('capacity', 'integer', 'Capacity')
      ->addConstraint('Range', ['min' => 1, 'max' => 1000]);
    $inputs->add('queue', 'integer', 'Queue')
      ->addConstraint('Range', ['max' => 10, 'maxMessage' => 'A queue holds ten at most.']);
    $inputs->add('code', 'string', 'Code')
      ->addConstraint('Length', ['max' => 12]);
    $day = DataDefinition::create('string')
      ->addConstraint('Choice', ['choices' => ['mon', 'tue']]);
    $inputs->addList('days', $day, 'Days', default: []);

    $inputs->narrow('capacity')
      ->unless(Condition::equals('licensed', TRUE))
      ->constrain('Range', ['max' => 100, 'message' => t('From 1 to 100 without a license.')]);
    $inputs->narrow('queue')
      ->unless(Condition::equals('licensed', TRUE))
      ->constrain('Range', ['max' => 10, 'message' => t('Ten at most without a license.')]);
    $inputs->narrow('code')
      ->when(Condition::equals('licensed', TRUE))
      ->constrain('Length', ['min' => 4, 'message' => t('A licensed code is four characters or more.')]);
    $inputs->narrow('days')
      ->when(Condition::equals('licensed', TRUE))
      ->constrain('Count', ['min' => 1, 'message' => t('Choose a day while licensed.')]);
  }

}
