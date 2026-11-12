<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_examples\Storage\RegistrationStep1Storage;
use PortableSchema\Builder\Schema;

/**
 * Example 1: declare what you accept.
 *
 * Three keys, each with its type, its label and what it allows. The form
 * at /surface-examples/1, its validation, and the tool
 * surface:registration.step1:configure are all read from here.
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
    $inputs->addSchema(self::TITLE, Schema::string()
      ->title(t('Event title')));

    $inputs->addSchema(self::CAPACITY, Schema::integer()
      ->title(t('Capacity'))
      ->minimum(1)
      ->maximum(1000)
      ->default(50));

    $inputs->addSchema(self::OPEN, Schema::bool()
      ->title(t('Registration open'))
      ->default(TRUE));
  }

}
