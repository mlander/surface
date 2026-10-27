<?php

declare(strict_types=1);

namespace Drupal\data_surface_first\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_first\Storage\NewsletterStorage;

/**
 * The newsletter settings: a form, a tool and a schema, from this class.
 */
#[Resource('first.newsletter', storage: NewsletterStorage::class)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure the newsletter'),
  description: new TranslatableMarkup('Sets who the newsletter is sent from, how often it goes out and how many recipients one send reaches.'),
  permission: 'administer data_surface_first',
)]
final class NewsletterResource implements ResourceInterface {

  /**
   * The sender name key.
   */
  public const SENDER_NAME = 'sender_name';

  /**
   * The sender email key.
   */
  public const SENDER_EMAIL = 'sender_email';

  /**
   * The frequency key.
   */
  public const FREQUENCY = 'frequency';

  /**
   * The maximum recipients per send key.
   */
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
