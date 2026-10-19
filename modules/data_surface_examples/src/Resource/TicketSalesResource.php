<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_examples\Keys\TicketTypes;
use Drupal\data_surface_examples\Storage\TicketSalesStorage;
use Drupal\data_surface_examples\Structure\TicketLimitStructure;

/**
 * Example 9: keys that come from the site, one per ticket type.
 *
 * The class cannot list the ticket types: the site's configuration does,
 * and TicketTypes reads it. This class declares the two maps the types
 * sit in, a checkbox per type and a structure per type.
 */
#[Resource('event.sales', storage: TicketSalesStorage::class)]
final class TicketSalesResource implements ResourceInterface {

  /**
   * Configures ticket sales, which always exist.
   */
  #[Operation('configure', label: new TranslatableMarkup('Configure ticket sales'), permission: 'administer site configuration')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addEach('on_sale', 'boolean', from: TicketTypes::class, default: FALSE)
      ->setLabel(t('On sale'))
      ->setDescription(t('Which ticket types can be bought.'));
    $inputs->attachEach('limits', TicketLimitStructure::class, from: TicketTypes::class)
      ->setLabel(t('Limits'));
  }

}
