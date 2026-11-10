<?php

declare(strict_types=1);

namespace Drupal\surface\Contract\Keywords;

/**
 * What a row writes for a constraint.
 *
 * @internal
 */
final class Written {

  /**
   * Constructs a Written.
   *
   * @param array $keywords
   *   The keywords, merged with other rows' by the table's rule
   *   (ConstraintKeywords::MERGE).
   * @param array $extras
   *   What goes under `x-surface` beside them: `patternMessage`, a
   *   Regex's message, as a string or a TranslatableMarkup.
   * @param bool $whole
   *   FALSE when the keywords state only part of the constraint, which
   *   the emitter then also names under `x-surface.checkedOnServer`.
   */
  public function __construct(
    public readonly array $keywords,
    public readonly array $extras = [],
    public readonly bool $whole = TRUE,
  ) {}

}
