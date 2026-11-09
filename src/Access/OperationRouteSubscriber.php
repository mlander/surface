<?php

declare(strict_types=1);

namespace Drupal\surface\Access;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\RoutingEvents;
use Drupal\surface\Flow\FlowRegistry;
use Drupal\surface\Form\CompiledFlow;
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
 * React page (OperationRoute::assertWritten()); a flow (`_surface_flow`)
 * that is neither a flow class's id nor a map of steps written as
 * CompiledFlow::assertWritten() says, or that is declared on a route
 * naming no operation or on one whose `_form` is not the operation form;
 * a flow class written wrong, or whose first step is another operation
 * than the one the route writes (FlowRegistry::onRoute()).
 *
 * A flow id that no class declares, or that two classes declare, depends
 * on which modules are installed, not on the route: such a route is not
 * refused but closed, with the requirement `_access: 'FALSE'`, and every
 * other route is written. Its match would say why
 * (FlowRegistry::unnamed()), were the route open.
 *
 * A route that names a flow class and no resource or operation takes
 * them from the flow's first step, written into its defaults here, before
 * anything else reads them: the access requirement is added to it as to
 * any operation route, and every reader of the route's operation reads
 * it as on any other. So the flow class's defineSteps() runs here, and
 * declares only.
 *
 * The refusal stops the router's rebuild, so no route of the site is
 * written until the routing file is corrected. Everything else a route
 * may get wrong needs the operation it serves or the resource built, and
 * is refused when the route is matched: for a flow, an operation a flow
 * cannot stage (a delete, a read-only operation, or one a stage does not
 * apply to), a group the operation does not have, a key the operation
 * does not have, a step that draws no key, a step order the refinements
 * contradict, and a create's required key drawn after the first step
 * (CompiledFlow::refusal()).
 *
 * Decision: see
 * docs/decisions.md#a-step-order-is-checked-against-the-refiner-graph-and-a-creates-required-keys-at-match-time.
 *
 * Decision: see
 * docs/decisions.md#the-surface-was-counted-and-cut.
 *
 * @see \Drupal\surface\Access\OperationAccessCheck
 */
final class OperationRouteSubscriber extends RouteSubscriberBase {

  /**
   * Constructs an OperationRouteSubscriber.
   *
   * @param \Drupal\surface\Flow\FlowRegistry $flows
   *   The flow classes, which name the operation of a route that names
   *   one of them.
   */
  public function __construct(
    protected readonly FlowRegistry $flows,
  ) {}

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
      if (is_string($route->getDefault(CompiledFlow::ROUTE)) && $route->getDefault(CompiledFlow::ROUTE) !== '') {
        if ($this->flows->unnamed($route->getDefault(CompiledFlow::ROUTE), (string) $name) !== NULL) {
          // What the id names depends on what is installed, not on the
          // route: the route is closed, and the rest are written.
          $route->setRequirement('_access', 'FALSE');
          continue;
        }
        // A flow class's first step names the route's operation.
        $this->flows->onRoute($route, (string) $name, TRUE);
      }
      if (!$route->hasDefault(OperationRoute::RESOURCE) || !$route->hasDefault(OperationRoute::OPERATION)) {
        // A flow draws an operation's form, so a route declaring one
        // names the operation.
        if ($route->hasDefault(CompiledFlow::ROUTE)) {
          CompiledFlow::assertWritten($route, (string) $name);
        }
        continue;
      }
      OperationRoute::assertWritten($route, (string) $name);
      if (!$route->hasRequirement(OperationRoute::ACCESS)) {
        $route->setRequirement(OperationRoute::ACCESS, 'TRUE');
      }
    }
  }

}
