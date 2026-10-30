<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery;

use Drupal\surface\Resource\AltersInputsInterface;
use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\BlueprintAdditionsInterface;
use Drupal\surface_resource_test\Compute\VaultToken;
use Drupal\surface_resource_test\Resource\Relay\PlainRelayResource;

/**
 * Stands in for the relay's token with a vault entry of the north vault.
 *
 * One of two alters that resolve the same key, which the build refuses;
 * registered only by the test that says so.
 */
#[Alters(PlainRelayResource::class)]
final class NorthVaultAlter implements AltersInputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->add('vault_id', 'string', 'Vault entry');
    $inputs->resolve('token', from: ['vault_id'], using: VaultToken::class);
  }

}
