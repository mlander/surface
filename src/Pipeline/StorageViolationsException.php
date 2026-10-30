<?php

declare(strict_types=1);

namespace Drupal\surface\Pipeline;

/**
 * Thrown when a storage refuses the values it cannot hold.
 *
 * The data surface's own constraints and the storage schema's constraints
 * are two different opinions about the same values, and neither knows
 * about the other. The data surface speaks first, through validate(). The
 * storage speaks second, inside prepare(), once it has shaped the values
 * into the thing the schema describes. This exception is how the second
 * opinion travels back: it carries violations in exactly the shape
 * SurfacePipeline::validate() returns them, so a caller folds them
 * into a result without translating anything. The messages stay objects;
 * only the exception's own text, which PHP insists is a string, renders
 * them, and it names the first few and counts the rest.
 *
 * @see \Drupal\surface\Pipeline\ViolationSummary
 */
final class StorageViolationsException extends \RuntimeException {

  /**
   * Constructs a StorageViolationsException.
   *
   * @param \Drupal\surface\Pipeline\ViolationSet $violations
   *   What the storage refused, each violation carrying its key,
   *   the property path within that key and an unrendered message.
   */
  public function __construct(
    protected readonly ViolationSet $violations,
  ) {
    parent::__construct('The storage refused the values: ' . ViolationSummary::fromViolations($violations));
  }

  /**
   * Gets the violations the storage reported.
   *
   * @return \Drupal\surface\Pipeline\ViolationSet
   *   The violations, ready to hand to a result.
   */
  public function getViolations(): ViolationSet {
    return $this->violations;
  }

}
