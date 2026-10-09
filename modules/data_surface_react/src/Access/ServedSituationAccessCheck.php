<?php

declare(strict_types=1);

namespace Drupal\data_surface_react\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface_react\ServedSituations;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Gates the React page and the contract endpoints by their situation.
 *
 * The same answer SituationAccessCheck gives a situation's form route,
 * for a path that names the surface and the situation by id rather than
 * in route defaults: the situation's permission, then the surface's
 * access class, no opinion read as a refusal. A route opts in with the
 * requirement `_data_surface_react_access: 'TRUE'`.
 */
final class ServedSituationAccessCheck implements AccessInterface {

  /**
   * Constructs a ServedSituationAccessCheck.
   *
   * @param \Drupal\data_surface_react\ServedSituations $servedSituations
   *   What reads a situation off a request.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack, for a check made with no request handed in.
   */
  public function __construct(
    protected readonly ServedSituations $servedSituations,
    protected readonly RequestStack $requestStack,
  ) {}

  /**
   * Checks access to a page or endpoint serving one situation.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match, carrying the surface and situation ids.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   The request, carrying the situation's parameters; NULL when access
   *   is asked outside a dispatch, in which case the current request
   *   stands in.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The situation's answer. A surface, situation or parameter that
   *   names nothing is refused rather than thrown: an access question is
   *   never answered with an exception. A POST body that is not a JSON
   *   object is not an access question: the situation is read from the
   *   query string alone, and if that is allowed the controller refuses
   *   the body with a 400.
   */
  public function access(RouteMatchInterface $route_match, AccountInterface $account, ?Request $request = NULL): AccessResultInterface {
    // Access is also asked outside a request's own dispatch: a link, a
    // breadcrumb, a local task. The argument resolver then hands in no
    // request, so the current one stands in for it.
    $request ??= $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      return AccessResult::forbidden('No request to read the situation from.');
    }
    $surface = (string) $route_match->getRawParameter('surface');
    $situation = (string) $route_match->getRawParameter('situation');
    try {
      try {
        $served = $this->servedSituations->fromRequest($surface, $situation, $request);
      }
      catch (BadRequestHttpException) {
        $served = $this->servedSituations->fromRequest($surface, $situation, $request, FALSE);
      }
    }
    catch (\InvalidArgumentException $e) {
      return AccessResult::forbidden($e->getMessage())->setCacheMaxAge(0);
    }
    return $this->servedSituations->access($served, $account);
  }

}
