<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_examples\Storage\EventNoticesStorage;
use PortableSchema\Builder\Schema;

/**
 * Example 19: the event's notices, the third step of its setup flow.
 *
 * A resource of its own, in a config object of its own: the flow at
 * /surface-examples/19 draws it whole as its third step, while ticket
 * sales are open or a banner is shown, into the same stage as the event
 * setup's two steps.
 */
#[Resource('event.notices', storage: EventNoticesStorage::class)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure the event notices'),
  description: new TranslatableMarkup('Sets the banner shown on the event pages and whether it is shown.'),
  permission: 'administer site configuration',
)]
final class EventNoticesResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('banner', Schema::string()
      ->title(t('Banner'))
      ->default('')
      ->maxLength(500)
      ->ui(widget: 'textarea'));

    $inputs->add('show_banner', Schema::bool()
      ->title(t('Show the banner'))
      ->default(FALSE));
  }

}
