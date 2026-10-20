<?php

declare(strict_types=1);

namespace Drupal\data_surface\Pipeline;

/**
 * A storage that knows whether the write it serves creates a thing.
 *
 * What a pipeline writes of what was left out depends on it: a thing that
 * exists keeps what it holds and is not handed a default it never held,
 * and a thing being created is written whole, its defaults included.
 *
 * Decision: see docs/decisions.md#absent-keeps-null-clears.
 */
interface CreatingStorageInterface extends DataSurfaceStorageInterface {

  /**
   * Answers whether the operation it serves creates what it writes.
   *
   * @return bool
   *   TRUE when the write creates a thing; FALSE when it changes one that
   *   exists.
   */
  public function creates(): bool;

}
