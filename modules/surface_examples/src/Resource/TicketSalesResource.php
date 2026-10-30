<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_examples\Keys\TicketTypes;
use Drupal\surface_examples\Storage\TicketSalesStorage;
use Drupal\surface_examples\Structure\TicketLimitStructure;

/**
 * Example 9: keys that come from the site, one per ticket type.
 *
 * The class cannot list the ticket types: the site's configuration does,
 * and TicketTypes reads it. This class declares the two maps the types
 * sit in, a checkbox per type and a structure per type. Each type's
 * structure reads that type's checkbox through its `selling` port.
 */
#[Resource('event.sales', storage: TicketSalesStorage::class)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure ticket sales'),
  description: new TranslatableMarkup('Sets which ticket types are on sale, and for each how many tickets one order may hold and whether a waiting list opens when it sells out.'),
  permission: 'administer site configuration',
)]
final class TicketSalesResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addEach('on_sale', 'boolean', from: TicketTypes::class, default: FALSE)
      ->setLabel(t('On sale'))
      ->setDescription(t('Which ticket types can be bought.'));

    $inputs->attachEach('limits', TicketLimitStructure::class, from: TicketTypes::class, reads: ['selling' => 'on_sale'])
      ->setLabel(t('Limits'));
  }

}
