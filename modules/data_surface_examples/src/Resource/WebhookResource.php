<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_examples\Storage\WebhookStorage;

/**
 * Example 14, the alter's way: a resource that knows nothing of managed keys.
 *
 * The webhook is signed with a key pasted here, a secret. A module that
 * manages keys may offer one in its place, with an alter; this class does
 * not change for it.
 */
#[Resource('event.webhook', storage: WebhookStorage::class)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure the sales webhook'),
  description: new TranslatableMarkup("Sets the address the event's sales are posted to, and the key each post is signed with."),
  permission: 'administer site configuration',
)]
final class WebhookResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('endpoint', 'uri', t('Endpoint'), default: 'https://hooks.example.com/sales')
      ->setRequired(TRUE);
    $api_key = $inputs->add('api_key', 'string', t('API key'))
      ->setDescription(t('The key each post is signed with, pasted here.'));
    DefinitionMetadata::setSecret($api_key);
  }

}
