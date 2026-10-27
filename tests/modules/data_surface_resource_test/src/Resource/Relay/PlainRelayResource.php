<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Resource\Relay;

use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_resource_test\Storage\RelayStorage;

/**
 * A relay with no resolve: a pasted token and the name of a vault entry.
 *
 * The relay's twin, declared the same less its one resolve, so a test
 * holds both documents and both tools to one another.
 */
#[Resource('resource_test.relay.plain', storage: RelayStorage::class)]
#[Operation('configure', label: 'Configure the relay', permission: 'administer site configuration')]
final class PlainRelayResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $token = $inputs->add('token', 'string', t('Token'))
      ->setDescription(t('The token each call is made with, pasted here.'));
    DefinitionMetadata::setSecret($token);

    $inputs->add('vault_id', 'string', t('Vault entry'));
  }

}
