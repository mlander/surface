<?php

declare(strict_types=1);

namespace Drupal\surface_sketch\Surface;

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
 */
interface SurfaceTargetInterface {

  /**
   * Current values, for an edit. Empty for a situation that creates.
   */
  public function load(SurfaceContext $context): array;

  /**
   * Writes accepted, validated, canonical values.
   */
  public function commit(SurfaceContext $context, array $values): void;

}
