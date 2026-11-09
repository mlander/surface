<?php

declare(strict_types=1);

namespace Drupal\surface\Staging;

/**
 * Where the stages are kept: what the engine asks of a store.
 *
 * The type of the `surface.stages` service, and what every consumer in
 * this module is handed. The shipped implementation is StageStore, over
 * the expirable key-value collection `surface.stage`. A site may swap the
 * service for its own implementation that keeps records somewhere else,
 * for a different time, or for good.
 *
 * A store keeps records; it never shapes them. The record is a PHP array
 * with no objects, in the shape StageRecord states, and a store writes it
 * whole and reads it back unchanged. A store changes no key. Beyond the
 * keys read(), named(), the create lookups and a listing read, the only
 * key it acts on is `keep`, the seconds a stage lives after its last
 * write, which a store may honor as a time to live or ignore.
 *
 * A store may also implement ListsStagesInterface, to list an account's
 * open stages.
 *
 * Decision: see
 * docs/decisions.md#one-store-contract-a-stages-lifetime-is-data-on-its-record.
 */
interface StageStoreInterface {

  /**
   * Gets the stage an id names, for a storage to be handed.
   *
   * @param string $id
   *   The stage's id.
   *
   * @return \Drupal\surface\Staging\Stage
   *   The stage, with no arguments yet: the record's id, owner and label.
   *
   * @throws \Drupal\surface\ResourceBuild\NamesNothingException
   *   At `stage`, when read() answers NULL for the id.
   */
  public function named(string $id): Stage;

  /**
   * Reads a stage's record.
   *
   * @param string $id
   *   The stage's id.
   *
   * @return array|null
   *   The record as it was last written; NULL when there is none, it
   *   outlived its lifetime, its `version` is not StageRecord::VERSION,
   *   or its `id` is not the id asked for. NULL for an empty id.
   */
  public function read(string $id): ?array;

  /**
   * Finds the create a stage holds of a resource, by the address given it.
   *
   * The create entry of the resource in the stage's open record whose
   * every address key (StageRecord::address()) is among the arguments and
   * equal to it as text, so `-3`, `"-3"` and a caller's name `abc` each
   * name what was staged under them. A store answers it from its read()
   * through StageRecord::created(), so the rule is the same in every
   * store.
   *
   * @param \Drupal\surface\Staging\Stage $stage
   *   The stage.
   * @param string $resource
   *   The resource's id.
   * @param array<string, mixed> $arguments
   *   The operation's arguments, by name.
   *
   * @return array|null
   *   The entry, or NULL when the stage is gone, applied or voided, or
   *   creates no thing of the resource under these arguments.
   */
  public function created(Stage $stage, string $resource, array $arguments): ?array;

  /**
   * Finds the create a stage holds of a resource, by its position.
   *
   * A store answers it from its read() through StageRecord::createdAt().
   *
   * @param \Drupal\surface\Staging\Stage $stage
   *   The stage.
   * @param string $resource
   *   The resource's id.
   * @param int $position
   *   The create's position.
   *
   * @return array|null
   *   The entry, or NULL when the stage is gone, applied or voided, or
   *   holds no create of the resource at the position.
   */
  public function createdAt(Stage $stage, string $resource, int $position): ?array;

  /**
   * Writes a stage's record, whole.
   *
   * The record replaces any record under its id. The store decides how
   * long it keeps the record from its `keep`, the seconds after this
   * write; a record with no `keep`, written before stages carried one,
   * takes the store's own default. A store that keeps records for good
   * ignores `keep`.
   *
   * The write is not part of any database transaction of the caller: an
   * apply writes the record after its own transaction committed or rolled
   * back.
   *
   * @param array $record
   *   The record, which names its id.
   *
   * @throws \Exception
   *   When the record could not be kept. The caller's write fails with it.
   */
  public function write(array $record): void;

  /**
   * Deletes a stage's record.
   *
   * Deleting a record that is gone does nothing.
   *
   * @param string $id
   *   The stage's id.
   */
  public function delete(string $id): void;

  /**
   * Takes the lock of one stage.
   *
   * Tries, waits once for another request's lock, then tries again. The
   * request that holds the lock already is let through. The caller
   * releases it after its write.
   *
   * @param string $id
   *   The stage's id.
   *
   * @return bool
   *   TRUE when this request holds it; FALSE when another still did after
   *   the wait.
   */
  public function lock(string $id): bool;

  /**
   * Releases the lock of one stage.
   *
   * @param string $id
   *   The stage's id.
   */
  public function release(string $id): void;

  /**
   * Gets the time a record is written at.
   *
   * The storage stamps a record's `opened` and `updated` with it, and a
   * listing (ListsStagesInterface) orders by `opened`.
   *
   * @return int
   *   The request time, as a Unix timestamp.
   */
  public function now(): int;

}
