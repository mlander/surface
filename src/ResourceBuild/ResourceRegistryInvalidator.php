<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tells the registry and the build step when what they read has changed.
 *
 * The discovery bin drops its entries by tag on its own; what this
 * reaches is the copy the registry already holds for the rest of the
 * request, and the sealed builds the build step keeps for it, so a field
 * saved and a resource built in one request (a test, a tool that adds a
 * field and then reads the bundle) see the field. It asks the container
 * for either only when it has been built already: an invalidation is no
 * reason to discover.
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
    $read_again = FALSE;
    if ($this->container->initialized('data_surface.resource_registry')) {
      $registry = $this->container->get('data_surface.resource_registry');
      assert($registry instanceof ResourceRegistry);
      $read_again = $registry->invalidateTags($tags);
    }
    // The builds this request kept: those that read a tag, or all of them
    // when what discovery read changed under them.
    // Decision: see docs/decisions.md#a-build-is-not-cached-across-requests.
    if ($this->container->initialized('data_surface.resources')) {
      $resources = $this->container->get('data_surface.resources');
      if ($resources instanceof Resources) {
        $read_again ? $resources->forgetBuilds() : $resources->invalidateTags($tags);
      }
    }
  }

}
