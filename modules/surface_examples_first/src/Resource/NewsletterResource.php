<?php

declare(strict_types=1);

namespace Drupal\surface_examples_first\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_examples_first\Storage\NewsletterStorage;

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
    $inputs->add(self::SENDER_NAME, 'string', t('Sender name'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 64]);

    $inputs->add(self::SENDER_EMAIL, 'email', t('Sender email'))
      ->setRequired(TRUE);

    $inputs->add(self::FREQUENCY, 'string', t('Frequency'), default: 'weekly')
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', [
        'choices' => [
          'weekly' => t('Weekly'),
          'monthly' => t('Monthly'),
        ],
      ]);

    $inputs->add(self::MAX_RECIPIENTS, 'integer', t('Maximum recipients per send'), default: 500)
      ->setDescription(t('Larger lists are sent in batches of this size.'))
      ->addConstraint('Range', ['min' => 1, 'max' => 10000]);
  }

}
