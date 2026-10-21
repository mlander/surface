<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool_test\Resource;

use Drupal\data_surface_tool_test\Structure\VaultBackupStructure;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_tool_test\Storage\VaultStorage;

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
    $inputs->add('endpoint', 'string', t('Endpoint'), default: '');
    $token = $inputs->add('token', 'string', t('API key'), default: '');
    DefinitionMetadata::setSecret($token);
    $inputs->attach('backup', VaultBackupStructure::class)
      ->setLabel(t('Backup'));
    $inputs->attachList('mirrors', VaultBackupStructure::class)
      ->setLabel(t('Mirrors'));
  }

}
