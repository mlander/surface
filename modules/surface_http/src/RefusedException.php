<?php

declare(strict_types=1);

namespace Drupal\surface_http;

use Drupal\surface\Operation\Refusal;

/**
 * Thrown when the runner refused the operation a request names.
 *
 * ServedOperations::resolve() throws it for a page, which turns it into
 * its own answer; the API's controller reads the run itself
 * (ResourceApiController::problem()). Its message is the refusal's reason
 * as the account may hear it.
 *
 * @internal
 */
final class RefusedException extends \RuntimeException {

  /**
   * Constructs a RefusedException.
   *
   * @param \Drupal\surface\Operation\Refusal $refusal
   *   Why, as the account may hear it.
   */
  public function __construct(public readonly Refusal $refusal) {
    parent::__construct($refusal->reason);
  }

}
