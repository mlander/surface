<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Refinement\Condition;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_examples\Storage\BulletinStorage;
use PortableSchema\Builder\Schema;

/**
 * Example 7: a rule declared as data, and a key that does not apply.
 *
 * The event's bulletin goes out weekly or monthly. Two rules read the
 * frequency, each a condition and an effect written as data, so both
 * schemas state them as written and nothing is run to learn them: a
 * weekly issue holds at most five items, and a monthly one has no send
 * day. The send day is required wherever it applies.
 */
#[Resource('event.bulletin', storage: BulletinStorage::class)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure the bulletin'),
  description: new TranslatableMarkup('Sets how often the event bulletin goes out, the day it is sent and how many items an issue holds.'),
  permission: 'administer site configuration',
)]
final class BulletinResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('frequency', Schema::options(['weekly' => t('Weekly'), 'monthly' => t('Monthly')])
      ->title(t('Frequency'))
      ->default('weekly')
      ->required());

    $inputs->add('send_day', Schema::options([
      'monday' => t('Monday'),
      'wednesday' => t('Wednesday'),
      'friday' => t('Friday'),
    ])
      ->title(t('Send day'))
      ->default('monday')
      ->required());

    $inputs->add('items', Schema::integer()
      ->title(t('Items per issue'))
      ->default(5)
      ->minimum(1)
      ->maximum(20));

    $inputs->narrow('send_day')
      ->when(Condition::equals('frequency', 'monthly'))
      ->doesNotApply();

    $inputs->narrow('items')
      ->when(Condition::equals('frequency', 'weekly'))
      ->constrain(Schema::any()->maximum(5))
      ->because(t('Up to 5 in a weekly issue.'));
  }

}
