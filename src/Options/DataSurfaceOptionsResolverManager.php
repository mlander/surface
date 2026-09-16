<?php

declare(strict_types=1);

namespace Drupal\data_surface\Options;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\data_surface\Attribute\DataSurfaceOptionsResolver;

/**
 * Manages data surface options resolver plugins.
 */
final class DataSurfaceOptionsResolverManager extends DefaultPluginManager {

  /**
   * Constructs a DataSurfaceOptionsResolverManager.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct(
      'Plugin/DataSurfaceOptionsResolver',
      $namespaces,
      $module_handler,
      DataSurfaceOptionsResolverInterface::class,
      DataSurfaceOptionsResolver::class,
    );
    $this->alterInfo('data_surface_options_resolver_info');
    $this->setCacheBackend($cache_backend, 'data_surface_options_resolver_plugins');
  }

}
