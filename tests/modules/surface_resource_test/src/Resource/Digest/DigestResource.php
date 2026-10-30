<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Resource\Digest;

use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_resource_test\Storage\DigestStorage;

/**
 * A digest whose schedule has variants, two of which have no keys.
 *
 * A daily or monthly digest has nothing more to say; only a weekly one
 * has a send day. An empty variant is how "this choice has no extra
 * settings" is spelled, and it renders as nothing at every door.
 */
#[Resource('resource_test.digest', storage: DigestStorage::class)]
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
      ->setRequired(TRUE);
    $inputs->attachVariants('schedule', by: 'frequency')
      ->setLabel('Schedule');
  }

}
