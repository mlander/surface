<?php

declare(strict_types=1);

namespace Drupal\surface_tool_test\Structure;

use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * Where the vault is backed up: a part with a secret of its own.
 *
 * No storage: the vault stores it under its backup key.
 */
#[Structure('tool_test.vault.backup')]
final class VaultBackupStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('host', 'string', t('Host'), default: '');
    $password = $inputs->add('password', 'string', t('Password'), default: '');
    DefinitionMetadata::setSecret($password);
  }

}
