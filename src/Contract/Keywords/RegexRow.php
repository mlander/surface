<?php

declare(strict_types=1);

namespace Drupal\surface\Contract\Keywords;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Contract\ContractEmitter;
use Drupal\surface\Contract\PatternTranslation;

/**
 * The row for Regex: `pattern`, or `not: {pattern}`, on a string.
 *
 * Written only when PatternTranslation carries the expression, inside the
 * subset both engines read alike; `match: FALSE` is `not: {pattern}`. A
 * pattern outside the subset, or a `normalizer`, which changes the value
 * before it is matched, writes no pattern, and the constraint is named
 * under `checkedOnServer`. The message is said under
 * `x-surface.patternMessage` either way, unless it names the value it
 * refuses (core's `machine_name` says "The %value machine name is not
 * valid."), a refusal's sentence with no value to name ahead of one.
 *
 * Read back, the pattern is the body of a Regex, delimited by a character
 * it does not hold, with `D`, and `u` where it holds a character outside
 * ASCII, accepted only where PatternTranslation gives the same pattern
 * again; `x-surface.patternMessage` is its message. A property holding
 * both `pattern` and `not: {pattern}` is two Regex constraints, of which
 * a definition holds one, so the pair is kept whole in the JsonSchema
 * constraint, with a note.
 *
 * Decision: see docs/decisions.md#a-pattern-both-engines-read-alike.
 *
 * @internal
 */
final class RegexRow implements ConstraintRowInterface {

  /**
   * {@inheritdoc}
   */
  public function constraints(): array {
    return ['Regex'];
  }

  /**
   * {@inheritdoc}
   */
  public function keywords(): array {
    return ['pattern', 'not'];
  }

  /**
   * {@inheritdoc}
   */
  public function appliesTo(array $types): bool {
    return in_array('string', $types, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function write(string $id, array $options, array $types): Written {
    $regex = $options['pattern'] ?? $options['value'] ?? NULL;
    $pattern = is_string($regex) && !isset($options['normalizer']) ? PatternTranslation::ecma($regex) : NULL;
    $keywords = match (TRUE) {
      $pattern === NULL => [],
      // Symfony holds `match` as a boolean: 0 and '0' are FALSE too.
      !($options['match'] ?? TRUE) => ['not' => ['pattern' => $pattern]],
      default => ['pattern' => $pattern],
    };
    // A pattern is for machines; a person is told the message.
    $message = $options['message'] ?? NULL;
    if (is_string($message) && ($message === '' || preg_match('/%value|@value|\{\{ ?value ?\}\}/', $message) === 1)) {
      $message = NULL;
    }
    $extras = is_string($message) || $message instanceof TranslatableMarkup ? ['patternMessage' => $message] : [];
    return new Written($keywords, $extras, $pattern !== NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function read(array $schema, array $types): ?ReadBack {
    $has_pattern = array_key_exists('pattern', $schema);
    $has_not = array_key_exists('not', $schema);
    if (!$has_pattern && !$has_not) {
      return NULL;
    }
    if ($has_pattern && $has_not) {
      return new ReadBack(NULL, kept: ['pattern' => $schema['pattern'], 'not' => $schema['not']], note: 'Kept as a JsonSchema constraint: a pattern and a pattern it must not match are two Regex constraints, and a definition holds one.');
    }
    $message = $schema[ContractEmitter::EXTENSION]['patternMessage'] ?? NULL;
    $message = is_string($message) ? ['message' => $message] : [];
    if ($has_pattern) {
      $regex = is_string($schema['pattern']) ? static::regex($schema['pattern']) : NULL;
      return $regex === NULL
        ? new ReadBack(NULL, kept: ['pattern' => $schema['pattern']])
        : new ReadBack('Regex', ['pattern' => $regex] + $message, ['pattern']);
    }
    $not = $schema['not'];
    $regex = is_array($not) && array_keys($not) === ['pattern'] && is_string($not['pattern']) ? static::regex($not['pattern']) : NULL;
    return $regex === NULL
      ? new ReadBack(NULL, kept: ['not' => $not])
      : new ReadBack('Regex', ['pattern' => $regex, 'match' => FALSE] + $message, ['not']);
  }

  /**
   * Writes a pattern as the PHP expression the emitter carries it from.
   *
   * PatternTranslation run backwards: a pattern inside the subset both
   * engines read alike is the body of a Regex, delimited by a character
   * it does not hold, with `D`, so a `$` is the very end in PHP as it is
   * in ECMA, and `u` where it holds a character outside ASCII. A pattern
   * outside the subset has no Regex both read alike.
   *
   * @param string $pattern
   *   The `pattern`.
   *
   * @return string|null
   *   The PHP expression, or NULL when the pattern is outside the subset.
   */
  public static function regex(string $pattern): ?string {
    $modifiers = 'D' . (preg_match('/[\x80-\xFF]/', $pattern) ? 'u' : '');
    foreach (['/', '#', '~', '@', '%', '!', ';', ','] as $delimiter) {
      $unescaped = preg_match('/(?<!\\\\)(\\\\\\\\)*' . preg_quote($delimiter, '/') . '/', $pattern);
      if ($unescaped) {
        continue;
      }
      $regex = $delimiter . $pattern . $delimiter . $modifiers;
      if (@preg_match($regex, '') !== FALSE && PatternTranslation::ecma($regex) === $pattern) {
        return $regex;
      }
    }
    return NULL;
  }

}
