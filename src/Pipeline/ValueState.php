<?php

declare(strict_types=1);

namespace Drupal\data_surface\Pipeline;

/**
 * The one answer to "does this key hold a value?".
 *
 * Every stage needs the same distinction and none of them may answer it
 * differently: accept() decides whether input replaces the fallback,
 * validate() decides whether a required key was configured, and
 * DataSurface::refine() decides whether a dependency is known. Before
 * this class each of them carried its own variation, so a boolean FALSE
 * was a value to one stage and an absence to another.
 *
 * The rule is deliberately narrow: a key is not configured when it holds
 * NULL or the empty string, and holds a value in every other case. The
 * empty string is in there because that is what a browser submits for an
 * untouched text field, a cleared number, and an unchosen select, and
 * nothing else is, because everything else a caller can send is
 * something the caller chose: FALSE is a checkbox that is off, 0 is a
 * number, '0' is that number as a form submits it, and an empty array is
 * a list or a map with nothing in it. A surface that wants to refuse
 * those says so with a constraint.
 *
 * @see docs/semantics.md
 */
final class ValueState {

  /**
   * Returns whether a value counts as configured.
   *
   * @param mixed $value
   *   The value to judge, in any shape a caller can send.
   *
   * @return bool
   *   TRUE unless the value is NULL or the empty string.
   */
  public static function isConfigured(mixed $value): bool {
    return $value !== NULL && $value !== '';
  }

}
