<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_examples\Shape\PhoneNumber;
use Drupal\data_surface_examples\Storage\InfoDeskStorage;
use Drupal\data_surface_examples\Structure\PersonStructure;

/**
 * Example 8: the information desk, the other resource reusing a person.
 *
 * Its lead is the same person structure, its `listed` port reading this
 * desk's `in_program`; its volunteers are a collection of persons, up to
 * four, whose port nothing wires. Its phone is the same shape as a
 * person's.
 */
#[Resource('event.desk', storage: InfoDeskStorage::class)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure the information desk'),
  description: new TranslatableMarkup('Sets the information desk: its phone, whether it is listed in the printed program, its lead and up to four volunteers.'),
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

    $inputs->attach('lead', PersonStructure::class, reads: ['listed' => 'in_program'])
      ->setLabel(t('Desk lead'));

    $inputs->attachList('volunteers', PersonStructure::class, max: 4)
      ->setLabel(t('Volunteers'));
  }

}
