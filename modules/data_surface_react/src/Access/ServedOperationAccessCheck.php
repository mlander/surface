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
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface_react\ProblemException;
use Drupal\data_surface_react\ServedOperations;
use Drupal\data_surface_react\ServedPaths;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Gates the React page and the API by the operation they serve.
 *
 * The same answer OperationAccessCheck gives an operation's form route,
 * for a path that names the resource and the operation by id (the page)
 * or the resource and the method that implies the operation (the API,
 * read by ServedPaths), rather than in route defaults: the operation's
 * permission, then the resource's access class, no opinion read as a
 * refusal. A route opts in with the requirement
 * `_data_surface_react_access: 'TRUE'`.
 *
 * The operation's permission is asked first, from the two ids alone,
 * before the request's parameters are read, the subject loaded or
 * anything built, so an account without it learns nothing of what the
 * parameters name. Every refusal after that gives one fixed reason,
 * whether a parameter named nothing or the access class refused what it
 * named, so the reason never says whether an id exists, and the real one
 * goes to the log; only an account holding the operation's permission
 * for what the parameters name hears the access class's reason, and on
 * the API, that an id names nothing (404).
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
   * @param \Drupal\data_surface_react\ServedPaths $servedPaths
   *   What reads an API path.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack, for a check made with no request handed in.
   * @param \Psr\Log\LoggerInterface|null $logger
   *   Where the reason behind a refusal is written; NULL writes none.
   */
  public function __construct(
    protected readonly ServedOperations $servedOperations,
    protected readonly ServedPaths $servedPaths,
    protected readonly RequestStack $requestStack,
    protected readonly ?LoggerInterface $logger = NULL,
  ) {}

  /**
   * The one reason every refusal past the permission gives.
   */
  public const REFUSED = 'The operation cannot be served to this account with these parameters.';

  /**
   * Checks access to the page or an API path serving one operation.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match: the page's carries the resource and operation ids,
   *   the API's the resource and the segments its path names.
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
   *   never answered with an exception. An API path that names nothing,
   *   or is gone, is let through for the controller to answer with its
   *   problem (404, 405, 410); what a path names is the code's, never a
   *   stored value. An id that names nothing is a 404 for an account that
   *   holds the operation's permission, and the fixed refusal for any
   *   other.
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
    $operation = $route_match->getRawParameter('operation');
    $parameters = NULL;
    if ($operation === NULL) {
      // The API: the operation is what the path and the method name.
      $segments = array_map(static fn (string $name): string => (string) $route_match->getRawParameter($name), ServedPaths::SEGMENTS);
      try {
        $target = $this->servedPaths->target($request, $resource, $segments);
      }
      catch (ProblemException) {
        // Nothing is served, so nothing is disclosed: the controller
        // answers the problem.
        return AccessResult::allowed()->setCacheMaxAge(0);
      }
      $resource = $target->definition->id;
      $operation = $target->operation->id;
      $parameters = $target->parameters;
    }
    $operation = (string) $operation;
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
    // The context alone, built from the parameters: access is answered
    // from it, and nothing is built for a response the page cache answers.
    // Decision: see docs/decisions.md#a-build-is-not-cached-across-requests.
    try {
      $context = $parameters === NULL
        ? $this->servedOperations->contextFromRequest($resource, $operation, $request)
        : $this->servedOperations->context($resource, $operation, $parameters);
    }
    catch (\InvalidArgumentException $e) {
      // A permission that names a parameter (`administer %entity_type
      // fields`) is read off the parameters as sent.
      // Decision: see
      // docs/decisions.md#a-parameter-is-identity-the-caller-knows.
      if ($parameters !== NULL && $permission === NULL) {
        $permission = $this->servedOperations->permissionAccess($resource, $operation, $account, new OperationContext($operation, known: $parameters));
        $permission = $permission?->isAllowed() ? $permission : NULL;
      }
      if ($parameters !== NULL && $permission !== NULL) {
        // The account holds the operation's permission for what the
        // parameters name: it may hear that one names nothing, a 404 the
        // controller answers.
        return AccessResult::allowed()->setCacheMaxAge(0);
      }
      return $this->refused($e->getMessage());
    }
    $access = $this->servedOperations->contextAccess($resource, $context, $account);
    // An account that holds the operation's permission for what the
    // parameters name may hear why the rest refused; any other learns
    // nothing of what they name.
    if ($access->isAllowed() || ($this->servedOperations->permissionAccess($resource, $operation, $account, $context)?->isAllowed() ?? FALSE)) {
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
