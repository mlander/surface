<?php

declare(strict_types=1);

namespace Drupal\surface\Pipeline;

/**
 * A resource's storage whose writes a database transaction undoes whole.
 *
 * A stage takes a write only for a resource whose storage class declares
 * this: its commit(), and its delete() where it also implements
 * DeletableStorageInterface, write through the database connection
 * alone, so the transaction an apply opens rolls every write back, and
 * nothing it writes survives a rollback. A storage that saves config,
 * writes State or changes a table's schema does not declare it, since
 * each keeps something beside the database a rollback leaves behind (a
 * cache, a collector, a schema change MySQL commits at once).
 *
 * Apply makes a stage's writes in one transaction, commitDryRun() per
 * entry in staging order. On any throwable it rolls the transaction
 * back, then calls rolledBack() on the storage of every entry whose
 * commit had started, in reverse order, before it rethrows; the stage
 * stays open. What the storage cached beside the database is its own to
 * drop there (an entity storage's static cache of what it saved or
 * deleted). What hooks a commit fires do beside the database (a mail
 * sent, a remote call) is the hooks', and no rollback undoes it.
 *
 * A class with nothing cached beside the database implements
 * rolledBack() with an empty body.
 *
 * Decision: see
 * docs/decisions.md#a-stage-is-a-resource-of-the-base-module-kept-in-an-expirable-key-value-collection.
 */
interface TransactionalStorageInterface {

  /**
   * Drops what was cached beside the database for writes rolled back.
   *
   * Called once per staged write whose commit this storage had started,
   * in an apply that was rolled back, after the rollback: what a load
   * after it reads must be what the database holds. A routed child's
   * storage is told with its parent's, whether its route wrote or not.
   */
  public function rolledBack(): void;

}
