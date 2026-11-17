<?php

declare(strict_types=1);

namespace Drupal\surface_tool_test\Resource;

use Drupal\surface_tool_test\Structure\VaultBackupStructure;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_tool_test\Storage\VaultStorage;
use PortableSchema\Builder\Schema;

/**
 * Settings that always exist and hold a secret, at the top and below.
 *
 * A standalone operation that changes what exists, so it has a read tool,
 * and a secret at the top, one in an attached child and one in each item
 * of a collection, which the read tool never returns.
 */
#[Resource('tool_test.vault', storage: VaultStorage::class)]
#[Operation('configure',
  label: 'Configure the vault',
  permission: 'administer site configuration',
)]
final class VaultResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('endpoint', Schema::string()
      ->title(t('Endpoint'))
      ->default(''));
    $inputs->addSchema('token', Schema::string()
      ->title(t('API key'))
      ->default('')
      ->writeOnly(TRUE));
    $inputs->attach('backup', VaultBackupStructure::class, t('Backup'));
    $inputs->attachList('mirrors', VaultBackupStructure::class, t('Mirrors'));
  }

}
