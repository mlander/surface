<?php

declare(strict_types=1);

namespace Drupal\data_surface\Pipeline;

/**
 * Thrown when a target refuses the values its storage cannot hold.
 *
 * The surface's own constraints and the storage schema's constraints are
 * two different opinions about the same values, and neither knows about
 * the other. The surface speaks first, through validate(). The storage
 * speaks second, inside prepare(), once the target has shaped the values
 * into the thing the schema describes. This exception is how the second
 * opinion travels back: it carries violations in exactly the shape
 * DataSurfacePipeline::validate() returns them, so a caller folds them
 * into a result without translating anything. The messages stay objects;
 * only the exception's own text, which PHP insists is a string, renders
 * them, and it names the first few and counts the rest.
 *
 * @see \Drupal\data_surface\Pipeline\ViolationSummary
 */
final class TargetViolationsException extends \RuntimeException {

  /**
   * Constructs a TargetViolationsException.
   *
   * @param \Drupal\data_surface\Pipeline\ViolationSet $violations
   *   What the storage refused, each violation carrying its surface key,
   *   the property path within that key and an unrendered message.
   */
  public function __construct(
    protected readonly ViolationSet $violations,
  ) {
    parent::__construct('The target refused the values: ' . ViolationSummary::fromViolations($violations));
  }

  /**
   * Gets the violations the target reported.
   *
   * @return \Drupal\data_surface\Pipeline\ViolationSet
   *   The violations, ready to hand to a result.
   */
  public function getViolations(): ViolationSet {
    return $this->violations;
  }

}
