<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource;

/**
 * A resource's storage that can remove the thing its context names.
 *
 * What an operation declared `#[Operation(deletes: TRUE)]` is written
 * through: two verbs beside the three every storage has, with the same
 * split. prepareDelete() rehearses the removal with every check the store
 * makes and no side effects, and delete() removes what it rehearsed. A
 * dry run stops after the first.
 *
 * A storage that does not implement it cannot serve a delete: the build
 * of its storage for one refuses it.
 *
 * Decision: see docs/decisions.md#a-placed-block-is-a-resource.
 */
interface DeletableStorageInterface extends ResourceStorageInterface {

  /**
   * Rehearses removing the thing the context names, without removing it.
   *
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The delete's context, which knows the thing's identity.
   *
   * @return array
   *   What delete() is handed: enough to name what it removes.
   *
   * @throws \Drupal\data_surface\Pipeline\StorageViolationsException
   *   When the store would refuse the removal, filed under the identity
   *   key or the whole call.
   */
  public function prepareDelete(OperationContext $context): array;

  /**
   * Removes what prepareDelete() rehearsed.
   *
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The delete's context.
   * @param array $prepared
   *   What prepareDelete() returned for this context.
   */
  public function delete(OperationContext $context, array $prepared): void;

}
