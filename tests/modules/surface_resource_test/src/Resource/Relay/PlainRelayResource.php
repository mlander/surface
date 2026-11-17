<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Resource\Relay;

use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_resource_test\Storage\RelayStorage;
use PortableSchema\Builder\Schema;

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
    $inputs->addSchema('token', Schema::string()
      ->title(t('Token'))
      ->description(t('The token each call is made with, pasted here.'))
      ->writeOnly(TRUE));

    $inputs->addSchema('vault_id', Schema::string()
      ->title(t('Vault entry')));
  }

}
