<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

/**
 * A value a URL carries that names no thing: the page is not found.
 *
 * Thrown where a value from the request is read as the name of a thing
 * and names none: an id no entity has (OperationArguments), an entity an
 * operation does not take as one of its own (an entity of another
 * bundle), a derived resource id a route fills from its path that
 * discovery does not have, or a value a route's reference reads from its
 * path that the key refuses (OperationRoute). A door answers it as not
 * found for an account allowed the operation, as the API door answers an
 * id that names nothing. It is an InvalidArgumentException, so every
 * catch of one keeps treating it as the refusal it always was.
 *
 * Decision: see
 * docs/decisions.md#a-route-may-fill-or-fix-what-an-operation-asks-for.
 */
final class NamesNothingException extends \InvalidArgumentException {

  /**
   * Constructs a NamesNothingException.
   *
   * @param string $message
   *   What names nothing, and where.
   * @param string|null $parameter
   *   The parameter whose value names nothing: the operation's, for an id
   *   no entity has or a thing the operation does not take; for a
   *   resource id a route fills from its path, the variable of the path
   *   whose raw value is absent when one is, else the id's first; for a
   *   route's references whose values their keys refuse, the first that
   *   read a value, the message naming them all. NULL when no one
   *   parameter is.
   * @param \Throwable|null $previous
   *   The refusal it was read from, if any.
   */
  public function __construct(
    string $message,
    public readonly ?string $parameter = NULL,
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($message, 0, $previous);
  }

}
