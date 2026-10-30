<?php

declare(strict_types=1);

namespace Drupal\surface\Access;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\RoutingEvents;
use Drupal\surface\ResourceBuild\OperationRoute;
use Symfony\Component\Routing\RouteCollection;

/**
 * Gates every route served by an operation with the operation's access.
 *
 * A route whose defaults name a resource (`_surface_resource`) and an
 * operation (`_surface_operation`) is served by that operation, so
 * it asks what the operation asks: the requirement
 * `_surface_operation_access: 'TRUE'` is added to it here, and a
 * routing file need not say it. One that says it already is left as it
 * is.
 *
 * Such a route written wrong in a way the route alone shows is refused
 * here, with an \InvalidArgumentException naming the route and the fix,
 * as core refuses a reserved variable name in a path
 * (SpecialAttributesRouteSubscriber): a `{name}` in the resource's id or
 * a reference that is no variable of the path, or one nothing bounds; one
 * key both fixed and filled; a query map no link can use; keys fixed on a
 * React page (OperationRoute::assertWritten()). The refusal stops the
 * router's rebuild, so no route of the site is written until the routing
 * file is corrected. Everything else a route may get wrong needs the
 * operation it serves or the resource built, and is refused when the
 * route is matched.
 *
 * Decision: see
 * docs/decisions.md#the-surface-was-counted-and-cut.
 *
 * @see \Drupal\surface\Access\OperationAccessCheck
 */
final class OperationRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After core's ParamConverterSubscriber (-220), which writes the
    // converter that loads each parameter's type
    // (`options.parameters.<name>.converter`): a converter is one of the
    // two things that bound a variable of the path, which the checks read.
    return [RoutingEvents::ALTER => ['onAlterRoutes', -230]];
  }

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    foreach ($collection as $name => $route) {
      if (!$route->hasDefault(OperationRoute::RESOURCE) || !$route->hasDefault(OperationRoute::OPERATION)) {
        continue;
      }
      OperationRoute::assertWritten($route, (string) $name);
      if (!$route->hasRequirement(OperationRoute::ACCESS)) {
        $route->setRequirement(OperationRoute::ACCESS, 'TRUE');
      }
    }
  }

}
