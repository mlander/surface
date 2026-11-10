<?php

declare(strict_types=1);

namespace Drupal\surface\Flow;

/**
 * What a flow's stage holds, read value by value.
 *
 * Handed to a step's `when` and to beforeApply(). A value is what the
 * stage holds for the thing, else what is stored, as the step's form
 * reads it; a create the route's first step staged is read as the
 * stage holds it. Values are read as the system, with no account's
 * access asked, so a key `read` would hide is answered: a beforeApply()
 * message must not repeat one. A secret is never answered; it reads as
 * NULL.
 *
 * Experimental, as every flow is.
 *
 * @see docs/staging.md#flows
 */
interface StagedInterface {

  /**
   * Reads one value of a resource, staged else stored.
   *
   * @param string $resource
   *   The resource's id or class: the route's own, one a step of the
   *   flow is drawn over, or any other, read through its `read`.
   * @param string $key
   *   The key, or a dotted path into its value.
   * @param array<string, mixed> $arguments
   *   The operation's arguments, by name. When none are given, the
   *   route's for its own resource, the first step over the resource's
   *   for one a step is drawn over, else none.
   *
   * @return mixed
   *   The value; NULL when neither the stage nor storage holds one, and
   *   for a secret.
   *
   * @throws \InvalidArgumentException
   *   When the site has no such resource, or one no step is drawn over
   *   has no `read`.
   */
  public function value(string $resource, string $key, array $arguments = []): mixed;

}
