<?php

declare(strict_types=1);

namespace Drupal\data_surface\Surface;

/**
 * Where a surface's values live.
 *
 * Named on #[Surface(target:)]. Receives the context, whose known
 * identity says which thing: a target loads by identity, so the context
 * never has to carry an entity object. Whether to create or update is
 * the context's `creates` flag.
 *
 * Three verbs. load() reads, prepare() rehearses the write with every
 * check storage itself would make and no side effects, and commit()
 * writes what prepare() returned. A dry run stops after prepare() and
 * reports what it returned; a real submit runs prepare() first, so that
 * commit() is never the first to find out storage would refuse.
 *
 * Targets compose along the subsurface tree: a child with its own
 * target gets its values routed there; a child without one is stored by
 * its parent under its key.
 *
 * Registered as an autowired service when the surface naming it is
 * discovered, so it may hold services. The engine's pipeline reaches it
 * through \Drupal\data_surface\SurfaceBuild\SurfaceTargetAdapter.
 */
interface SurfaceTargetInterface {

  /**
   * Current values, for an edit. Empty for a situation that creates.
   *
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   Where the surface is being asked for.
   *
   * @return array
   *   The stored values, keyed by surface key.
   */
  public function load(SurfaceContext $context): array;

  /**
   * Rehearses the write without performing it.
   *
   * Builds what commit() would store, in storage's own shape, and holds
   * it to every check storage would make (config schema, entity
   * constraints). Must have no side effects: nothing saved, nothing
   * cached that a later load would read.
   *
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   Where the surface is being asked for.
   * @param array $values
   *   The accepted, validated, canonical values, keyed by surface key.
   *
   * @return array
   *   What commit() would store, in storage's own shape. A dry run
   *   reports it as it is.
   *
   * @throws \Drupal\data_surface\Pipeline\TargetViolationsException
   *   When storage would refuse the values, its violations filed under
   *   the surface keys that own them.
   */
  public function prepare(SurfaceContext $context, array $values): array;

  /**
   * Writes what prepare() returned.
   *
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   Where the surface is being asked for.
   * @param array $prepared
   *   What prepare() returned for this context.
   */
  public function commit(SurfaceContext $context, array $prepared): void;

}
