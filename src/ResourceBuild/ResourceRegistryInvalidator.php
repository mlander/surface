<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tells the registry when what a deriver read from has changed.
 *
 * The discovery bin drops its entry by tag on its own; what this
 * reaches is the copy the registry already holds for the rest of the
 * request, so a field saved and a resource built in one request (a test,
 * a tool that adds a field and then reads the bundle) see the field.
 * It asks the container for the registry only when the registry has
 * been built already: an invalidation is no reason to discover.
 *
 * @internal
 */
final class ResourceRegistryInvalidator implements CacheTagsInvalidatorInterface {

  /**
   * Constructs a ResourceRegistryInvalidator.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container, asked whether the registry is built.
   */
  public function __construct(
    protected readonly ContainerInterface $container,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function invalidateTags(array $tags): void {
    if ($this->container->initialized('data_surface.resource_registry')) {
      $registry = $this->container->get('data_surface.resource_registry');
      assert($registry instanceof ResourceRegistry);
      $registry->invalidateTags($tags);
    }
  }

}
