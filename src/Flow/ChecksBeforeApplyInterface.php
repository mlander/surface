<?php

declare(strict_types=1);

namespace Drupal\surface\Flow;

/**
 * A flow class that checks the stage as a whole before Save applies it.
 *
 * Optional: a FlowInterface class may implement this too, for a check
 * across steps or resources that no single write can make. Asked on the
 * confirm page when Save is pressed, after the stage's apply is
 * rehearsed; each violation returned is said on the page and Save
 * applies nothing. This is a check of the form door alone: the stage's
 * `apply` tool and its HTTP path never see a flow.
 *
 * Experimental, as every flow is.
 *
 * @see docs/staging.md#flows
 */
interface ChecksBeforeApplyInterface {

  /**
   * Checks what the stage holds before it is applied.
   *
   * @param \Drupal\surface\Flow\StagedInterface $staged
   *   The stage's values, staged else stored.
   *
   * @return list<\Drupal\surface\Pipeline\Violation>
   *   The refusals; empty when Save may apply the stage.
   */
  public function beforeApply(StagedInterface $staged): array;

}
