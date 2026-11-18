<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_examples\Storage\DoorsStorage;
use PortableSchema\Builder\Schema;

/**
 * Example 11: the stock widgets, and what nothing sent for a key means.
 *
 * A time, checkboxes, radios, a text area and rows of free values, each
 * drawn by a stock widget from what the key declares. Its config schema is
 * fully validatable, so the storage always holds the badges, which may
 * not be cleared, and may clear the notes, which say `nullable: true`.
 */
#[Resource('event.doors', storage: DoorsStorage::class)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure the doors'),
  description: new TranslatableMarkup('Sets when the doors open, the badges checked at the door and the notes for the door team.'),
  permission: 'administer site configuration',
)]
final class DoorsResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('opens', Schema::string()
      ->title(t('Doors open at'))
      ->description(t('HH:MM, on a 24-hour clock.'))
      ->default('18:30')
      ->check('drupal:Regex', [
        'pattern' => '/^([01]\d|2[0-3]):[0-5]\d$/D',
        'message' => t('Write the time as HH:MM, such as 18:30.'),
      ])
      ->ui(widget: 'time')
      ->required());
    $inputs->add('days', Schema::list(Schema::options([
      'fri' => t('Friday'),
      'sat' => t('Saturday'),
      'sun' => t('Sunday'),
    ]))
      ->title(t('Days'))
      ->default(['fri', 'sat'])
      ->required());

    $inputs->add('badges', Schema::options([
      'printed' => t('Printed at the door'),
      'digital' => t('On the phone'),
      'none' => t('No badges'),
    ])
      ->title(t('Badges'))
      ->default('printed')
      ->ui(widget: 'radios'));

    $inputs->add('notes', Schema::string()
      ->title(t('Notes for the door team'))
      ->ui(widget: 'textarea'));

    $inputs->add('greeters', Schema::list(Schema::string())
      ->title(t('Greeters'))
      ->default([]));
  }

}
