<?php

declare(strict_types=1);

namespace Drupal\surface\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\surface\SurfaceAccess;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\ResourcesInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The two answers every door that serves an operation gives alike.
 *
 * A door asks in one order: the operation's permission, from the
 * resource and operation ids alone, before anything the request carries
 * is read, so an account without it learns nothing of what exists; then
 * the context the request's values build; then the access class. When a
 * value of the URL names nothing, an account the operation's permission
 * lets in for what the values name hears that it names nothing, as a
 * page not found from a controller that answers so, and no other does; a
 * resource the path names that discovery does not have is not found to
 * any account, since nothing is there to hold a permission for. The
 * operation form's route (OperationAccessCheck) and the React page and
 * the API (the HTTP door's ServedOperationAccessCheck) ask both
 * answers here, so the two doors cannot give them differently; the order
 * they are asked in, and the permission asked again of the URL's values,
 * each door writes itself.
 *
 * Decision: see
 * docs/decisions.md#every-door-projects-what-the-read-tool-projects.
 *
 * @internal
 */
final class OperationGate {

  /**
   * Constructs an OperationGate.
   *
   * @param \Drupal\surface\ResourceBuild\ResourcesInterface $resources
   *   The build step, which answers an operation's permission.
   */
  public function __construct(
    protected readonly ResourcesInterface $resources,
  ) {}

  /**
   * Answers the operation's permission, before its context is built.
   *
   * The permission as the operation declares it, a `%key` in it filled
   * from the values the door was given by name (`administer %entity_type
   * fields`); nothing is invoked, built or loaded. Asked with no values
   * first, the static permission alone, and again with them when that
   * decided nothing.
   *
   * @param string $resource
   *   The resource's class or id.
   * @param string $operation
   *   The operation id.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   * @param array $known
   *   The values the door was given for the operation's parameters, by
   *   name, as the request carries them; empty asks the permission as
   *   written.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Allowed when the account holds it, and forbidden naming it when the
   *   account does not, each varying by the account's permissions; neutral
   *   when the operation has none, or one with a `%key` the values do not
   *   fill, which decides nothing.
   *
   * @throws \InvalidArgumentException
   *   When the resource or the operation is not discovered.
   */
  public function permission(string $resource, string $operation, AccountInterface $account, array $known = []): AccessResultInterface {
    $permission = $this->resources->permissionAccess($resource, $operation, $account, $known === [] ? NULL : new OperationContext($operation, known: $known));
    if ($permission === NULL) {
      return AccessResult::neutral();
    }
    return SurfaceAccess::decisive($permission, $permission instanceof AccessResultReasonInterface ? $permission->getReason() : NULL);
  }

  /**
   * Answers access to a URL whose value names nothing.
   *
   * Asked for an account the operation's permission lets in, or for any
   * account where nothing is there to hold a permission for. In the
   * request's own dispatch, which hands the request in, it is let
   * through, for the controller to answer with a page not found that says
   * why, as core answers a path whose placeholder its converter cannot
   * load; only to a controller the door knows answers so, since a
   * controller that serves the URL without reading what its values name
   * would serve a page for nothing, past the context's access class. Any
   * other is refused with no reason. Asked about a link, which hands no
   * request in (Url::access(), a menu link, a local task, a breadcrumb),
   * it is refused with no reason, so no link to a page not found is
   * drawn. Nothing is written to the log: core's breadcrumb asks about
   * every path above the one requested, so a URL anyone may type would
   * write a line per request. Neither answer is kept past this request:
   * what a value names can change.
   *
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   The request, when access is asked in its own dispatch; NULL when it
   *   is asked about a link.
   * @param bool $answersNotFound
   *   Whether the route's controller answers a URL that names nothing with
   *   a page not found: the operation form, a React page, the API. The
   *   door tells, from the route it gates.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Allowed in a dispatch to a controller that answers a page not found,
   *   forbidden otherwise, each for this request alone.
   */
  public function namesNothing(?Request $request, bool $answersNotFound): AccessResultInterface {
    return $request !== NULL && $answersNotFound
      ? AccessResult::allowed()->setCacheMaxAge(0)
      : AccessResult::forbidden()->setCacheMaxAge(0);
  }

}
