<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel\Fixture;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Pipeline\SurfaceStorageInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\ResourcesInterface;

/**
 * The build step, counting what it is asked to build.
 *
 * Set in a kernel test's container in place of surface.resources,
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
   * @param \Drupal\surface\ResourceBuild\ResourcesInterface $inner
   *   The real build step.
   */
  public function __construct(
    protected readonly ResourcesInterface $inner,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function build(string $resource, OperationContext $context): SurfaceInterface {
    $this->builds[] = $resource;
    return $this->inner->build($resource, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function buildStatic(string $resource, OperationContext $context): SurfaceInterface {
    $this->builds[] = $resource;
    return $this->inner->buildStatic($resource, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function isRuntimeAtTheTop(string $resource, OperationContext $context, AccountInterface $account): bool {
    return $this->inner->isRuntimeAtTheTop($resource, $context, $account);
  }

  /**
   * {@inheritdoc}
   */
  public function buildDeclared(string $resource, OperationContext $context): SurfaceInterface {
    $this->builds[] = $resource;
    return $this->inner->buildDeclared($resource, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function holdDeclared(string $resource, OperationContext $context): SurfaceInterface {
    $this->builds[] = $resource;
    return $this->inner->holdDeclared($resource, $context);
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
  public function buildOperation(string $resource, string $operation, array $arguments = []): SurfaceInterface {
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
  public function permissionAccess(string $resource, string $operation, ?AccountInterface $account = NULL, ?OperationContext $context = NULL): ?AccessResultInterface {
    return $this->inner->permissionAccess($resource, $operation, $account, $context);
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
  public function storage(string $resource, OperationContext $context, ?SurfaceInterface $built = NULL, ?AccountInterface $account = NULL): SurfaceStorageInterface {
    return $this->inner->storage($resource, $context, $built, $account);
  }

  /**
   * {@inheritdoc}
   */
  public function resolved(string $resource, string $operation = 'configure', array $arguments = []): array {
    return $this->inner->resolved($resource, $operation, $arguments);
  }

}
