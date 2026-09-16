<?php

declare(strict_types=1);

namespace Drupal\data_surface;

/**
 * What can be known about one surface-aware class without booting it.
 *
 * The answer DataSurfaceAwareness gives per plugin: the class behind the
 * plugin. A named object rather than a bare class name, because this is
 * what a catalogue — a deriver, a documentation page, an agent
 * enumerating the configurable things on a site — reads, and a
 * documented value object is a contract where a scalar is a convention
 * that cannot be added to without breaking every reader.
 *
 * What the surface holds is deliberately not here: the honest answer to
 * that is the built surface, which costs an instance. This is what can
 * be known without one.
 */
final class DataSurfaceAwarenessRecord {

  /**
   * Constructs a DataSurfaceAwarenessRecord.
   *
   * @param string $class
   *   The fully qualified class name providing the surface.
   */
  public function __construct(
    public readonly string $class,
  ) {
  }

}
