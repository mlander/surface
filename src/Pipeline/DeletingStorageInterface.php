<?php

declare(strict_types=1);

namespace Drupal\surface\Pipeline;

/**
 * A storage that knows whether the call it serves deletes what it holds.
 *
 * A delete takes no values: the pipeline accepts and validates none,
 * hands prepare() what is stored, and commits whatever it is, so a thing
 * whose stored values no longer hold to the resource can still be
 * removed. Its prepare() rehearses the removal with the store's own
 * checks, and its commit() removes.
 *
 * Decision: see docs/decisions.md#a-placed-block-is-a-resource.
 */
interface DeletingStorageInterface extends SurfaceStorageInterface {

  /**
   * Answers whether the operation it serves deletes what it holds.
   *
   * @return bool
   *   TRUE when a commit removes the thing; FALSE when it writes values.
   */
  public function deletes(): bool;

}
