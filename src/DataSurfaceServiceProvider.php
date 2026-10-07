<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderInterface;
use Drupal\data_surface\SurfaceBuild\SurfaceCollectorPass;

/**
 * Adds the pass that discovers surfaces in every enabled module.
 */
final class DataSurfaceServiceProvider implements ServiceProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    $container->addCompilerPass(new SurfaceCollectorPass());
  }

}
