<?php

declare(strict_types=1);

namespace Drupal\surface\Resource;

use Drupal\Core\Session\AccountInterface;

/**
 * A resource's storage that can remove the thing its context names.
 *
 * What an operation declared `#[Operation(deletes: TRUE)]` is written
 * through: two verbs beside the three every storage has, with the same
 * split. prepareDelete() rehearses the removal with every check the store
 * makes and no side effects, and delete() removes what it rehearsed. A
 * dry run stops after the first.
 *
 * A storage that removes a thing by its identity can say whether one is
 * stored under an identity, exists(), and what one thing is called,
 * singularLabel(). The build reads both: every operation that creates
 * holds its identity key to the stock SurfaceUnique constraint, which
 * asks exists() and refuses an identity taken, "A venue named riverside
 * already exists."
 *
 * A storage that does not implement it cannot serve a delete: the build
 * of its storage for one refuses it.
 *
 * Decision: see docs/decisions.md#a-placed-block-is-a-resource.
 * Decision: see docs/decisions.md#a-machine-name-is-a-computed-identity.
 */
interface DeletableStorageInterface extends ResourceStorageInterface {

  /**
   * Rehearses removing the thing the context names, without removing it.
   *
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The delete's context, which knows the thing's identity.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account the removal is made as.
   *
   * @return array
   *   What delete() is handed: enough to name what it removes.
   *
   * @throws \Drupal\surface\Pipeline\StorageViolationsException
   *   When the store would refuse the removal, filed under the identity
   *   key or the whole call.
   */
  public function prepareDelete(OperationContext $context, AccountInterface $account): array;

  /**
   * Removes what prepareDelete() rehearsed.
   *
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The delete's context.
   * @param array $prepared
   *   What prepareDelete() returned for this context.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account the removal is made as.
   */
  public function delete(OperationContext $context, array $prepared, AccountInterface $account): void;

  /**
   * Answers whether a thing is stored under the identity the context knows.
   *
   * Asked of a context that knows every identity key, by the stock
   * SurfaceUnique constraint and by the form's machine name element,
   * so every door refuses a taken identity in one sentence. Reads what is
   * stored, as load() does, and nothing bound to a request.
   *
   * @param \Drupal\surface\Resource\OperationContext $context
   *   A context whose known identity names the thing.
   *
   * @return bool
   *   TRUE when one is stored under it.
   */
  public function exists(OperationContext $context): bool;

  /**
   * Names one of the things this storage holds, for a sentence.
   *
   * @return string|\Stringable
   *   The singular label, lowercase: "venue", "content type".
   */
  public function singularLabel(): string|\Stringable;

}
