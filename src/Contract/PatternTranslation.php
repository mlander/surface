<?php

declare(strict_types=1);

namespace Drupal\data_surface\Contract;

/**
 * Translates a PHP regular expression to a JSON Schema `pattern`.
 *
 * A Regex constraint is a PCRE expression, and a `pattern` is read by an
 * ECMA-262 engine (a browser, ajv) or, here in the suite, by PCRE again
 * (opis). The two read most of a pattern alike and some of it
 * differently, and a `pattern` that refuses what the runtime accepts
 * breaks the rule that the schema is never stricter than the runtime. So
 * the body is carried only when every construct in it is in a subset both
 * engines read alike, and anything else leaves `pattern` out: the key is
 * then checked on the server alone, and its message still says what it
 * allows. Never approximated.
 *
 * The subset: literal characters (any character outside ASCII only with
 * the `u` modifier), an escaped syntax character, `\n` `\r` `\t` `\f`;
 * character classes with ranges, never negated; the class escapes `\d`
 * `\w` `\s`, inside a class or out, and `\b`, only without `u`; the
 * quantifiers `*` `+` `?` and `{n}` `{n,}` `{n,m}`, lazy or not;
 * alternation; capturing, named and non-capturing groups; the anchors
 * `^`, and `$` with the `D` modifier. The modifiers `u` and `D` are
 * carried (an ECMA `$` is already the very end, which is what `D` says);
 * any other modifier, a bracket delimiter, a `$` without `D` (PCRE's
 * matches before a final newline too), `.` (the two engines end a line
 * differently), and everything else (`\A` `\z` `\Z` `\h` `\R` `\x{…}`
 * `\p{…}` `\Q…\E`, the negated class escapes, back-references, inline
 * flags, look-around, atomic groups, possessive quantifiers, `(?P<`,
 * POSIX classes, an escaped delimiter that is no ECMA syntax character)
 * leave it out.
 *
 * Without `u`, PCRE reads `\d` `\w` `\b` by ASCII, exactly as ECMA does,
 * so they are carried as written; its `\s` is the six ASCII white space
 * characters, which ECMA's wider `\s` contains. With `u`, PHP also reads
 * by Unicode properties: `\d` and `\w` (so `\b`) take in letters and
 * digits ECMA never does, and `\s` takes in U+0085 and U+180E, which
 * ECMA's lacks, so none of the four is carried. A negated class is never
 * carried: without `u`, PCRE's matches one byte of a character outside
 * ASCII where ECMA's matches the whole character, and ECMA's `[^\s]`
 * leaves out more than PCRE's.
 *
 * Decision: see docs/decisions.md#a-pattern-both-engines-read-alike.
 */
final class PatternTranslation {

  /**
   * The characters ECMA allows escaped as themselves, `-` besides.
   */
  private const SYNTAX = '^$\\.*+?()[]{}|/';

  /**
   * Translates a PHP regular expression, when it can be.
   *
   * @param string $regex
   *   The expression, delimiters and modifiers included.
   *
   * @return string|null
   *   The `pattern`, or NULL when it cannot be carried.
   */
  public static function ecma(string $regex): ?string {
    if (strlen($regex) < 2) {
      return NULL;
    }
    $delimiter = $regex[0];
    if (ctype_alnum($delimiter) || ctype_space($delimiter) || $delimiter === '\\' || str_contains('([{<', $delimiter)) {
      return NULL;
    }
    $end = strrpos($regex, $delimiter);
    if ($end === FALSE || $end === 0) {
      return NULL;
    }
    $modifiers = substr($regex, $end + 1);
    if (trim($modifiers, 'uD') !== '') {
      return NULL;
    }
    $body = substr($regex, 1, $end - 1);
    return self::safe($body, str_contains($modifiers, 'u'), str_contains($modifiers, 'D')) ? $body : NULL;
  }

  /**
   * Answers whether a pattern body stays inside the subset.
   *
   * @param string $body
   *   The body.
   * @param bool $unicode
   *   Whether the expression carries `u`.
   * @param bool $dollar_end
   *   Whether the expression carries `D`.
   *
   * @return bool
   *   TRUE when both engines read it alike.
   */
  private static function safe(string $body, bool $unicode, bool $dollar_end): bool {
    if (!$unicode && preg_match('/[\x80-\xFF]/', $body)) {
      return FALSE;
    }
    $length = strlen($body);
    // Whether the last thing read can take a quantifier.
    $quantifiable = FALSE;
    $i = 0;
    while ($i < $length) {
      $char = $body[$i];
      if ($char === '\\') {
        $next = $body[$i + 1] ?? '';
        if (!self::escape($next, FALSE, $unicode)) {
          return FALSE;
        }
        // \b is an assertion and takes no quantifier.
        $quantifiable = $next !== 'b';
        $i += 2;
        continue;
      }
      if ($char === '[') {
        $close = self::classEnd($body, $i, $unicode);
        if ($close === NULL) {
          return FALSE;
        }
        $i = $close + 1;
        $quantifiable = TRUE;
        continue;
      }
      if ($char === '(') {
        $open = self::groupOpening($body, $i);
        if ($open === NULL) {
          return FALSE;
        }
        $i += $open;
        $quantifiable = FALSE;
        continue;
      }
      if ($char === '*' || $char === '+' || $char === '?' || $char === '{') {
        $quantifier = $char === '{' ? self::braces($body, $i) : 1;
        if (!$quantifiable || $quantifier === NULL) {
          return FALSE;
        }
        $i += $quantifier;
        // Lazy is read alike; possessive is PCRE's alone.
        if (($body[$i] ?? '') === '?') {
          $i++;
        }
        elseif (($body[$i] ?? '') === '+') {
          return FALSE;
        }
        $quantifiable = FALSE;
        continue;
      }
      if ($char === '$' && !$dollar_end) {
        return FALSE;
      }
      if ($char === '.' || $char === ']' || $char === '}') {
        return FALSE;
      }
      $quantifiable = !in_array($char, ['^', '$', '|'], TRUE);
      $i++;
    }
    return TRUE;
  }

  /**
   * Answers whether an escape is in the subset.
   *
   * @param string $next
   *   The character after the backslash.
   * @param bool $in_class
   *   Whether the escape is inside a character class.
   * @param bool $unicode
   *   Whether the expression carries `u`.
   *
   * @return bool
   *   TRUE when both engines read it alike.
   */
  private static function escape(string $next, bool $in_class, bool $unicode): bool {
    if ($next === '') {
      return FALSE;
    }
    if (in_array($next, ['n', 'r', 't', 'f'], TRUE)) {
      return TRUE;
    }
    // With u, PCRE reads these by Unicode properties and ECMA does not.
    if (in_array($next, ['d', 'w', 's', 'b'], TRUE)) {
      return !$unicode;
    }
    return str_contains(self::SYNTAX, $next) || ($in_class && $next === '-');
  }

  /**
   * Finds where a character class in the subset ends.
   *
   * @param string $body
   *   The pattern body.
   * @param int $start
   *   The offset of its `[`.
   * @param bool $unicode
   *   Whether the expression carries `u`.
   *
   * @return int|null
   *   The offset of its `]`, or NULL when it is not in the subset.
   */
  private static function classEnd(string $body, int $start, bool $unicode): ?int {
    $i = $start + 1;
    // A negated class: without u PCRE's matches a byte of a character, and
    // ECMA's [^\s] leaves out more than PCRE's.
    if (($body[$i] ?? '') === '^') {
      return NULL;
    }
    // A `]` first is a literal to PCRE and closes an empty class to ECMA.
    if (($body[$i] ?? '') === ']') {
      return NULL;
    }
    $length = strlen($body);
    while ($i < $length) {
      $char = $body[$i];
      if ($char === ']') {
        return $i;
      }
      if ($char === '\\') {
        if (!self::escape($body[$i + 1] ?? '', TRUE, $unicode)) {
          return NULL;
        }
        $i += 2;
        continue;
      }
      // A POSIX class is PCRE's alone.
      if ($char === '[' && ($body[$i + 1] ?? '') === ':') {
        return NULL;
      }
      $i++;
    }
    return NULL;
  }

  /**
   * Reads the opening of a group, when it is one in the subset.
   *
   * @param string $body
   *   The pattern body.
   * @param int $start
   *   The offset of its `(`.
   *
   * @return int|null
   *   How many characters open it, or NULL when it is not in the subset.
   */
  private static function groupOpening(string $body, int $start): ?int {
    if (($body[$start + 1] ?? '') !== '?') {
      return 1;
    }
    if (($body[$start + 2] ?? '') === ':') {
      return 3;
    }
    // A named group; never a look-behind, and never PCRE's (?P<.
    if (preg_match('/\G\(\?<([A-Za-z_][A-Za-z0-9_]*)>/', $body, $match, 0, $start)) {
      return strlen($match[0]);
    }
    return NULL;
  }

  /**
   * Reads a `{n}`, `{n,}` or `{n,m}` quantifier.
   *
   * @param string $body
   *   The pattern body.
   * @param int $start
   *   The offset of its `{`.
   *
   * @return int|null
   *   Its length, or NULL when the brace is not a quantifier.
   */
  private static function braces(string $body, int $start): ?int {
    return preg_match('/\G\{\d+(,\d*)?\}/', $body, $match, 0, $start) ? strlen($match[0]) : NULL;
  }

}
