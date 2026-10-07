<?php

declare(strict_types=1);

namespace Drupal\data_surface\Surface;

/**
 * Where a surface's values live.
 *
 * Named on #[Surface(target:)]. Receives the context, whose known
 * identity says which thing: a target loads by identity, so the context
 * never has to carry an entity object. Whether to create or update is
 * the situation's `creates` flag.
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
   * Writes accepted, validated, canonical values.
   *
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   Where the surface is being asked for.
   * @param array $values
   *   The values, keyed by surface key.
   */
  public function commit(SurfaceContext $context, array $values): void;

}
