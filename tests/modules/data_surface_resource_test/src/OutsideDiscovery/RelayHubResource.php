<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\OutsideDiscovery;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_resource_test\Storage\RelayHubStorage;

/**
 * A relay hub: one link mixed in, one attached, and a list of them.
 *
 * Each holds the link structure, whose resolve is its own: in the hub's
 * frame where it is mixed in, at `primary`, and in each item of
 * `backups`. Registered only by the test that says so.
 */
#[Resource('resource_test.relay_hub', storage: RelayHubStorage::class)]
#[Operation('configure', label: 'Configure the relay hub', permission: 'administer site configuration')]
final class RelayHubResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->mixIn(RelayLinkStructure::class);

    $inputs->attach('primary', RelayLinkStructure::class)
      ->setLabel(t('Primary link'));

    $inputs->attachList('backups', RelayLinkStructure::class, max: 3)
      ->setLabel(t('Backup links'));
  }

}
