<?php

declare(strict_types=1);

namespace Drupal\surface\Contract\Keywords;

use Symfony\Component\Validator\Constraints\Length;

/**
 * A row for a constraint that bounds a value: Range, Length or Count.
 *
 * - `Range` on an integer or a number: `min` and `max` as `minimum` and
 *   `maximum`, cast to a number (a definition may hold a bound as a
 *   string, as core's timestamp field does). A bound that is no number
 *   (a date) or one read from another property (`minPropertyPath`,
 *   `maxPropertyPath`) is not stated, and the constraint is named under
 *   `checkedOnServer` besides. On an integer, `exclusiveMinimum` and
 *   `exclusiveMaximum` read back as the next whole number in; on a
 *   number they are kept in the JsonSchema constraint.
 * - `Length` on a string: `minLength`, `maxLength`. Options that count
 *   otherwise than JSON Schema does (`normalizer`, a `countUnit` other
 *   than code points, a `charset` other than UTF-8) leave the whole
 *   constraint unstated.
 * - `Count` on an array: `minItems`, `maxItems`. `divisibleBy` is not
 *   stated, and the constraint is named under `checkedOnServer` besides.
 *
 * `exactly` on Length and Count is written as both bounds, equal, where
 * neither `min` nor `max` is given; beside either, Symfony ignores it, and
 * so does the row, which writes `min` and `max`. Two bounds are read back
 * as `min` and `max`, never as `exactly`, which Symfony reads the same:
 * written again, they are the same keywords.
 *
 * @internal
 */
final class BoundsRow implements ConstraintRowInterface {

  /**
   * Constructs a BoundsRow.
   *
   * @param string $id
   *   The constraint plugin id.
   * @param string $min
   *   The keyword of the lower bound.
   * @param string $max
   *   The keyword of the upper bound.
   * @param string[] $types
   *   The JSON types it applies to, any of them.
   */
  public function __construct(
    protected readonly string $id,
    protected readonly string $min,
    protected readonly string $max,
    protected readonly array $types,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function constraints(): array {
    return [$this->id];
  }

  /**
   * {@inheritdoc}
   */
  public function keywords(): array {
    $keywords = [$this->min, $this->max];
    if ($this->id === 'Range') {
      $keywords[] = 'exclusiveMinimum';
      $keywords[] = 'exclusiveMaximum';
    }
    return $keywords;
  }

  /**
   * {@inheritdoc}
   */
  public function appliesTo(array $types): bool {
    return array_intersect($types, $this->types) !== [];
  }

  /**
   * {@inheritdoc}
   */
  public function write(string $id, array $options, array $types): ?Written {
    if ($this->id === 'Length' && (isset($options['normalizer']) || ($options['countUnit'] ?? Length::COUNT_CODEPOINTS) !== Length::COUNT_CODEPOINTS || strtoupper((string) ($options['charset'] ?? 'UTF-8')) !== 'UTF-8')) {
      return NULL;
    }
    $whole = !isset($options['minPropertyPath']) && !isset($options['maxPropertyPath']) && !isset($options['divisibleBy']);
    $bounds = ['min' => $options['min'] ?? NULL, 'max' => $options['max'] ?? NULL];
    // Symfony reads `exactly` only where neither bound is given, and
    // ignores it beside one.
    if ($this->id !== 'Range' && isset($options['exactly']) && !isset($options['min']) && !isset($options['max'])) {
      $bounds = ['min' => $options['exactly'], 'max' => $options['exactly']];
    }
    $keywords = [];
    foreach ($bounds as $bound => $value) {
      if ($value === NULL) {
        continue;
      }
      if (!is_numeric($value)) {
        $whole = FALSE;
        continue;
      }
      $keywords[$bound === 'min' ? $this->min : $this->max] = $this->id === 'Range' ? $value + 0 : (int) $value;
    }
    return $keywords === [] ? NULL : new Written($keywords, whole: $whole);
  }

  /**
   * {@inheritdoc}
   */
  public function read(array $schema, array $types): ?ReadBack {
    $options = [];
    $consumed = [];
    $kept = [];
    $integer = $types === ['integer'];
    foreach ($schema as $keyword => $value) {
      if (!in_array($keyword, $this->keywords(), TRUE)) {
        continue;
      }
      if (!is_int($value) && !is_float($value)) {
        $kept[$keyword] = $value;
        continue;
      }
      if ($keyword === $this->min || $keyword === $this->max) {
        $option = $keyword === $this->min ? 'min' : 'max';
      }
      elseif ($integer) {
        // Exact for an integer: the next whole number in.
        $option = $keyword === 'exclusiveMinimum' ? 'min' : 'max';
        $value = $keyword === 'exclusiveMinimum' ? (int) floor($value) + 1 : (int) ceil($value) - 1;
      }
      else {
        $kept[$keyword] = $value;
        continue;
      }
      // Both bounds of one side hold: the tighter one.
      $options[$option] = isset($options[$option]) ? ($option === 'min' ? max($options[$option], $value) : min($options[$option], $value)) : $value;
      $consumed[] = $keyword;
    }
    if ($consumed === [] && $kept === []) {
      return NULL;
    }
    // A bound that is no number is no bound of the vocabulary.
    $note = array_diff_key($kept, ['exclusiveMinimum' => TRUE, 'exclusiveMaximum' => TRUE]) === [] ? NULL : 'Kept as a JsonSchema constraint: no constraint of the vocabulary says it.';
    return new ReadBack($consumed === [] ? NULL : $this->id, $options, $consumed, $kept, $note);
  }

}
