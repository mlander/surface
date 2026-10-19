<?php

declare(strict_types=1);

namespace Drupal\data_surface;

/**
 * A value a structure reads from its container: a port.
 *
 * Declared by the structure under a name of its own, with
 * BlueprintInterface::reads(); wired by the container, when it attaches the
 * structure, to one of the container's own keys (attach(..., reads:)). A
 * port is a live sibling value: the structure's refiners and declared rules
 * read it by the port's name, as they read a sibling key, and it moves as
 * the container's key moves. It is never stored by the structure, never a
 * property of its values, and never drawn on its form: it is the
 * container's key, which the container stores and draws.
 *
 * Decision: see docs/decisions.md#a-port-is-a-live-sibling-value.
 */
final class Port {

  /**
   * Constructs a port.
   *
   * @param string $name
   *   The name the structure reads it by.
   * @param string $type
   *   The typed data type of the value, which the key it is wired to must
   *   have: `any` reads any key.
   * @param bool $required
   *   Whether every container must wire it.
   */
  public function __construct(
    public readonly string $name,
    public readonly string $type,
    public readonly bool $required = FALSE,
  ) {
  }

}
