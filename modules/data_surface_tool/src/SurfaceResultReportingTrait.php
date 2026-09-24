<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool;

use Drupal\data_surface\Pipeline\ViolationSet;

/**
 * Turns what a pipeline run reported into what a tool result carries.
 *
 * The one boundary where a violation stops being a message object: a
 * tool result carries strings, so the objects are rendered here and
 * nowhere earlier. Shared by every tool in this module, because none of
 * them has anything to say about a violation that the pipeline did not
 * already say.
 */
trait SurfaceResultReportingTrait {

  /**
   * Turns pipeline violations into one message naming every path.
   *
   * @param \Drupal\data_surface\Pipeline\ViolationSet $violations
   *   The violations, exactly as the pipeline reports them.
   *
   * @return string
   *   The violations as one line, each prefixed by its full path. This
   *   is the boundary a message object stops being one: a tool result
   *   carries a single string, so the objects are rendered here and
   *   nowhere earlier.
   */
  protected function violationSummary(ViolationSet $violations): string {
    $lines = [];
    foreach ($violations as $violation) {
      $lines[] = $violation->fullPath() . ': ' . (string) $violation->message;
    }
    return implode(' ', $lines);
  }

  /**
   * Names the stale references a run reported, as their own list.
   *
   * Beside the violations and never mixed into them, because they are a
   * different instruction. A violation says "that input is wrong, send
   * something else"; a stale reference says "nothing you sent is wrong,
   * and a value that was already here points at something that is gone —
   * choose again when you can". An agent that saw them as one list would
   * either retry a request that succeeded or ignore a setting that has
   * quietly stopped meaning anything.
   *
   * Empty for almost every run, and left out of the result entirely when
   * it is, so nothing is added to the shape a caller reads on a normal
   * save.
   *
   * @param \Drupal\data_surface\Pipeline\ViolationSet $violations
   *   The violations, exactly as the pipeline reports them.
   *
   * @return array<string, string>
   *   What to re-choose, keyed by the full path of the key holding it.
   *   The messages are rendered here for the same reason the summary's
   *   are: a tool result carries strings.
   */
  protected function staleReferences(ViolationSet $violations): array {
    $stale = [];
    foreach ($violations->stale() as $reference) {
      $stale[$reference->fullPath()] = (string) $reference->message;
    }
    return $stale;
  }

}
