<?php

declare(strict_types=1);

namespace Drupal\surface\Options;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\surface\Attribute\SurfaceOptionsResolver;

/**
 * Manages data surface options resolver plugins.
 */
final class SurfaceOptionsResolverManager extends DefaultPluginManager {

  /**
   * Constructs a SurfaceOptionsResolverManager.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct(
      'Plugin/SurfaceOptionsResolver',
      $namespaces,
      $module_handler,
      SurfaceOptionsResolverInterface::class,
      SurfaceOptionsResolver::class,
    );
    $this->alterInfo('surface_options_resolver_info');
    $this->setCacheBackend($cache_backend, 'surface_options_resolver_plugins');
  }

}
