<?php

declare(strict_types=1);

namespace Drupal\data_surface\Pipeline;

/**
 * One thing a surface, a target or the pipeline itself refused.
 *
 * Three facts, and no more: which surface key the refusal belongs to,
 * where inside that key's value it sits, and what to say about it.
 *
 * The message stays whatever object built it — a constraint's
 * TranslatableMarkup, the surface's own required message, a config
 * schema's complaint. Rendering it here would print its placeholders
 * once as plain text, and whoever displayed the result afterwards would
 * escape that text a second time. A form error, a tool result and a log
 * line each render it themselves, at their own boundary.
 *
 * @see \Drupal\data_surface\Pipeline\ViolationSet
 * @see docs/pipeline.md
 */
final class SurfaceViolation {

  /**
   * Constructs a SurfaceViolation.
   *
   * @param string $key
   *   The surface key the violation is filed under. Always a top level
   *   key, even for something found deep inside a nested value.
   * @param string $path
   *   The property path within that key's value, dotted; the empty
   *   string for the value itself.
   * @param string|\Stringable $message
   *   What to say about it, unrendered.
   */
  public function __construct(
    public readonly string $key,
    public readonly string $path,
    public readonly string|\Stringable $message,
  ) {
  }

  /**
   * Gets the full dotted path, from the surface key down.
   *
   * @return string
   *   The surface key, followed by the path within it when there is
   *   one.
   */
  public function fullPath(): string {
    return $this->path === '' ? $this->key : $this->key . '.' . $this->path;
  }

}
