<?php

declare(strict_types=1);

namespace Drupal\surface;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderInterface;
use Drupal\surface\ResourceBuild\ResourceCollectorPass;

/**
 * Adds the pass that discovers resources and structures in every module.
 */
final class SurfaceServiceProvider implements ServiceProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    $container->addCompilerPass(new ResourceCollectorPass());
  }

}
