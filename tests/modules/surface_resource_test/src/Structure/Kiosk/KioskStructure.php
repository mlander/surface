<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Kiosk;

use Drupal\surface\Refinement\Condition;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

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
    $inputs->add('licensed', Schema::bool()
      ->title('Licensed')
      ->default(FALSE));
    $inputs->add('capacity', Schema::integer()
      ->title('Capacity')
      ->minimum(1)
      ->maximum(1000));
    $inputs->add('queue', Schema::integer()
      ->title('Queue')
      ->check('drupal:Range', ['max' => 10, 'maxMessage' => 'A queue holds ten at most.']));
    $inputs->add('code', Schema::string()
      ->title('Code')
      ->maxLength(12));
    $inputs->add('days', Schema::list(Schema::enum(['mon', 'tue']))
      ->title('Days')
      ->default([]));

    $inputs->narrow('capacity')
      ->unless(Condition::equals('licensed', TRUE))
      ->constrain(Schema::any()->check('drupal:Range', [
        'max' => 100,
        'maxMessage' => t('From 1 to 100 without a license.'),
      ]));
    $inputs->narrow('queue')
      ->unless(Condition::equals('licensed', TRUE))
      ->constrain(Schema::any()->check('drupal:Range', [
        'max' => 10,
        'maxMessage' => t('Ten at most without a license.'),
      ]));
    $inputs->narrow('code')
      ->when(Condition::equals('licensed', TRUE))
      ->constrain(Schema::any()->check('drupal:Length', [
        'min' => 4,
        'minMessage' => t('A licensed code is four characters or more.'),
      ]));
    $inputs->narrow('days')
      ->when(Condition::equals('licensed', TRUE))
      ->constrain(Schema::any()->check('drupal:Count', [
        'min' => 1,
        'minMessage' => t('Choose a day while licensed.'),
      ]));
  }

}
