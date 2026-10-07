<?php

declare(strict_types=1);

namespace Drupal\data_surface\Surface;

/**
 * What only the owner may do, on top of adding.
 *
 * Handed to defineInputs() and defineOutputs(). Outputs may not be
 * refined, which the framework refuses when sealing rather than with a
 * separate type.
 */
interface ShapeInterface extends ShapeAdditionsInterface {

  /**
   * A subsurface whose shape one sibling INPUT key chooses.
   *
   * Declared now and built in step 2 of the rework: until then it throws.
   *
   * @param string $key
   *   The key the subsurface sits at.
   * @param string $by
   *   The sibling input key whose value chooses the subsurface.
   * @param array<string, class-string<\Drupal\data_surface\Surface\SurfaceInterface>> $children
   *   One child class per allowed value of $by. Leave empty for an open
   *   slot: every surface marked #[SurfaceVariant] for this key fills it.
   *
   * @return $this
   *
   * @throws \LogicException
   *   Always, until subsurfaces are built.
   */
  public function attachBy(string $key, string $by, array $children = []): static;

}
