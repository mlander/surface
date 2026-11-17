<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_examples\Storage\EventSetupStorage;
use PortableSchema\Builder\Schema;

/**
 * Example 19: the event's setup, which a flow draws over two steps.
 *
 * Stock keys, in no group: the flow class EventSetupFlow lists which
 * keys each step draws. The route at /surface-examples/19 draws them,
 * then the event notices as a third step, all into one stage.
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
    $inputs->addSchema('name', Schema::string()
      ->title(t('Event name'))
      ->default('Autumn meetup')
      ->maxLength(128)
      ->required());

    $inputs->addSchema('date', Schema::string()
      ->title(t('Date'))
      ->description(t('YYYY-MM-DD.'))
      ->default('2026-10-17')
      ->check('drupal:Regex', [
        'pattern' => '/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/D',
        'message' => t('Write the date as YYYY-MM-DD, such as 2026-10-17.'),
      ])
      ->ui(widget: 'date'));

    $inputs->addSchema('capacity', Schema::integer()
      ->title(t('Capacity'))
      ->default(100)
      ->minimum(1)
      ->maximum(5000));

    $inputs->addSchema('price', Schema::number()
      ->title(t('Ticket price'))
      ->default(25.0)
      ->minimum(0));
    $inputs->describe('price', suffix: t('EUR'));

    $inputs->addSchema('sales_open', Schema::bool()
      ->title(t('Ticket sales open'))
      ->default(FALSE));

    $inputs->addSchema('note', Schema::string()
      ->title(t('Note for buyers'))
      ->ui(widget: 'textarea'));
  }

}
