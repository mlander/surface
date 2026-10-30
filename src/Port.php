<?php

declare(strict_types=1);

namespace Drupal\surface;

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
   * @param string|null $shape
   *   The id of the shape it reads, which the key it is wired to must be
   *   declared with; NULL for a port that reads a type alone.
   * @param bool $through
   *   TRUE for the port a map of keys from the site reads only to hand each
   *   entry its own ports: the entry `<id>` reads `<port>.<id>`, no key of
   *   the map reads it, and no document lists it. Its name starts with a
   *   colon, which no entry's id holds.
   */
  public function __construct(
    public readonly string $name,
    public readonly string $type,
    public readonly bool $required = FALSE,
    public readonly ?string $shape = NULL,
    public readonly bool $through = FALSE,
  ) {
  }

  /**
   * Gets the through port a map of keys from the site reads a sibling map by.
   *
   * Decision: see docs/decisions.md#keys-that-come-from-the-site.
   *
   * @param string $sibling
   *   The sibling map's key.
   *
   * @return static
   *   The port, named `:<sibling>`.
   */
  public static function through(string $sibling): static {
    return new static(':' . $sibling, 'map', through: TRUE);
  }

}
