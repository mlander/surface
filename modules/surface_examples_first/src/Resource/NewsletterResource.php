<?php

declare(strict_types=1);

namespace Drupal\surface_examples_first\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_examples_first\Storage\NewsletterStorage;
use PortableSchema\Builder\Schema;

/**
 * The newsletter settings: a form, a tool and a schema, from this class.
 */
#[Resource('first.newsletter',
  label: new TranslatableMarkup('Newsletter settings'),
  storage: NewsletterStorage::class,
)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure the newsletter'),
  description: new TranslatableMarkup('Sets who the newsletter is sent from, how often it goes out and how many recipients one send reaches.'),
  permission: 'administer surface_examples_first',
)]
final class NewsletterResource implements ResourceInterface {

  public const SENDER_NAME = 'sender_name';
  public const SENDER_EMAIL = 'sender_email';
  public const FREQUENCY = 'frequency';
  public const MAX_RECIPIENTS = 'max_recipients';

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema(self::SENDER_NAME, Schema::string()
      ->title(t('Sender name'))
      ->maxLength(64));

    $inputs->addSchema(self::SENDER_EMAIL, Schema::string()
      ->title(t('Sender email'))
      ->format('email'));

    $inputs->addSchema(self::FREQUENCY, Schema::options([
      'weekly' => t('Weekly'),
      'monthly' => t('Monthly'),
    ])
      ->type('string')
      ->title(t('Frequency'))
      ->default('weekly'), required: TRUE);

    $inputs->addSchema(self::MAX_RECIPIENTS, Schema::integer()
      ->title(t('Maximum recipients per send'))
      ->description(t('Larger lists are sent in batches of this size.'))
      ->minimum(1)
      ->maximum(10000)
      ->default(500));
  }

}
