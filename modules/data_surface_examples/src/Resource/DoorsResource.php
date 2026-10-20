<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_examples\Storage\DoorsStorage;

/**
 * Example 11: the stock widgets, and what nothing sent for a key means.
 *
 * A time, checkboxes, radios, a text area and rows of free values, each
 * drawn by a stock widget from what the key declares. Its config schema is
 * fully validatable, so the storage always holds the badges, which may
 * not be cleared, and may clear the notes, which say `nullable: true`.
 */
#[Resource('event.doors', storage: DoorsStorage::class)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure the doors'),
  description: new TranslatableMarkup('Sets when the doors open, the badges checked at the door and the notes for the door team.'),
  permission: 'administer site configuration',
)]
final class DoorsResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('opens', 'string', t('Doors open at'), default: '18:30')
      ->setRequired(TRUE)
      ->setDescription(t('HH:MM, on a 24-hour clock.'))
      ->setSetting('widget', 'time')
      ->addConstraint('Regex', [
        'pattern' => '/^([01]\d|2[0-3]):[0-5]\d$/D',
        'message' => t('Write the time as HH:MM, such as 18:30.'),
      ]);

    $day = DataDefinition::create('string')
      ->addConstraint('LabeledChoice', [
        'choices' => ['fri' => t('Friday'), 'sat' => t('Saturday'), 'sun' => t('Sunday')],
      ]);
    $days = (new ListDataDefinition(['type' => 'list'], $day))
      ->setLabel(t('Days'))
      ->setRequired(TRUE);
    $inputs->addDefinition('days', $days, default: ['fri', 'sat']);

    $inputs->add('badges', 'string', t('Badges'), default: 'printed')
      ->setSetting('widget', 'radios')
      ->addConstraint('LabeledChoice', [
        'choices' => ['printed' => t('Printed at the door'), 'digital' => t('On the phone'), 'none' => t('No badges')],
      ]);

    $inputs->add('notes', 'string', t('Notes for the door team'))
      ->setSetting('multiline', TRUE);

    $greeters = (new ListDataDefinition(['type' => 'list'], DataDefinition::create('string')))
      ->setLabel(t('Greeters'));
    $inputs->addDefinition('greeters', $greeters, default: []);
  }

}
