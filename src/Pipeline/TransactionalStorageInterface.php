<?php

declare(strict_types=1);

namespace Drupal\surface\Pipeline;

/**
 * A resource's storage whose writes a database transaction undoes whole.
 *
 * A stage takes a write only for a resource whose storage class declares
 * this. The promise: its commit(), and its delete() where it also
 * implements DeletableStorageInterface, write their permanent state
 * through the database connection alone, so the one transaction an
 * apply opens undoes every write whole; and what they put in caches or
 * in memory beside the database is undone in rolledBack(). The config
 * storages (ConfigObjectStorage, ConfigEntityStorage) declare it: config
 * rows are the database's, and apply itself resets what config keeps
 * beside them for every config name an apply wrote. A storage that
 * writes State, or changes a table's schema, does not: State keeps a
 * cache a rollback leaves behind, and MySQL commits a schema change at
 * once.
 *
 * Apply makes a stage's writes in one transaction, commitDryRun() per
 * entry in staging order. On any throwable it rolls the transaction
 * back, resets config's caches for the config it wrote, then calls
 * rolledBack() on the storage of every entry whose commit had started,
 * in reverse order, before it rethrows; the stage stays open. What the
 * storage cached beside the database is its own to drop there (an
 * entity storage's static cache of what it saved or deleted). What
 * hooks a commit fires do beside the database (a mail sent, a remote
 * call) is the hooks', and no rollback undoes it.
 *
 * A class with nothing cached beside the database implements
 * rolledBack() with an empty body.
 *
 * Decision: see
 * docs/decisions.md#a-stage-is-a-resource-of-the-base-module-kept-in-an-expirable-key-value-collection.
 * Decision: see
 * docs/decisions.md#config-is-staged-and-apply-resets-what-its-rollback-leaves-behind.
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
