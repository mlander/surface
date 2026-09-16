<?php

declare(strict_types=1);

namespace Drupal\data_surface\Pipeline;

use Drupal\Core\Access\AccessResultInterface;

/**
 * What one run through the pipeline produced.
 *
 * The same answer whatever asked the question: a form submit handler, a
 * config action, a service endpoint, or an agent all read the accepted
 * values, the path-aware violations, the prepared artifact when the
 * values were valid, whether the write happened, and the access answer
 * the run was gated by.
 */
final class DataSurfaceResult {

  /**
   * Constructs a DataSurfaceResult.
   *
   * @param array $values
   *   The accepted values in surface shape; the input as far as it got
   *   when a stage refused it.
   * @param \Drupal\data_surface\Pipeline\ViolationSet $violations
   *   Everything the run refused, exactly as
   *   DataSurfacePipeline::validate() reports it.
   * @param \Drupal\data_surface\Pipeline\PreparedValues|null $prepared
   *   The prepared artifact, or NULL when the run did not reach prepare.
   * @param bool $committed
   *   Whether the artifact was written.
   * @param \Drupal\Core\Access\AccessResultInterface|null $access
   *   The access answer the run was gated by, exactly as the caller
   *   resolved it, or NULL when the caller applied none. It is kept
   *   whatever the answer was — forbidden, neutral or allowed — because
   *   it carries cacheability a caller rendering or caching this result
   *   has to merge, and a neutral answer's cacheability is as real as a
   *   refusal's.
   */
  public function __construct(
    public readonly array $values,
    public readonly ViolationSet $violations = new ViolationSet(),
    public readonly ?PreparedValues $prepared = NULL,
    public readonly bool $committed = FALSE,
    public readonly ?AccessResultInterface $access = NULL,
  ) {
  }

  /**
   * Returns whether the run produced no violations.
   *
   * Violations alone decide it, access included: a run refused by access
   * carries one violation under
   * DataSurfacePipelineInterface::ACCESS_VIOLATION_KEY, so it is invalid
   * for the same reason an out-of-range number is, and a caller that
   * branches on isValid() needs to know nothing new.
   */
  public function isValid(): bool {
    return $this->violations->isEmpty();
  }

  /**
   * Returns whether the run was refused by access before anything ran.
   *
   * The question a caller asks when it wants to say "you may not" rather
   * than "that value is wrong": a 403 instead of a 422, a different log
   * line, a different message. Everything else about the two is the
   * same, which is the point of filing the refusal as a violation.
   *
   * @return bool
   *   TRUE when an access answer was given and it was forbidden.
   */
  public function isAccessRefused(): bool {
    return $this->access !== NULL && $this->access->isForbidden();
  }

}
