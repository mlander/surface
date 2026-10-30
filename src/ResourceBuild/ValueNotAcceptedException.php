<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

/**
 * A value given for a key that the key does not accept.
 *
 * What KeyPaths::held() throws when a site default, a context's default
 * or a context's fixed value is of the wrong shape for its key, or fails
 * one of its constraints: the value is wrong, and where it was given is
 * not. Every other refusal of a path (one that names no key, a secret, a
 * key a value does not reach) is a plain LogicException, so a route can
 * tell a value its path supplied that names nothing from a route written
 * wrong.
 *
 * @internal
 *
 * @see \Drupal\surface\ResourceBuild\OperationRoute
 */
final class ValueNotAcceptedException extends \LogicException {

}
