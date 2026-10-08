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
   * The sibling gains a Choice over the variants' values, narrower than
   * any list it already declares. Until it holds a value the slot is a
   * placeholder that lists every variant; once it does, the slot is
   * exactly that variant. A sibling the context locks resolves the slot
   * from the start.
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
   *   When the key is already declared, or for outputs, which do not
   *   attach yet.
   */
  public function attachBy(string $key, string $by, array $children = []): static;

}
