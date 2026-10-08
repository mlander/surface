<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel\Fixture;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\SurfaceBuild\SurfacesInterface;

/**
 * The build step, counting what it is asked to build.
 *
 * Set in a kernel test's container in place of data_surface.surfaces,
 * so a test can say how often a host built its surface.
 */
final class CountingSurfaces implements SurfacesInterface {

  /**
   * The surface classes or ids build() was asked for, in order.
   *
   * @var string[]
   */
  public array $builds = [];

  /**
   * Constructs a CountingSurfaces.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfacesInterface $inner
   *   The real build step.
   */
  public function __construct(
    protected readonly SurfacesInterface $inner,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function build(string $surface, SurfaceContext $context): DataSurfaceInterface {
    $this->builds[] = $surface;
    return $this->inner->build($surface, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function situation(string $surface, string $situation, array $arguments = []): SurfaceContext {
    return $this->inner->situation($surface, $situation, $arguments);
  }

  /**
   * {@inheritdoc}
   */
  public function buildSituation(string $surface, string $situation, array $arguments = []): DataSurfaceInterface {
    return $this->build($surface, $this->situation($surface, $situation, $arguments));
  }

  /**
   * {@inheritdoc}
   */
  public function access(string $surface, SurfaceContext $context, ?AccountInterface $account = NULL): AccessResultInterface {
    return $this->inner->access($surface, $context, $account);
  }

  /**
   * {@inheritdoc}
   */
  public function defaults(string $surface): array {
    return $this->inner->defaults($surface);
  }

  /**
   * {@inheritdoc}
   */
  public function target(string $surface, SurfaceContext $context, ?DataSurfaceInterface $built = NULL): DataSurfaceTargetInterface {
    return $this->inner->target($surface, $context, $built);
  }

}
