<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Resource\Relay;

use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_resource_test\Compute\VaultToken;
use Drupal\surface_resource_test\Storage\RelayStorage;

/**
 * A relay whose token, at use, is the vault entry's while one is named.
 *
 * Its twin's keys, and one resolve: the token is resolved from the vault
 * entry by VaultToken, and stored as pasted.
 */
#[Resource('resource_test.relay', storage: RelayStorage::class)]
#[Operation('configure', label: 'Configure the relay', permission: 'administer site configuration')]
final class RelayResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    PlainRelayResource::defineInputs($inputs);

    $inputs->resolve('token', from: ['vault_id'], using: VaultToken::class);
  }

}
