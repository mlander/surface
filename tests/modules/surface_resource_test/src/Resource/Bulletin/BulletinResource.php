<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Resource\Bulletin;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Refinement\DoesNotApply;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_resource_test\Storage\BulletinStorage;

/**
 * A bulletin whose send day applies only when it goes out weekly.
 *
 * The send day is required whenever it applies, and a monthly bulletin
 * has none: its refiner answers that the key does not apply.
 */
#[Resource('resource_test.bulletin', storage: BulletinStorage::class)]
final class BulletinResource implements ResourceInterface {

  /**
   * Configures the bulletin, whose settings always exist.
   */
  #[Operation('configure', label: 'Configure the bulletin', permission: 'administer site configuration')]
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
        'choices' => [
          'weekly' => t('Weekly'),
          'monthly' => t('Monthly'),
        ],
      ]);
    $inputs->add('send_day', 'string', t('Send day'), default: 'monday')
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', [
        'choices' => [
          'monday' => t('Monday'),
          'wednesday' => t('Wednesday'),
          'friday' => t('Friday'),
        ],
      ]);
  }

  /**
   * A send day applies only to a weekly bulletin.
   */
  #[RefinesInput('send_day')]
  public static function sendDayOnlyWhenWeekly(DataDefinitionInterface $send_day, string $frequency): DataDefinitionInterface|DoesNotApply {
    return $frequency === 'weekly' ? $send_day : DoesNotApply::here();
  }

}
