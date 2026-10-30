<?php

declare(strict_types=1);

namespace Drupal\data_surface\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\Form\DataSurfaceOperationForm;
use Drupal\data_surface\ResourceBuild\NamesNothingException;
use Drupal\data_surface\ResourceBuild\OperationArguments;
use Drupal\data_surface\ResourceBuild\OperationRoute;
use Drupal\data_surface\ResourceBuild\ResourceRegistry;
use Drupal\data_surface\ResourceBuild\ResourcesInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

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
 * It asks in this order, and the first refusal is the answer:
 *   - what the route alone shows to be written wrong
 *     (OperationRoute::assertWritten()), refused saying why to any
 *     account, since it reads nothing of the URL;
 *   - which resource and operation the route names; a resource's id the
 *     path fills that no resource has is a URL that names nothing, to any
 *     account, since nothing is there to hold a permission for;
 *   - the operation's permission, from those two ids alone, so an account
 *     without it hears the permission's reason and nothing of what the
 *     URL names or whether it exists;
 *   - the context the route's parameters and values build
 *     (OperationRoute::fromRouteMatch()): a value of the URL that names
 *     nothing (NamesNothingException) is a page not found for an account
 *     the operation's permission lets in for what the parameters name, the
 *     permission's refusal for one it refuses, and a refusal saying
 *     nothing for one no permission sorts, since the operation declares
 *     none; any other refusal is the route's, saying why;
 *   - the access class, for that context.
 * A URL that names nothing is let through in the request's own dispatch,
 * for the form to answer with a 404, and refused when access is asked
 * about a link, so no link to a page not found is drawn
 * (OperationGate::namesNothing()). It is let through only to a route
 * whose controller answers it so (answersNotFound()): one served by any
 * other controller is refused with no reason, since that controller may
 * serve the URL without reading what its values name. The React page and
 * the API ask the same two answers of the same gate.
 *
 * Decision: see
 * docs/decisions.md#a-route-may-fill-or-fix-what-an-operation-asks-for.
 *
 * @see \Drupal\data_surface\ResourceBuild\OperationRoute
 * @see \Drupal\data_surface\Access\OperationGate
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
   * @param \Drupal\data_surface\Access\OperationGate $gate
   *   The answers every door serving an operation gives alike.
   */
  public function __construct(
    protected readonly ResourcesInterface $resources,
    protected readonly ResourceRegistry $registry,
    protected readonly OperationArguments $arguments,
    protected readonly OperationGate $gate,
  ) {}

  /**
   * Checks access to a route served by an operation.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   The request, which core hands in when it asks in the request's own
   *   dispatch; NULL when it asks about a link.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The answer for the operation's context. A route written wrong, or a
   *   value that names nothing, is refused or let through rather than
   *   thrown: an access question is never answered with an exception.
   */
  public function access(RouteMatchInterface $route_match, AccountInterface $account, ?Request $request = NULL): AccessResultInterface {
    $route = $route_match->getRouteObject();
    try {
      // The route alone, before anything of the URL is read, so a route
      // the rebuild never saw is refused in the rebuild's order.
      if ($route !== NULL) {
        OperationRoute::assertWritten($route, (string) $route_match->getRouteName());
      }
      [$resource, $operation] = OperationRoute::named($route_match, $this->registry);
    }
    catch (NamesNothingException) {
      return $this->gate->namesNothing($request, answersNotFound: static::answersNotFound($route));
    }
    catch (\InvalidArgumentException $e) {
      return AccessResult::forbidden($e->getMessage());
    }
    try {
      $permission = $this->gate->permission($resource, $operation, $account);
    }
    catch (\InvalidArgumentException $e) {
      return AccessResult::forbidden($e->getMessage());
    }
    if ($permission->isForbidden()) {
      return $permission;
    }
    try {
      $served = OperationRoute::fromRouteMatch($route_match, $this->registry, $this->arguments, $this->resources);
    }
    catch (NamesNothingException) {
      // A permission with a `%key` the ids could not fill is asked of the
      // parameters as the URL carries them.
      // Decision: see
      // docs/decisions.md#a-parameter-is-identity-the-caller-knows.
      if (!$permission->isAllowed()) {
        $permission = $this->gate->permission($resource, $operation, $account, $this->parameters($route_match, $resource, $operation));
      }
      if ($permission->isAllowed()) {
        return $this->gate->namesNothing($request, answersNotFound: static::answersNotFound($route));
      }
      // No permission lets the account in before the context is built.
      // One the permission refuses hears the permission's reason, the same
      // for every value. One no permission sorts, because the operation
      // declares none (a derived content entity operation, which entity
      // access answers once the thing is loaded) or none the URL fills,
      // hears nothing, not even why: that a value names nothing is told
      // only to an account a permission let in, so the page not found
      // needs a permission to ask first. For this request alone, since
      // what a value names can change, and written to no log, since anyone
      // may type the URL.
      return $permission->isForbidden() ? $permission : AccessResult::forbidden()->setCacheMaxAge(0);
    }
    catch (\InvalidArgumentException $e) {
      return AccessResult::forbidden($e->getMessage());
    }
    return $this->resources->access($served->resource, $served->context, $account);
  }

  /**
   * Answers whether a route's controller answers a URL naming nothing 404.
   *
   * The operation form, or a form extending it, whose route is `_form`,
   * and a React page served at a module's route
   * (OperationRoute::reactPage()): each reads the route's context
   * (OperationRoute::fromRouteMatch()) before it serves anything, and
   * answers a value that names nothing with a page not found. What any
   * other controller does with the URL is not known here.
   *
   * @param \Symfony\Component\Routing\Route|null $route
   *   The route.
   *
   * @return bool
   *   TRUE when its controller is one of those.
   */
  protected static function answersNotFound(?Route $route): bool {
    $form = $route?->getDefault('_form');
    return (is_string($form) && is_a(ltrim($form, '\\'), DataSurfaceOperationForm::class, TRUE)) || OperationRoute::reactPage($route);
  }

  /**
   * Reads the values the route carries for the operation's parameters.
   *
   * Each raw, as the path carries it, or a plain default's value, which a
   * link's route match holds among its parameters alone
   * (OperationRoute::parameters()).
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param string $resource
   *   The resource's class or id.
   * @param string $operation
   *   The operation id.
   *
   * @return array<string, scalar>
   *   The values, by parameter name.
   */
  protected function parameters(RouteMatchInterface $route_match, string $resource, string $operation): array {
    return OperationRoute::parameters($route_match, $this->registry->getOperation($this->registry->getDefinition($resource)->name(), $operation));
  }

}
