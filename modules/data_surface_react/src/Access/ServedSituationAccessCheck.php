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
   */
  public function __construct(
    protected readonly ServedSituations $servedSituations,
  ) {}

  /**
   * Checks access to a page or endpoint serving one situation.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match, carrying the surface and situation ids.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request, carrying the situation's parameters.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The situation's answer. A surface, situation or parameter that
   *   names nothing is refused rather than thrown: an access question is
   *   never answered with an exception.
   */
  public function access(RouteMatchInterface $route_match, Request $request, AccountInterface $account): AccessResultInterface {
    try {
      $served = $this->servedSituations->fromRequest(
        (string) $route_match->getRawParameter('surface'),
        (string) $route_match->getRawParameter('situation'),
        $request,
      );
    }
    catch (\InvalidArgumentException | BadRequestHttpException $e) {
      return AccessResult::forbidden($e->getMessage())->setCacheMaxAge(0);
    }
    return $this->servedSituations->access($served, $account);
  }

}
