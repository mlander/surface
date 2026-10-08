<?php

declare(strict_types=1);

namespace Drupal\data_surface\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\SurfaceBuild\SituationArguments;
use Drupal\data_surface\SurfaceBuild\SituationRoute;
use Drupal\data_surface\SurfaceBuild\SurfaceRegistry;
use Drupal\data_surface\SurfaceBuild\SurfacesInterface;

/**
 * Gates a route by the situation it is served by.
 *
 * The situation's permission first, then the surface's access class:
 * exactly SurfacesInterface::access() for the context the route's
 * parameters build, so the route, the form it serves, the operation link
 * that points at it and the tool for the same situation all read one
 * answer. A route opts in with the requirement
 * `_data_surface_situation_access: 'TRUE'` beside the defaults that name
 * the surface and the situation.
 *
 * @see \Drupal\data_surface\SurfaceBuild\SituationRoute
 */
final class SituationAccessCheck implements AccessInterface {

  /**
   * Constructs a SituationAccessCheck.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfacesInterface $surfaces
   *   The build step, which answers access.
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceRegistry $registry
   *   What discovery found.
   * @param \Drupal\data_surface\SurfaceBuild\SituationArguments $arguments
   *   What maps the route's parameters onto the situation's.
   */
  public function __construct(
    protected readonly SurfacesInterface $surfaces,
    protected readonly SurfaceRegistry $registry,
    protected readonly SituationArguments $arguments,
  ) {}

  /**
   * Checks access to a route served by a situation.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The surface's answer for the situation's context. A parameter that
   *   names nothing is refused rather than thrown: an access question is
   *   never answered with an exception.
   */
  public function access(RouteMatchInterface $route_match, AccountInterface $account): AccessResultInterface {
    try {
      $route = SituationRoute::fromRouteMatch($route_match, $this->registry, $this->arguments, $this->surfaces);
    }
    catch (\InvalidArgumentException $e) {
      return AccessResult::forbidden($e->getMessage());
    }
    return $this->surfaces->access($route->surface, $route->context, $account);
  }

}
