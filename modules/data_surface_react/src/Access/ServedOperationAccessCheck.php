<?php

declare(strict_types=1);

namespace Drupal\data_surface_react\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\DataSurfaceAccess;
use Drupal\data_surface_react\ServedOperations;
use Psr\Log\LoggerInterface;
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
 *
 * The operation's permission is asked first, from the two ids alone,
 * before the request's parameters are read, the subject loaded or
 * anything built, so an account without it learns nothing of what the
 * parameters name. Every refusal after that gives one fixed reason,
 * whether a parameter named nothing or the access class refused what it
 * named, so the reason never says whether an id exists, and the real one
 * goes to the log; only an account holding the operation's permission
 * for what the parameters name hears the access class's reason.
 *
 * Decision: see
 * docs/decisions.md#every-door-projects-what-the-read-tool-projects.
 */
final class ServedOperationAccessCheck implements AccessInterface {

  /**
   * Constructs a ServedOperationAccessCheck.
   *
   * @param \Drupal\data_surface_react\ServedOperations $servedOperations
   *   What reads an operation off a request.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack, for a check made with no request handed in.
   * @param \Psr\Log\LoggerInterface|null $logger
   *   Where the reason behind a refusal is written; NULL writes none.
   */
  public function __construct(
    protected readonly ServedOperations $servedOperations,
    protected readonly RequestStack $requestStack,
    protected readonly ?LoggerInterface $logger = NULL,
  ) {}

  /**
   * The one reason every refusal past the permission gives.
   */
  public const REFUSED = 'The operation cannot be served to this account with these parameters.';

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
    $resource = (string) $route_match->getRawParameter('resource');
    $operation = (string) $route_match->getRawParameter('operation');
    try {
      $permission = $this->servedOperations->permissionAccess($resource, $operation, $account);
    }
    catch (\InvalidArgumentException $e) {
      return $this->refused($e->getMessage());
    }
    if ($permission !== NULL && !$permission->isAllowed()) {
      // Which permission it takes is the operation's, the same for every
      // parameter, so saying it says nothing of what one names.
      return DataSurfaceAccess::decisive($permission, $permission instanceof AccessResultReasonInterface ? $permission->getReason() : NULL);
    }
    try {
      try {
        $served = $this->servedOperations->fromRequest($resource, $operation, $request);
      }
      catch (BadRequestHttpException) {
        $served = $this->servedOperations->fromRequest($resource, $operation, $request, FALSE);
      }
    }
    catch (\InvalidArgumentException $e) {
      return $this->refused($e->getMessage());
    }
    $access = $this->servedOperations->access($served, $account);
    // An account that holds the operation's permission for what the
    // parameters name may hear why the rest refused; any other learns
    // nothing of what they name.
    if ($access->isAllowed() || ($this->servedOperations->permissionAccess($resource, $operation, $account, $served->context)?->isAllowed() ?? FALSE)) {
      return $access;
    }
    return $this->refused($access instanceof AccessResultReasonInterface ? (string) $access->getReason() : '')->addCacheableDependency($access);
  }

  /**
   * Refuses with the one fixed reason, logging the real one.
   *
   * @param string $reason
   *   Why, for the log.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   Forbidden, cached for this request only.
   */
  protected function refused(string $reason): AccessResult {
    if ($reason !== '') {
      $this->logger?->info('A served operation was refused: @reason', ['@reason' => $reason]);
    }
    return AccessResult::forbidden(self::REFUSED)->setCacheMaxAge(0);
  }

}
