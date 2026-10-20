<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Refinement\Condition;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_examples\Storage\BulletinStorage;

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
final class BulletinResource implements ResourceInterface {

  /**
   * Configures the bulletin, whose settings always exist.
   */
  #[Operation('configure', label: new TranslatableMarkup('Configure the bulletin'), permission: 'administer site configuration')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('frequency', 'string', t('Frequency'), default: 'weekly')
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', [
        'choices' => ['weekly' => t('Weekly'), 'monthly' => t('Monthly')],
      ]);
    $inputs->add('send_day', 'string', t('Send day'), default: 'monday')
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', [
        'choices' => ['monday' => t('Monday'), 'wednesday' => t('Wednesday'), 'friday' => t('Friday')],
      ]);
    $inputs->add('items', 'integer', t('Items per issue'), default: 5)
      ->addConstraint('Range', ['min' => 1, 'max' => 20]);

    $inputs->narrow('send_day')
      ->when(Condition::equals('frequency', 'monthly'))
      ->doesNotApply();
    $inputs->narrow('items')
      ->when(Condition::equals('frequency', 'weekly'))
      ->constrain('Range', ['max' => 5])
      ->because(t('Up to 5 in a weekly issue.'));
  }

}
