<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

/**
 * An anonymous map's callable asked for a frame of its own.
 *
 * Thrown by MapBlueprint at the first call only a frame takes (a child, a
 * rule, a resolve, a port) and caught by the owner's addMap(), which
 * attaches the map as a structure with no id instead. Never seen outside
 * the blueprint.
 *
 * Decision: see docs/decisions.md#an-anonymous-map.
 *
 * @internal
 */
final class NeedsFrame extends \LogicException {

  /**
   * Constructs a NeedsFrame.
   *
   * @param string $path
   *   The map's path in its owner.
   * @param string[] $declared
   *   The keys the callable declared before it asked for the frame, which
   *   it declares again first when the owner's declaration runs again to
   *   fill the structure.
   */
  public function __construct(
    public readonly string $path,
    public readonly array $declared = [],
  ) {
    parent::__construct(sprintf('The map "%s" needs a frame of its own: its owner attaches it as a structure with no id.', $path));
  }

}
