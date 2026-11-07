<?php

declare(strict_types=1);

namespace Drupal\surface\Staging;

/**
 * A stage moved inside one request, between a write's prepare and commit.
 *
 * Its record expired, was discarded or was applied, it took a write the
 * plan of an apply did not see, what a staged write was taken against
 * changed, or its lock could not be had after one wait. A runtime failure,
 * as any storage failure is: every refusal a rehearsal can see is a
 * violation from prepare, and these are what is left.
 *
 * Decision: see
 * docs/decisions.md#apply-builds-for-the-current-user-and-writes-as-the-owner.
 *
 * @internal
 */
final class StageRaceException extends \RuntimeException {
}
