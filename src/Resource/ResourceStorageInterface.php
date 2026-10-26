<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource;

use Drupal\Core\Session\AccountInterface;

/**
 * Where a resource's values live.
 *
 * Named on #[Resource(storage:)]. Receives the context, whose known
 * identity says which thing: storage loads by identity, so the context
 * never has to carry an entity object. Whether to create or update is
 * the context's `creates` flag.
 *
 * Three verbs. load() reads, prepare() rehearses the write with every
 * check storage itself would make and no side effects, and commit()
 * writes what prepare() returned. A dry run stops after prepare() and
 * reports what it returned; a real submit runs prepare() first, so that
 * commit() is never the first to find out storage would refuse.
 *
 * Storage composes along the tree of attached parts: a child with its
 * own storage gets its values routed there; a child without it is stored
 * by its parent under its key.
 *
 * Registered as an autowired service when the resource naming it is
 * discovered, so it may hold services, and none bound to the request a
 * door answers: each write verb is handed the account it writes as, and
 * the current user is never read here.
 * The engine's pipeline reaches it through
 * \Drupal\data_surface\ResourceBuild\StorageAdapter.
 *
 * Decision: see
 * docs/decisions.md#nothing-an-operation-runs-reads-the-request.
 */
interface ResourceStorageInterface {

  /**
   * Current values, for an edit. Empty for an operation that creates.
   *
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   Where the resource is being asked for.
   *
   * @return array
   *   The stored values, keyed by input key.
   */
  public function load(OperationContext $context): array;

  /**
   * Rehearses the write without performing it.
   *
   * Builds what commit() would store, in storage's own layout, and holds
   * it to every check storage would make (config schema, entity
   * constraints). Must have no side effects: nothing saved, nothing
   * cached that a later load would read.
   *
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   Where the resource is being asked for.
   * @param array $values
   *   The accepted, validated, canonical values, keyed by input key.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account the write is made as, which a storage that records who
   *   wrote reads here and never from the current user.
   *
   * @return array
   *   What commit() would store, in storage's own layout. A dry run
   *   reports it as it is.
   *
   * @throws \Drupal\data_surface\Pipeline\StorageViolationsException
   *   When storage would refuse the values, its violations filed under
   *   the input keys that own them.
   */
  public function prepare(OperationContext $context, array $values, AccountInterface $account): array;

  /**
   * Writes what prepare() returned.
   *
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   Where the resource is being asked for.
   * @param array $prepared
   *   What prepare() returned for this context.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account the write is made as.
   */
  public function commit(OperationContext $context, array $prepared, AccountInterface $account): void;

}
