<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderInterface;
use Drupal\data_surface\ResourceBuild\ResourceCollectorPass;

/**
 * Adds the pass that discovers resources and structures in every module.
 */
final class DataSurfaceServiceProvider implements ServiceProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    $container->addCompilerPass(new ResourceCollectorPass());
  }

}
