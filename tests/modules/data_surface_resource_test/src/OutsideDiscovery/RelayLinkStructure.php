<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\OutsideDiscovery;

use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_resource_test\Compute\VaultToken;

/**
 * A link of a relay hub: a pasted token, resolved from a vault entry.
 *
 * The resolve is the structure's own, among its own keys, so it applies
 * wherever the structure is held. Registered only by the test that says
 * so.
 */
#[Structure('resource_test.relay_link')]
final class RelayLinkStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $token = $inputs->add('token', 'string', t('Token'));
    DefinitionMetadata::setSecret($token);

    $inputs->add('vault_id', 'string', t('Vault entry'));

    $inputs->resolve('token', from: ['vault_id'], using: VaultToken::class);
  }

}
