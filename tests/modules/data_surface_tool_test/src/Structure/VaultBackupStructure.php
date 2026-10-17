<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool_test\Structure;

use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\ShapeInterface;
use Drupal\data_surface\Resource\StructureInterface;

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
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('host', 'string', t('Host'), default: '');
    $password = $inputs->add('password', 'string', t('Password'), default: '');
    DefinitionMetadata::setSecret($password);
  }

}
