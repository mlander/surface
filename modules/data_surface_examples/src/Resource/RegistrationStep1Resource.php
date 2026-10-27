<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_examples\Storage\RegistrationStep1Storage;

/**
 * Example 1: declare what you accept.
 *
 * Three keys, each with its type, its label and what it allows. The form
 * at /surface-examples/1, its validation, and the tool
 * data_surface:registration.step1:configure are all read from here.
 */
#[Resource('registration.step1', storage: RegistrationStep1Storage::class)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure registration (step 1)'),
  description: new TranslatableMarkup('Sets the event title, its capacity and whether registration is open.'),
  permission: 'administer site configuration',
)]
final class RegistrationStep1Resource implements ResourceInterface {

  public const TITLE = 'title';
  public const CAPACITY = 'capacity';
  public const OPEN = 'open';

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add(self::TITLE, 'string', t('Event title'))
      ->setRequired(TRUE);

    $inputs->add(self::CAPACITY, 'integer', t('Capacity'), default: 50)
      ->addConstraint('Range', ['min' => 1, 'max' => 1000]);

    $inputs->add(self::OPEN, 'boolean', t('Registration open'), default: TRUE);
  }

}
