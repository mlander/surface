<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\surface\Options\SurfaceOptions;
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
    if ($this->container->initialized('surface.resource_registry')) {
      $registry = $this->container->get('surface.resource_registry');
      assert($registry instanceof ResourceRegistry);
      $read_again = $registry->invalidateTags($tags);
    }
    // The builds this request kept: those that read a tag, or all of them
    // when what discovery read changed under them.
    // Decision: see docs/decisions.md#a-build-is-not-cached-across-requests.
    if ($this->container->initialized('surface.resources')) {
      $resources = $this->container->get('surface.resources');
      if ($resources instanceof Resources) {
        $read_again ? $resources->forgetBuilds() : $resources->invalidateTags($tags);
      }
    }
    // The option lists read from what changed, which a build kept for the
    // request would otherwise be answered again.
    if ($this->container->initialized('surface.options')) {
      $options = $this->container->get('surface.options');
      if ($options instanceof SurfaceOptions) {
        $options->invalidateTags($tags);
      }
    }
  }

}
