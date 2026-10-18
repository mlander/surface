<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Resource\Digest;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_surface_test\Storage\DigestStorage;

/**
 * A digest whose schedule is a slot, two of whose variants have no keys.
 *
 * A daily or monthly digest has nothing more to say; only a weekly one
 * has a send day. An empty variant is how "this choice has no extra
 * settings" is spelled, and it renders as nothing at every door.
 */
#[Resource('surface_test.digest', storage: DigestStorage::class)]
final class DigestResource implements ResourceInterface {

  /**
   * Configures the digest, whose settings always exist.
   */
  #[Operation('configure', label: 'Configure the digest', permission: 'administer site configuration')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('frequency', 'string', 'Frequency', default: 'weekly')
      ->setRequired(TRUE)
      ->addConstraint('Choice', ['choices' => ['daily', 'weekly', 'monthly']]);
    $inputs->attachBy('schedule', by: 'frequency')
      ->setLabel('Schedule');
  }

}
