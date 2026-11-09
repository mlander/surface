<?php

declare(strict_types=1);

namespace Drupal\surface\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\surface\Form\Flow;
use Drupal\surface\ResourceBuild\NamesNothingException;
use Drupal\surface\ResourceBuild\OperationArguments;
use Drupal\surface\ResourceBuild\OperationRoute;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Drupal\surface\ResourceBuild\ResourcesInterface;
use Drupal\surface\Staging\StageStoreInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
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
 * requirement `_surface_operation_access: 'TRUE'`, which a routing
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
 *   - the stage the query names, `?stage=<id>`
 *     (OperationRoute::stageRequested()): refused on a page that is no
 *     operation form, refused saying `does_not_apply` where a stage does
 *     not apply to the operation (Stageable), a page not found as below
 *     for an id that names no stage, and the stage's own access, which
 *     only its owner holds. Access asked about a link reads the current
 *     request's query only for a link to the current route; a link to
 *     another route is judged with no stage, at its first step, so a
 *     link carrying another account's stage is drawn, and answers 403
 *     when followed;
 *   - the context the route's parameters and values build
 *     (OperationRoute::fromRouteMatch()), through that stage, so a local
 *     id in the path names what the stage creates: a value of the URL
 *     that names nothing (NamesNothingException) is a page not found for
 *     an account the operation's permission lets in for what the
 *     parameters name, the permission's refusal for one it refuses, and a
 *     refusal saying nothing for one no permission sorts, since the
 *     operation declares none; any other refusal is the route's, saying
 *     why;
 *   - on a route that declares a flow (Flow), what the flow needs of the
 *     built resource (Flow::refusal()) and of each step over another
 *     resource (Flow::stepRefusal()), refused saying why; a step the
 *     flow does not have, a page not found; a step after the first with
 *     no stage, refused; at a step over another resource, its
 *     operation's permission, then that resource's operation
 *     (Flow::servedAt()), whose arguments naming nothing is a page not
 *     found;
 *   - the access class, for that context, and with the stage's answer;
 *     at a step over another resource, that resource's access too, so
 *     the route's operation gates every page and the step adds its own.
 * The answer varies by `stage` and `step` in the query, and a refusal
 * at a flow's page is kept for this request alone. A URL that
 * names nothing is let through in the request's own dispatch, for the
 * form to answer with a 404, and refused when access is asked about a
 * link, so no link to a page not found is drawn
 * (OperationGate::namesNothing()). It is let through only to a route
 * whose controller answers it so (answersNotFound()): one served by any
 * other controller is refused with no reason, since that controller may
 * serve the URL without reading what its values name. The React page and
 * the API ask the same two answers of the same gate.
 *
 * Decision: see
 * docs/decisions.md#a-route-may-fill-or-fix-what-an-operation-asks-for.
 *
 * @see \Drupal\surface\ResourceBuild\OperationRoute
 * @see \Drupal\surface\Access\OperationGate
 */
final class OperationAccessCheck implements AccessInterface {

  /**
   * Constructs an OperationAccessCheck.
   *
   * @param \Drupal\surface\ResourceBuild\ResourcesInterface $resources
   *   The build step, which answers access.
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found.
   * @param \Drupal\surface\ResourceBuild\OperationArguments $arguments
   *   What maps the route's parameters onto the operation's.
   * @param \Drupal\surface\Access\OperationGate $gate
   *   The answers every door serving an operation gives alike.
   * @param \Drupal\surface\Staging\StageStoreInterface $stages
   *   The stages, which name the stage a page's query names.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack, whose current request stands in for the one
   *   core hands in no request for: access asked about a link.
   */
  public function __construct(
    protected readonly ResourcesInterface $resources,
    protected readonly ResourceRegistry $registry,
    protected readonly OperationArguments $arguments,
    protected readonly OperationGate $gate,
    protected readonly StageStoreInterface $stages,
    protected readonly RequestStack $requestStack,
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
    // The stage before the thing, as the HTTP door asks its header: a
    // link is asked about with no request, so the current one is read
    // when the link is to the page itself, and the link and the page
    // agree. A link to any other route is judged as with no stage, at
    // its first step: the current page's query is not the link's.
    // Decision: see
    // docs/decisions.md#one-rule-says-where-a-stage-applies-at-every-door.
    $query = $request ?? $this->linkQuery($route_match);
    try {
      $staged = $query === NULL ? NULL : OperationRoute::stageRequested($route_match, $query, $this->stages, $this->resources, $this->registry, $account);
    }
    catch (NamesNothingException) {
      return $this->gate->namesNothing($request, answersNotFound: static::answersNotFound($route));
    }
    catch (\InvalidArgumentException $e) {
      return AccessResult::forbidden($e->getMessage())->setCacheMaxAge(0);
    }
    if ($staged !== NULL && !$staged[1]->isAllowed()) {
      return $staged[1];
    }
    try {
      $served = OperationRoute::fromRouteMatch($route_match, $this->registry, $this->arguments, $this->resources, $staged[0] ?? NULL);
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
    $flow = Flow::onRoute($route, (string) $route_match->getRouteName())?->resolved($this->registry);
    $at = $served;
    // Every answer at a flow's page varies by the step and the stage.
    $varies = (new CacheableMetadata())->addCacheContexts([
      'url.query_args:' . Flow::STAGE,
      'url.query_args:' . Flow::STEP,
    ]);
    if ($flow !== NULL) {
      $refusal = $flow->refusedAt($served, $this->resources, $this->registry, (string) $route_match->getRouteName(), $query, $staged !== NULL);
      if ($refusal === FALSE) {
        return $this->gate->namesNothing($request, answersNotFound: static::answersNotFound($route));
      }
      if ($refusal !== NULL) {
        return AccessResult::forbidden($refusal)->addCacheableDependency($varies)->setCacheMaxAge(0);
      }
      // A step over another resource adds its operation's permission,
      // asked before that operation is built, so an account without it
      // hears the permission's reason and nothing of what the step names.
      $step = $query === NULL ? 1 : $flow->step($query);
      $over = is_int($step) ? $flow->over($step) : NULL;
      if ($over !== NULL) {
        $permission = $this->gate->permission($over['resource'], $over['operation'], $account, $over['arguments'] ?? []);
        if ($permission->isForbidden()) {
          $reason = $permission instanceof AccessResultReasonInterface ? $permission->getReason() : NULL;
          return AccessResult::forbidden($reason)->addCacheableDependency($permission)->addCacheableDependency($varies)->setCacheMaxAge(0);
        }
      }
      // A step over another resource is that resource's operation.
      try {
        $at = $flow->servedAt($served, $this->resources, $this->registry, (string) $route_match->getRouteName(), $query);
      }
      catch (NamesNothingException) {
        return $this->gate->namesNothing($request, answersNotFound: static::answersNotFound($route));
      }
      catch (\InvalidArgumentException $e) {
        return AccessResult::forbidden($e->getMessage())->addCacheableDependency($varies)->setCacheMaxAge(0);
      }
    }
    // The route's operation gates every page of a flow; a step over
    // another resource adds its own permission and access.
    $access = AccessResult::allowed()
      ->addCacheableDependency($varies)
      ->andIf($this->resources->access($served->resource, $served->context, $account));
    if ($at !== $served) {
      $access = $access->andIf($this->resources->access($at->resource, $at->context, $account));
    }
    // Decision: see
    // docs/decisions.md#a-stage-input-is-a-door-argument-handed-to-storage-never-context.
    return $staged === NULL ? $access : $access->andIf($staged[1]);
  }

  /**
   * Gets the request whose query a link to a route is judged by.
   *
   * The current request, when the link is to the current route: a page
   * that names a stage draws its own links under that stage. NULL for a
   * link to any other route.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The link's route match.
   *
   * @return \Symfony\Component\HttpFoundation\Request|null
   *   The request, or NULL.
   */
  protected function linkQuery(RouteMatchInterface $route_match): ?Request {
    $current = $this->requestStack->getCurrentRequest();
    $name = $current?->attributes->get(RouteObjectInterface::ROUTE_NAME);
    return $name !== NULL && $name === $route_match->getRouteName() ? $current : NULL;
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
    return OperationRoute::operationForm($route) || OperationRoute::reactPage($route);
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
