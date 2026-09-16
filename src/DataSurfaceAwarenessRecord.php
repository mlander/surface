<?php

declare(strict_types=1);

namespace Drupal\data_surface;

/**
 * What can be known about one surface-aware class without booting it.
 *
 * The answer DataSurfaceAwareness gives per plugin: the class behind the
 * plugin, and the refinement map that class declares statically. A named
 * object rather than an array shape, because this is what a catalogue —
 * a deriver, a documentation page, an agent enumerating the configurable
 * things on a site — reads, and a documented value object is a contract
 * where an array shape is a convention.
 */
final class DataSurfaceAwarenessRecord {

  /**
   * Constructs a DataSurfaceAwarenessRecord.
   *
   * @param string $class
   *   The fully qualified class name providing the surface.
   * @param array<string, string[]> $refinements
   *   The statically declared refinement map: target definition name =>
   *   the names of the sibling values it is refined against. Empty when
   *   the class declares its surface at runtime instead.
   */
  public function __construct(
    public readonly string $class,
    public readonly array $refinements = [],
  ) {
  }

}
