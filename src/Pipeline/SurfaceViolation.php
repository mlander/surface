<?php

declare(strict_types=1);

namespace Drupal\data_surface\Pipeline;

/**
 * One thing a surface, a target or the pipeline itself refused.
 *
 * Four facts, and no more: which surface key the refusal belongs to,
 * where inside that key's value it sits, what to say about it, and
 * whether it blocks.
 *
 * Almost every entry blocks. The one that does not is a stale
 * reference — a stored value that has fallen outside the list its key
 * now offers — which is reported so it can be said out loud and
 * deliberately does not stop the save, because the alternative is
 * resetting a value nobody asked to change. See ViolationSet for how
 * the two are kept apart, and docs/semantics.md for the rule.
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
   * @param bool $stale
   *   TRUE when the entry is a stale reference rather than a refusal:
   *   the key holds what it always held, that value is no longer among
   *   the ones the key offers, and nothing about this run tried to
   *   change it. A stale entry warns and never blocks.
   */
  public function __construct(
    public readonly string $key,
    public readonly string $path,
    public readonly string|\Stringable $message,
    public readonly bool $stale = FALSE,
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
