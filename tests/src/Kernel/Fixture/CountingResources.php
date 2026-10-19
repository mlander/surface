<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel\Fixture;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceStorageInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\ResourceBuild\ResourcesInterface;

/**
 * The build step, counting what it is asked to build.
 *
 * Set in a kernel test's container in place of data_surface.resources,
 * so a test can say how often a host built its resource or structure.
 */
final class CountingResources implements ResourcesInterface {

  /**
   * The resource or structure classes or ids build() was asked for, in order.
   *
   * @var string[]
   */
  public array $builds = [];

  /**
   * Constructs a CountingResources.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourcesInterface $inner
   *   The real build step.
   */
  public function __construct(
    protected readonly ResourcesInterface $inner,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function build(string $resource, OperationContext $context): DataSurfaceInterface {
    $this->builds[] = $resource;
    return $this->inner->build($resource, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function buildStatic(string $resource, OperationContext $context): DataSurfaceInterface {
    $this->builds[] = $resource;
    return $this->inner->buildStatic($resource, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function operation(string $resource, string $operation, array $arguments = []): OperationContext {
    return $this->inner->operation($resource, $operation, $arguments);
  }

  /**
   * {@inheritdoc}
   */
  public function buildOperation(string $resource, string $operation, array $arguments = []): DataSurfaceInterface {
    return $this->build($resource, $this->operation($resource, $operation, $arguments));
  }

  /**
   * {@inheritdoc}
   */
  public function access(string $resource, OperationContext $context, ?AccountInterface $account = NULL): AccessResultInterface {
    return $this->inner->access($resource, $context, $account);
  }

  /**
   * {@inheritdoc}
   */
  public function defaults(string $resource): array {
    return $this->inner->defaults($resource);
  }

  /**
   * {@inheritdoc}
   */
  public function storage(string $resource, OperationContext $context, ?DataSurfaceInterface $built = NULL): DataSurfaceStorageInterface {
    return $this->inner->storage($resource, $context, $built);
  }

}
