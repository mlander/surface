<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool_test\Surface;

use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Surface\Attribute\Situation;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceInterface;
use Drupal\data_surface_tool_test\Target\VaultTarget;

/**
 * Settings that always exist and hold a secret, on the surface and below.
 *
 * A standalone situation that changes what exists, so it has a read tool,
 * and a secret at the top and one in an attached child, which the read
 * tool never returns.
 */
#[Surface('tool_test.vault', target: VaultTarget::class)]
final class VaultSurface implements SurfaceInterface {

  /**
   * Configures the vault, which always exists.
   */
  #[Situation('configure', label: 'Configure the vault', permission: 'administer site configuration')]
  public static function configure(): SurfaceContext {
    return new SurfaceContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('endpoint', 'string', t('Endpoint'), default: '');
    $token = $inputs->add('token', 'string', t('API key'), default: '');
    DefinitionMetadata::setSecret($token);
    $inputs->attach('backup', VaultBackupSurface::class)
      ->setLabel(t('Backup'));
  }

}
