<?php

declare(strict_types=1);

namespace Drupal\surface_http\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\surface\Access\OperationGate;
use Drupal\surface\Form\CompiledFlow;
use Drupal\surface\Operation\OperationRun;
use Drupal\surface\Operation\Refusal;
use Drupal\surface\Operation\RefusalCategory;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Resource\StageResource;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\surface_http\HttpDoors;
use Drupal\surface_http\ServedPaths;
use Drupal\surface_http\ServedOperations;
use Drupal\surface_http\ProblemException;

/**
 * Gates the API, and a page naming an operation, by the operation served.
 *
 * The runner's gate (OperationRunnerInterface::gate()), for a path that
 * names the resource and the operation by id (a page, such as the React
 * example's) or the resource and the method that implies the operation
 * (the API, read by ServedPaths), rather than in route defaults. A route
 * opts in with the requirement `_surface_served_access: 'TRUE'`. This
 * check reads the request and turns the run into an access answer; the
 * order and the disclosure rule are the runner's: the operation's
 * permission, from the two ids alone; the stage, which applies, exists
 * and is the account's; the context; the access class, no opinion read as
 * a refusal.
 *
 * Past the permission, an account that does not hold it hears one
 * refusal whatever refused (Refusal::WITHHELD), so the reason never says
 * whether an id exists, and the real one goes to the log; so does one
 * refused a `%key` permission, which was filled from the parameters. An
 * account that holds it hears the access class's reason, and that a
 * value names nothing: a 404 its controller answers. The stage's own
 * paths tell every account: an id that names no stage is a 404, another
 * account's stage a 403 with the stage access's reason. On the page, a
 * resource or an
 * operation its path names that discovery does not have is a 404 to any
 * account, as the API answers a path that names nothing; a page that
 * names nothing is let through only in the request's own dispatch, so no
 * link to it is drawn (OperationGate::namesNothing()).
 *
 * An API request whose `X-Surface-Stage` header names a stage is asked,
 * after the permission and before the thing's context, whether a stage
 * applies to the operation, whether the stage exists, and whether it is
 * the account's. One that does not apply, or names no stage, is let
 * through for this request alone, for the controller to answer 422 or
 * 404; another account's is refused, 403, with the stage's own reason.
 * On a path of the stage itself the header is the controller's to
 * refuse, and this check ignores it.
 *
 * A page reads the stage from its query, `?stage=<id>`, as the operation
 * form does (OperationRoute::stageRequested()), and asks the same three
 * things in the same order: a stage that does not apply is refused
 * naming `does_not_apply`; one that does not exist is a page not found;
 * another account's is refused. The context is built through the stage,
 * so `?id=-1&stage=<id>` names what the stage creates. A page's answer
 * varies by the query's `stage`.
 *
 * Decision: see docs/decisions.md#one-runner-runs-an-operation.
 * Decision: see
 * docs/decisions.md#every-door-projects-what-the-read-tool-projects.
 */
final class ServedOperationAccessCheck implements AccessInterface {

  /**
   * The route default that names a page's door, for the runner.
   *
   * The key of the door in the site catalog whose route the page is, such
   * as `react`; the API's door, HttpDoors::DOOR, when a route names none.
   */
  public const DOOR = '_surface_door';

  /**
   * The one reason every refusal past the permission gives.
   *
   * The runner's, Refusal::WITHHELD.
   */
  public const REFUSED = Refusal::WITHHELD;

  /**
   * Constructs a ServedOperationAccessCheck.
   *
   * @param \Drupal\surface_http\ServedOperations $servedOperations
   *   What reads an operation off a request and asks the runner.
   * @param \Drupal\surface_http\ServedPaths $servedPaths
   *   What reads an API path.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack, for a check made with no request handed in.
   * @param \Drupal\surface\Access\OperationGate $gate
   *   The answer every door serving an operation gives alike to a URL
   *   that names nothing.
   * @param \Psr\Log\LoggerInterface|null $logger
   *   Where the reason behind a refusal is written; NULL writes none.
   */
  public function __construct(
    protected readonly ServedOperations $servedOperations,
    protected readonly ServedPaths $servedPaths,
    protected readonly RequestStack $requestStack,
    protected readonly OperationGate $gate,
    protected readonly ?LoggerInterface $logger = NULL,
  ) {}

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
   *   names nothing is refused or let through rather than thrown: an
   *   access question is never answered with an exception. An API path
   *   that names nothing, or is gone, is let through for the controller
   *   to answer with its problem (404, 405, 410); what a path names is
   *   the code's, never a stored value. An id that names nothing is a 404
   *   for an account that holds the operation's permission, and the one
   *   withheld refusal for any other.
   */
  public function access(RouteMatchInterface $route_match, AccountInterface $account, ?Request $request = NULL): AccessResultInterface {
    // Access is also asked outside a request's own dispatch: a link, a
    // breadcrumb, a local task. The argument resolver then hands in no
    // request, so the current one stands in for it, and a page that
    // names nothing draws no link.
    $dispatch = $request;
    $request ??= $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      return AccessResult::forbidden('No request to read the operation from.');
    }
    $resource = (string) $route_match->getRawParameter('resource');
    $operation = $route_match->getRawParameter('operation');
    // The page names the operation in its path; the API, by the method.
    $api = $operation === NULL;
    if ($api) {
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
      // On a path of the stage itself the header is the controller's to
      // refuse, 400.
      $stage = $target->definition->id === StageResource::ID ? NULL : $target->stage;
      // A client calls the API; no link the site draws points at it. What
      // its path names, or does not, is its controller's to answer, as
      // it answers a path that names nothing above.
      $dispatch = $request;
      $door = HttpDoors::DOOR;
    }
    else {
      $operation = (string) $operation;
      try {
        $parameters = $this->servedOperations->parameters($resource, $operation, $request);
      }
      catch (\InvalidArgumentException) {
        // The page's path names a resource or an operation discovery does
        // not have: nothing is there, as the API answers such a path.
        return $this->gate->namesNothing($dispatch, answersNotFound: TRUE);
      }
      // A page names its stage in its query, as the operation form does.
      $stage = $request->query->all()[CompiledFlow::STAGE] ?? NULL;
      $stage = $stage === '' ? NULL : $stage;
      $door = (string) ($route_match->getRouteObject()?->getDefault(self::DOOR) ?? HttpDoors::DOOR);
    }
    if ($stage !== NULL && !is_string($stage)) {
      // A query that names no one stage: the gate without a stage answers
      // a refusal as it is, and names nothing only when it is gated.
      $run = $this->servedOperations->gate($resource, $operation, $parameters, NULL, $door, $account);
      return $run->isRefused() ? $this->answer($run, $api, $dispatch, $resource, $operation) : $this->gate->namesNothing($dispatch, answersNotFound: TRUE);
    }
    // The context alone, built from the parameters: access is answered
    // from it, and nothing is built for a response the page cache answers.
    // Decision: see docs/decisions.md#a-build-is-not-cached-across-requests.
    $run = $this->servedOperations->gate($resource, $operation, $parameters, $stage, $door, $account);
    return $this->answer($run, $api, $dispatch, $resource, $operation);
  }

  /**
   * Turns the runner's answer into the route's, from what it may say.
   *
   * Read from the refusal's public fields alone; its actual() is read
   * only to log what was withheld.
   *
   * @param \Drupal\surface\Operation\OperationRun $run
   *   What the runner's gate answered.
   * @param bool $api
   *   TRUE for the API, FALSE for a page.
   * @param \Symfony\Component\HttpFoundation\Request|null $dispatch
   *   The request, in its own dispatch; NULL when access is asked about a
   *   link.
   * @param string $resource
   *   The resource, as the path named it.
   * @param string $operation
   *   The operation, as the path named it.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The answer.
   */
  protected function answer(OperationRun $run, bool $api, ?Request $dispatch, string $resource, string $operation): AccessResultInterface {
    $refusal = $run->refusal();
    if ($refusal === NULL) {
      $access = $run->access();
      return $api ? $access : $access->andIf(AccessResult::allowed()->addCacheContexts(['url.query_args:' . CompiledFlow::STAGE]));
    }
    if ($refusal->code === Violation::PERMISSION && $refusal->access !== NULL) {
      // A static permission, told: the same for every parameter, so
      // saying it says nothing of what one names. The runner withholds a
      // `%key` one, which arrives below.
      return $refusal->access;
    }
    if ($refusal->category === RefusalCategory::Stage) {
      return match ($refusal->code) {
        // The controller answers 422: the caller drops the header.
        Violation::DOES_NOT_APPLY => $api
          ? AccessResult::allowed()->addCacheableDependency($refusal->cacheability)->setCacheMaxAge(0)
          : AccessResult::forbidden(sprintf('A stage does not apply to the "%s" operation of %s (%s), so this page takes no "%s" in its query.', $operation, $resource, Violation::DOES_NOT_APPLY, CompiledFlow::STAGE))->addCacheContexts(['url.query_args:' . CompiledFlow::STAGE])->setCacheMaxAge(0),
        // The controller answers 404; a page is not found.
        Violation::NOT_FOUND => $api
          ? AccessResult::allowed()->addCacheableDependency($refusal->cacheability)->setCacheMaxAge(0)
          : $this->gate->namesNothing($dispatch, answersNotFound: TRUE),
        // Another account's, with the stage access's own reason.
        default => AccessResult::forbidden($refusal->reason)->addCacheableDependency($refusal->cacheability)->setCacheMaxAge(0),
      };
    }
    // The resource, the operation or a parameter names nothing, told: a
    // 404 the controller answers, in the request's own dispatch.
    if ($refusal->category === RefusalCategory::NamesNothing) {
      return $this->gate->namesNothing($dispatch, answersNotFound: TRUE);
    }
    if ($refusal->reason === Refusal::WITHHELD) {
      $actual = $refusal->actual()->reason;
      if ($actual !== '') {
        $this->logger?->info('A served operation was refused: @reason', ['@reason' => $actual]);
      }
    }
    // Withheld, or the access class told, each with its answer; and an
    // account the runner may not run as (blocked, or no user), which
    // carries none: access asked about another account, for a link.
    return $refusal->access ?? AccessResult::forbidden($refusal->reason)->addCacheableDependency($refusal->cacheability);
  }

}
