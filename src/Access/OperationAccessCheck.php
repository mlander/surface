<?php

declare(strict_types=1);

namespace Drupal\data_surface\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\ResourceBuild\OperationArguments;
use Drupal\data_surface\ResourceBuild\OperationRoute;
use Drupal\data_surface\ResourceBuild\ResourceRegistry;
use Drupal\data_surface\ResourceBuild\ResourcesInterface;

/**
 * Gates a route by the operation it is served by.
 *
 * The operation's permission first, then the access class the resource
 * or structure names: exactly ResourcesInterface::access() for the
 * context the route's parameters build, so the route, the form it
 * serves, the operation link that points at it and the tool for the same
 * operation all read one answer. Every route whose defaults name the
 * resource and the operation has it: OperationRouteSubscriber adds the
 * requirement `_data_surface_operation_access: 'TRUE'`, which a routing
 * file may still say itself.
 *
 * @see \Drupal\data_surface\ResourceBuild\OperationRoute
 */
final class OperationAccessCheck implements AccessInterface {

  /**
   * Constructs an OperationAccessCheck.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourcesInterface $resources
   *   The build step, which answers access.
   * @param \Drupal\data_surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found.
   * @param \Drupal\data_surface\ResourceBuild\OperationArguments $arguments
   *   What maps the route's parameters onto the operation's.
   */
  public function __construct(
    protected readonly ResourcesInterface $resources,
    protected readonly ResourceRegistry $registry,
    protected readonly OperationArguments $arguments,
  ) {}

  /**
   * Checks access to a route served by an operation.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The answer for the operation's context. A parameter that names
   *   nothing is refused rather than thrown: an access question is never
   *   answered with an exception.
   */
  public function access(RouteMatchInterface $route_match, AccountInterface $account): AccessResultInterface {
    try {
      $route = OperationRoute::fromRouteMatch($route_match, $this->registry, $this->arguments, $this->resources);
    }
    catch (\InvalidArgumentException $e) {
      return AccessResult::forbidden($e->getMessage());
    }
    return $this->resources->access($route->resource, $route->context, $account);
  }

}
