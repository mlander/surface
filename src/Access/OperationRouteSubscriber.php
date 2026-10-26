<?php

declare(strict_types=1);

namespace Drupal\data_surface\Access;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\data_surface\ResourceBuild\OperationRoute;
use Symfony\Component\Routing\RouteCollection;

/**
 * Gates every route served by an operation with the operation's access.
 *
 * A route whose defaults name a resource (`_data_surface_resource`) and an
 * operation (`_data_surface_operation`) is served by that operation, so
 * it asks what the operation asks: the requirement
 * `_data_surface_operation_access: 'TRUE'` is added to it here, and a
 * routing file need not say it. One that says it already is left as it
 * is.
 *
 * Decision: see
 * docs/decisions.md#the-surface-was-counted-and-cut.
 *
 * @see \Drupal\data_surface\Access\OperationAccessCheck
 */
final class OperationRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    foreach ($collection as $route) {
      if ($route->hasDefault(OperationRoute::RESOURCE) && $route->hasDefault(OperationRoute::OPERATION) && !$route->hasRequirement(OperationRoute::ACCESS)) {
        $route->setRequirement(OperationRoute::ACCESS, 'TRUE');
      }
    }
  }

}
