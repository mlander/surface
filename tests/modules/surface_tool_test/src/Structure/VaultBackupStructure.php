<?php

declare(strict_types=1);

namespace Drupal\surface_tool_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

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
    $inputs->addSchema('host', Schema::string()
      ->title(t('Host'))
      ->default(''));
    $inputs->addSchema('password', Schema::string()
      ->title(t('Password'))
      ->default('')
      ->writeOnly(TRUE));
  }

}
