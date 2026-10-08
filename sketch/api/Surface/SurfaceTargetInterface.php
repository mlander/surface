<?php

declare(strict_types=1);

namespace Drupal\surface_sketch\Surface;

/**
 * Where a surface's values live.
 *
 * Named on #[Surface(target:)]. Receives the context, whose known
 * identity says which thing: a target loads by identity, so the context
 * never has to carry an entity object. Whether to create or update is
 * the context's `creates` flag.
 *
 * Targets compose along the subsurface tree: a child with its own
 * target gets its values routed there; a child without one is stored by
 * its parent under its key.
 */
interface SurfaceTargetInterface {

  /**
   * Current values, for an edit. Empty for a situation that creates.
   */
  public function load(SurfaceContext $context): array;

  /**
   * Rehearses the write without performing it.
   *
   * Returns what commit() would store, in storage's own shape, after
   * every check storage itself would make (config schema, entity
   * constraints). A dry run stops here and reports it; a real submit
   * runs it first so that commit() cannot be the first to find out.
   * Must have no side effects.
   */
  public function prepare(SurfaceContext $context, array $values): array;

  /**
   * Writes what prepare() returned.
   */
  public function commit(SurfaceContext $context, array $prepared): void;

}
