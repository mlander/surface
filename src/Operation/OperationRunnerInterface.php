<?php

declare(strict_types=1);

namespace Drupal\surface\Operation;

use Drupal\Core\Session\AccountInterface;
use Drupal\surface\Staging\Stage;

/**
 * Runs one operation through the one sequence every door follows.
 *
 * The gate asks whether a call may be made; run() makes it, or prepares
 * it; submit() submits values to a prepared run. Each answers an
 * OperationRun, refused or not: a failure the caller can cause is a
 * result, never an exception. What can still leave them: run() and
 * submit() throw a StageRaceException when a stage moved meanwhile;
 * gate() throws nothing a caller can cause; submit() handed a run it
 * cannot submit to throws a \BadMethodCallException. Anything else
 * thrown is a bug.
 * The sequence, the identity rule, the disclosure rule and the security
 * model are stated on OperationRunner.
 *
 * Decision: see docs/decisions.md#one-runner-runs-an-operation.
 *
 * @see \Drupal\surface\Operation\OperationRunner
 */
interface OperationRunnerInterface {

  /**
   * Asks whether an operation may be run.
   *
   * The sequence up to the access class: the resource and operation are
   * known, the permission, the stage, the context, the access. It builds
   * nothing itself; an access class that judges by the built resource
   * (its variants, its children) builds it.
   *
   * @param string $resource
   *   The resource's class, or its #[Resource] or #[Structure] id.
   * @param string $operation
   *   The operation id.
   * @param array $arguments
   *   The operation's arguments, by parameter name or by position.
   * @param \Drupal\surface\Operation\Door|string $door
   *   The door the call comes through, or its id.
   * @param \Drupal\surface\Staging\Stage|string|null $stage
   *   The stage the call goes through, or its id; NULL for none.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account to run as; NULL for the current user. One that is the
   *   current user, by id and roles, is taken as NULL; any other is
   *   refused when its user is blocked or does not exist.
   *
   * @return \Drupal\surface\Operation\OperationRun
   *   Refused, or gated: its context and its access answer. Nothing a
   *   caller can cause is thrown.
   */
  public function gate(
    string $resource,
    string $operation,
    array $arguments = [],
    Door|string $door = 'php',
    Stage|string|null $stage = NULL,
    ?AccountInterface $account = NULL,
  ): OperationRun;

  /**
   * Runs an operation, or prepares it when no values are given.
   *
   * @param string $resource
   *   The resource's class, or its #[Resource] or #[Structure] id.
   * @param string $operation
   *   The operation id.
   * @param array $arguments
   *   The operation's arguments, by parameter name or by position.
   * @param array|null $values
   *   The values to submit, keyed by input key; `[]` runs an operation
   *   that takes none (a delete, an apply, a read). NULL prepares: the
   *   surface is built and its storage composed with the stage, and
   *   nothing is submitted or written. A prepare of a resource that names
   *   no storage of its own builds it with none (hasStorage()); a run of
   *   it with values is refused. With values, run() is a prepare, then
   *   submit().
   * @param \Drupal\surface\Operation\Door|string $door
   *   The door the call comes through, or its id.
   * @param \Drupal\surface\Staging\Stage|string|null $stage
   *   The stage the call goes through, or its id; NULL for none.
   * @param bool $dryRun
   *   TRUE to prepare the write and skip it.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account to run as; NULL for the current user. One that is the
   *   current user, by id and roles, is taken as NULL; any other is
   *   refused when its user is blocked or does not exist.
   *
   * @return \Drupal\surface\Operation\OperationRun
   *   Refused, prepared, or run: the pipeline's result and the outputs.
   *
   * @throws \Drupal\surface\Staging\StageRaceException
   *   From a storage under a stage, when the stage moved or its lock was
   *   not had. It is the one exception a caller can cause; nothing is
   *   thrown after a write, and anything else thrown is a bug.
   */
  public function run(
    string $resource,
    string $operation,
    array $arguments = [],
    ?array $values = NULL,
    Door|string $door = 'php',
    Stage|string|null $stage = NULL,
    bool $dryRun = FALSE,
    ?AccountInterface $account = NULL,
  ): OperationRun;

  /**
   * Submits values to a prepared run: the pipeline, then the outputs.
   *
   * For a door that reads the prepared surface and storage before it
   * writes, the HTTP door's fingerprint (`If-Match`) and the stale values
   * it keeps, and the operation form's stored values: the values go to
   * that same surface and storage, which a second run() would build
   * again. Run as the account the run was prepared for
   * (OperationRun::account()), through the same wrapper as gate() and
   * run(): a run-as account (one that is not the current user) blocked
   * or deleted since the prepare is refused and nothing is written, and
   * is switched to, and back in a `finally`. The current user is not
   * checked again.
   *
   * A prepare is written through once. A dry run, or a submit whose
   * result is not valid or commits nothing, leaves it usable, so a door
   * may validate, then write. After a submit that committed, the
   * prepare's surface and stored values are from before the write, so a
   * second submit to it throws: prepare the operation again.
   *
   * @param \Drupal\surface\Operation\OperationRun $prepared
   *   What run() with no values answered: not refused, built, with a
   *   storage, and not run or committed through.
   * @param array $values
   *   The values to submit, keyed by input key; `[]` for an operation
   *   that takes none.
   * @param bool $dryRun
   *   TRUE to prepare the write and skip it.
   *
   * @return \Drupal\surface\Operation\OperationRun
   *   The run: the pipeline's result and the outputs; or refused, when the
   *   account may no longer be run as.
   *
   * @throws \BadMethodCallException
   *   When the run was refused, only gated, names no storage, already
   *   ran, or a submit to it committed: the door's bug, not the caller's.
   * @throws \Drupal\surface\Staging\StageRaceException
   *   From a storage under a stage, as run() throws it.
   */
  public function submit(OperationRun $prepared, array $values, bool $dryRun = FALSE): OperationRun;

}
