<?php

declare(strict_types=1);

namespace Drupal\surface\Operation;

use Drupal\Core\Session\AccountInterface;
use Drupal\surface\Staging\Stage;

/**
 * Runs one operation through the one sequence every door follows.
 *
 * The gate asks whether a call may be made; run() makes it. Both answer
 * an OperationRun, refused or not: a failure the caller can cause is a
 * result, never an exception. What can still leave them: run() throws a
 * StageRaceException when a stage moved meanwhile; gate() throws
 * nothing a caller can cause. Anything else thrown by either is a bug.
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
   *   The account to run as; NULL for the current user.
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
   *   nothing is submitted or written.
   * @param \Drupal\surface\Operation\Door|string $door
   *   The door the call comes through, or its id.
   * @param \Drupal\surface\Staging\Stage|string|null $stage
   *   The stage the call goes through, or its id; NULL for none.
   * @param bool $dryRun
   *   TRUE to prepare the write and skip it.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account to run as; NULL for the current user.
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

}
