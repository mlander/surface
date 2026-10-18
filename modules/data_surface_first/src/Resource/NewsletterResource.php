<?php

declare(strict_types=1);

namespace Drupal\data_surface_first\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_first\Storage\NewsletterStorage;

/**
 * The newsletter settings: a form, a tool and a schema, from this class.
 */
#[Resource('first.newsletter', storage: NewsletterStorage::class)]
final class NewsletterResource implements ResourceInterface {

  /**
   * Configures the newsletter, whose settings always exist.
   */
  #[Operation('configure', label: new TranslatableMarkup('Configure the newsletter'), permission: 'administer data_surface_first')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('sender_name', 'string', t('Sender name'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 64]);
    $inputs->add('sender_email', 'email', t('Sender email'))
      ->setRequired(TRUE);
    $inputs->add('frequency', 'string', t('Frequency'), default: 'weekly')
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', [
        'choices' => [
          'weekly' => t('Weekly'),
          'monthly' => t('Monthly'),
        ],
      ]);
    $inputs->add('max_recipients', 'integer', t('Maximum recipients per send'), default: 500)
      ->setDescription(t('Larger lists are sent in batches of this size.'))
      ->addConstraint('Range', ['min' => 1, 'max' => 10000]);
  }

}
