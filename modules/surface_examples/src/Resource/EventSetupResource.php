<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\ArrangementGroup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_examples\Storage\EventSetupStorage;

/**
 * Example 19: the event's setup, in two groups a flow draws as two steps.
 *
 * Stock keys in the groups `basics` and `tickets`. The route at
 * /surface-examples/19 draws one group a step, then the event notices as
 * a third step, all into one stage.
 */
#[Resource('event.setup', storage: EventSetupStorage::class)]
#[Operation('configure',
  label: new TranslatableMarkup('Set up the event'),
  description: new TranslatableMarkup('Sets the event name, date and capacity, then the ticket price, whether sales are open and a note for buyers.'),
  permission: 'administer site configuration',
)]
final class EventSetupResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $basics = ArrangementGroup::details('basics', t('Basics'));
    $tickets = ArrangementGroup::details('tickets', t('Tickets'));

    $inputs->add('name', 'string', t('Event name'), default: 'Autumn meetup')
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 128]);
    $inputs->describe('name', group: $basics);

    $inputs->add('date', 'string', t('Date'), default: '2026-10-17')
      ->setDescription(t('YYYY-MM-DD.'))
      ->setSetting('widget', 'date')
      ->addConstraint('Regex', [
        'pattern' => '/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/D',
        'message' => t('Write the date as YYYY-MM-DD, such as 2026-10-17.'),
      ]);
    $inputs->describe('date', group: $basics);

    $inputs->add('capacity', 'integer', t('Capacity'), default: 100)
      ->addConstraint('Range', ['min' => 1, 'max' => 5000]);
    $inputs->describe('capacity', group: $basics);

    $inputs->add('price', 'float', t('Ticket price'), default: 25.0)
      ->addConstraint('Range', ['min' => 0]);
    $inputs->describe('price', group: $tickets, suffix: t('EUR'));

    $inputs->add('sales_open', 'boolean', t('Ticket sales open'), default: FALSE);
    $inputs->describe('sales_open', group: $tickets);

    $inputs->add('note', 'string', t('Note for buyers'))
      ->setSetting('multiline', TRUE);
    $inputs->describe('note', group: $tickets);
  }

}
