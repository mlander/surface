<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_examples\Shape\PhoneNumber;
use Drupal\surface_examples\Storage\InfoDeskStorage;
use Drupal\surface_examples\Structure\PersonStructure;

/**
 * Example 8: the information desk, the other resource reusing a person.
 *
 * Its lead is the same person structure, its `listed` port reading this
 * desk's `in_program`; its volunteers are a collection of persons, up to
 * four, whose port nothing wires. Its phone is the same shape as a
 * person's. Where it stands is a latitude and a longitude only the desk
 * holds: an anonymous map, addMap(), with no def of its own.
 */
#[Resource('event.desk', storage: InfoDeskStorage::class)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure the information desk'),
  description: new TranslatableMarkup('Sets the information desk: its phone, whether it is listed in the printed program, where it stands, its lead and up to four volunteers.'),
  permission: 'administer site configuration',
)]
final class InfoDeskResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('phone', PhoneNumber::class, t('Desk phone'))
      ->setRequired(TRUE);

    $inputs->add('in_program', 'boolean', t('Listed in the printed program'), default: FALSE);

    // A pair only the desk holds: an anonymous map, written inline.
    $inputs->addMap('location', t('Where the desk stands'), static function (BlueprintInterface $location): void {
      $location->add('lat', 'float', t('Latitude'), default: 43.6532)
        ->addConstraint('Range', ['min' => -90, 'max' => 90]);
      $location->add('lng', 'float', t('Longitude'), default: -79.3832)
        ->addConstraint('Range', ['min' => -180, 'max' => 180]);
    });

    $inputs->attach('lead', PersonStructure::class, t('Desk lead'), reads: ['listed' => 'in_program']);

    $inputs->attachList('volunteers', PersonStructure::class, t('Volunteers'), max: 4);
  }

}
