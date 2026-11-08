<?php

declare(strict_types=1);

namespace Drupal\surface\Staging;

/**
 * A stage moved, or its lock was not had, between prepare and commit.
 *
 * Thrown by an apply, by a write staged into a stage (StagedStorage), and
 * by a stage's own create, rename and discard (StageStorage), whenever
 * the stage's lock could not be had after one wait or its record moved
 * under the operation: it expired, was discarded or was applied, it took
 * a write the rehearsal of an apply did not see, or what a staged write
 * was taken against changed. A runtime failure, as any storage failure
 * is: every refusal a rehearsal can see is a violation from prepare, and
 * these are what is left.
 *
 * Public, since every door that applies a stage or writes into one meets
 * it: PHP code that applies or writes into a stage catches it and tells
 * the person to try again, as the entity example's confirm page does; the
 * module's tool door answers it as a runtime failure naming this class,
 * and the HTTP door as a server error.
 *
 * Decision: see
 * docs/decisions.md#apply-builds-for-the-current-user-and-writes-as-the-owner.
 */
final class StageRaceException extends \RuntimeException {
}
