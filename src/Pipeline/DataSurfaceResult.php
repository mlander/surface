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
 *
 * Every door answers it in one shape, document(): `ok`, `committed`,
 * `changed`, `changes`, `values`, `violations`, `notes` and `stale`,
 * each entry `{path, code, message}` (ResultDocument).
 *
 * Decision: see docs/decisions.md#one-result-at-every-door.
 */
final class DataSurfaceResult {

  /**
   * Constructs a DataSurfaceResult.
   *
   * @param array $values
   *   The accepted values in input layout; the input as far as it got
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
   * @param \Drupal\data_surface\Pipeline\Violation[] $notes
   *   What the caller is told and nothing blocks, one per key: a value
   *   sent for a locked key that the key did not take, since it keeps
   *   what it holds ("Lock is locked and kept as stored; the value sent
   *   was not taken."), code `locked`. Never a refusal, so isValid() does
   *   not read it.
   * @param string[]|null $changes
   *   The dotted paths the write changes (or, on a dry run, would change)
   *   against what storage held, a list whole; empty when it changes
   *   nothing, so a committed run with none wrote nothing; NULL when the
   *   run did not reach prepare.
   * @param array|null $written
   *   What storage holds once the run is written, or would hold on a dry
   *   run, in input layout: the accepted values less a key storage never
   *   held that was left at its default, which is not written
   *   (DataSurfacePipeline::kept()); NULL when the run did not reach
   *   prepare.
   * @param bool $writes
   *   Whether committing what was prepared writes: on a dry run, whether
   *   the run would have committed (DataSurfacePipeline::commitDryRun()
   *   reads it); FALSE when the run did not reach prepare, changes
   *   nothing, or would leave a thing that exists as it is.
   */
  public function __construct(
    public readonly array $values,
    public readonly ViolationSet $violations = new ViolationSet(),
    public readonly ?PreparedValues $prepared = NULL,
    public readonly bool $committed = FALSE,
    public readonly ?AccessResultInterface $access = NULL,
    public readonly array $notes = [],
    public readonly ?array $changes = NULL,
    public readonly ?array $written = NULL,
    public readonly bool $writes = FALSE,
  ) {
  }

  /**
   * Gives the values as a read after this run would load them.
   *
   * What every door answers as `values`, once projected: what is written
   * on a write and a dry run, so a key never sent and never stored (a
   * mounted key at its default) is no more in the answer than in the
   * read tool's; the accepted values when the run stopped before prepare.
   *
   * Decision: see
   * docs/decisions.md#every-door-projects-what-the-read-tool-projects.
   *
   * @return array
   *   The values, in input layout.
   */
  public function held(): array {
    return $this->written ?? $this->values;
  }

  /**
   * Returns whether the write changed what storage holds.
   *
   * Decision: see docs/decisions.md#absent-keeps-null-clears.
   *
   * @return bool
   *   TRUE when a committed run moved a value; FALSE when it was not
   *   committed or changed nothing.
   */
  public function changed(): bool {
    return $this->committed && $this->changes !== [];
  }

  /**
   * Answers this result in the one shape every door answers.
   *
   * @param array|\stdClass $values
   *   The values as the door may return them: what the pipeline's
   *   outbound() projected of held(), written as a document by a
   *   door that serves JSON.
   * @param bool $machine
   *   TRUE for a door a program reads (the tool), whose messages name a
   *   key by its path and a value as sent; FALSE for one a person reads
   *   (React), whose messages read as the form's.
   *
   * @return array{ok: bool, committed: bool, changed: bool, changes: string[], values: array|\stdClass, violations: list<array{path: string, code: string, message: string}>, notes: list<array{path: string, code: string, message: string}>, stale: list<array{path: string, code: string, message: string, stored: mixed}>}
   *   The result.
   *
   * @see \Drupal\data_surface\Pipeline\ResultDocument
   */
  public function document(array|\stdClass $values, bool $machine = TRUE): array {
    return ResultDocument::of($this, $values, $machine);
  }

  /**
   * Returns whether the run produced no violations.
   *
   * Violations alone decide it, access included: a run refused by access
   * carries one violation of the whole call, its path empty and its code
   * `access`, so it is invalid for the same reason an out-of-range number
   * is, and a caller that branches on isValid() needs to know nothing new.
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
