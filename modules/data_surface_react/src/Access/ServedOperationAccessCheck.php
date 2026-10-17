<?php

declare(strict_types=1);

namespace Drupal\data_surface_react\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface_react\ServedOperations;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Gates the React page and the contract endpoints by their operation.
 *
 * The same answer OperationAccessCheck gives an operation's form route,
 * for a path that names the resource and the operation by id rather than
 * in route defaults: the operation's permission, then the resource's
 * access class, no opinion read as a refusal. A route opts in with the
 * requirement `_data_surface_react_access: 'TRUE'`.
 */
final class ServedOperationAccessCheck implements AccessInterface {

  /**
   * Constructs a ServedOperationAccessCheck.
   *
   * @param \Drupal\data_surface_react\ServedOperations $servedOperations
   *   What reads an operation off a request.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack, for a check made with no request handed in.
   */
  public function __construct(
    protected readonly ServedOperations $servedOperations,
    protected readonly RequestStack $requestStack,
  ) {}

  /**
   * Checks access to a page or endpoint serving one operation.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match, carrying the resource and operation ids.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   The request, carrying the operation's parameters; NULL when access
   *   is asked outside a dispatch, in which case the current request
   *   stands in.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The operation's answer. A resource, operation or parameter that
   *   names nothing is refused rather than thrown: an access question is
   *   never answered with an exception. A POST body that is not a JSON
   *   object is not an access question: the operation is read from the
   *   query string alone, and if that is allowed the controller refuses
   *   the body with a 400.
   */
  public function access(RouteMatchInterface $route_match, AccountInterface $account, ?Request $request = NULL): AccessResultInterface {
    // Access is also asked outside a request's own dispatch: a link, a
    // breadcrumb, a local task. The argument resolver then hands in no
    // request, so the current one stands in for it.
    $request ??= $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      return AccessResult::forbidden('No request to read the operation from.');
    }
    $surface = (string) $route_match->getRawParameter('surface');
    $operation = (string) $route_match->getRawParameter('situation');
    try {
      try {
        $served = $this->servedOperations->fromRequest($surface, $operation, $request);
      }
      catch (BadRequestHttpException) {
        $served = $this->servedOperations->fromRequest($surface, $operation, $request, FALSE);
      }
    }
    catch (\InvalidArgumentException $e) {
      return AccessResult::forbidden($e->getMessage())->setCacheMaxAge(0);
    }
    return $this->servedOperations->access($served, $account);
  }

}
