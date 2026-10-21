<?php

declare(strict_types=1);

namespace Drupal\data_surface\Pipeline;

use Drupal\data_surface\DataSurfaceInterface;

/**
 * Where one data surface's values are read from and written to.
 *
 * The seam that keeps storage out of the data surface and forms out of
 * the storage: a data surface plus a storage is a complete configurable
 * thing.
 * Preparing and committing are separate so a caller can preview, diff,
 * or discard a submission without the write having happened.
 */
interface DataSurfaceStorageInterface {

  /**
   * Reads the stored values in input layout.
   *
   * The 'current' argument of the pipeline's accept(), which is what
   * makes a partial update legal.
   *
   * The data surface is an argument rather than something a storage
   * holds, because a storage describes a destination and a data surface
   * describes values: the same destination serves the add operation and
   * the edit operation of one resource, and a storage that held the data
   * surface of one of them could not. It is also what keeps a storage
   * free of the data surface when it rides along on a cached form.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface whose keys are wanted.
   *
   * @return array
   *   The stored values keyed by data surface key; empty when nothing is
   *   stored yet.
   */
  public function load(DataSurfaceInterface $surface): array;

  /**
   * Turns accepted values into this storage's own layout.
   *
   * Writes nothing.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface the values belong to.
   * @param array $values
   *   The accepted, validated values.
   *
   * @return \Drupal\data_surface\Pipeline\PreparedValues
   *   The values, the storage-shaped artifact, and any dependencies.
   */
  public function prepare(DataSurfaceInterface $surface, array $values): PreparedValues;

  /**
   * Writes a prepared artifact.
   *
   * The only stage with side effects, and the only one a dry run skips.
   *
   * @param \Drupal\data_surface\Pipeline\PreparedValues $prepared
   *   The artifact to store.
   */
  public function commit(PreparedValues $prepared): void;

}
