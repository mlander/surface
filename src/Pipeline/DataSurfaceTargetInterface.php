<?php

declare(strict_types=1);

namespace Drupal\data_surface\Pipeline;

use Drupal\data_surface\DataSurfaceInterface;

/**
 * Where one surface's values are read from and written to.
 *
 * The seam that keeps storage out of the surface and forms out of the
 * storage: a surface plus a target is a complete configurable thing.
 * Preparing and committing are separate so a caller can preview, diff,
 * or discard a submission without the write having happened.
 */
interface DataSurfaceTargetInterface {

  /**
   * Reads the stored values in surface shape.
   *
   * The 'current' argument of the pipeline's accept(), which is what
   * makes a partial update legal.
   *
   * The surface is an argument rather than something a target holds,
   * because a target describes a destination and a surface describes
   * values: the same destination serves the add surface and the edit
   * surface of one thing, and a target that held one of them could not.
   * It is also what keeps a target free of the surface when it rides
   * along on a cached form.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface whose keys are wanted.
   *
   * @return array
   *   The stored values keyed by surface key; empty when nothing is
   *   stored yet.
   */
  public function load(DataSurfaceInterface $surface): array;

  /**
   * Turns surface values into this target's storage shape.
   *
   * Writes nothing.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface the values belong to.
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
