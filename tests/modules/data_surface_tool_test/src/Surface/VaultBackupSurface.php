<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool_test\Surface;

use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * Where the vault is backed up: a part with a secret of its own.
 *
 * No target: the vault stores it under its backup key.
 */
#[Surface('tool_test.vault.backup')]
final class VaultBackupSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('host', 'string', t('Host'), default: '');
    $password = $inputs->add('password', 'string', t('Password'), default: '');
    DefinitionMetadata::setSecret($password);
  }

}
