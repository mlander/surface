<?php

declare(strict_types=1);

namespace Drupal\surface_sketch\Surface;

/**
 * What only the owner may do, on top of adding. Handed to defineInputs()
 * and defineOutputs(). Outputs may not be refined, which the framework
 * refuses when sealing rather than with a separate type.
 */
interface ShapeInterface extends ShapeAdditionsInterface {

  /**
   * A subsurface whose shape one sibling INPUT key chooses.
   *
   * @param array<string, class-string<\Drupal\surface_sketch\Surface\SurfaceInterface>> $children
   *   One child class per allowed value of $by. Leave empty for an open
   *   slot: every surface marked #[SurfaceVariant] for this key fills it.
   */
  public function attachBy(string $key, string $by, array $children = []): static;

}
