<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource;

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
 * discovered, so it may hold services. The engine's pipeline reaches it
 * through \Drupal\data_surface\ResourceBuild\StorageAdapter.
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
   * Builds what commit() would store, in storage's own shape, and holds
   * it to every check storage would make (config schema, entity
   * constraints). Must have no side effects: nothing saved, nothing
   * cached that a later load would read.
   *
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   Where the resource is being asked for.
   * @param array $values
   *   The accepted, validated, canonical values, keyed by input key.
   *
   * @return array
   *   What commit() would store, in storage's own shape. A dry run
   *   reports it as it is.
   *
   * @throws \Drupal\data_surface\Pipeline\StorageViolationsException
   *   When storage would refuse the values, its violations filed under
   *   the input keys that own them.
   */
  public function prepare(OperationContext $context, array $values): array;

  /**
   * Writes what prepare() returned.
   *
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   Where the resource is being asked for.
   * @param array $prepared
   *   What prepare() returned for this context.
   */
  public function commit(OperationContext $context, array $prepared): void;

}
