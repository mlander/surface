<?php

declare(strict_types=1);

namespace Drupal\data_surface\Refinement;

use Drupal\data_surface\Pipeline\ValueState;

/**
 * What a declared rule waits for: a test of sibling values.
 *
 * A closed vocabulary, each word with one exact JSON Schema spelling and
 * one exact evaluation on the values the pipeline accepts, so the full
 * schema can state the rule as an `if` without running anything, and a
 * validator holding that `if` to an accepted document answers what the
 * pipeline answers:
 *
 * - present($k): $k holds a value;
 *   `{properties: {k: {not: {type: null}}}, required: [k]}`.
 * - absent($k): $k holds nothing; `{properties: {k: {type: null}}}`.
 * - equals($k, $v): $k holds $v;
 *   `{properties: {k: {const: v}}, required: [k]}`.
 * - in($k, $vs): $k holds one of $vs;
 *   `{properties: {k: {enum: vs}}, required: [k]}`.
 * - atLeast($k, $n), atMost(), above(), below(): $k holds a number of at
 *   least, at most, above or below $n;
 *   `{properties: {k: {type: number, minimum: n}}, required: [k]}`, or
 *   `maximum`, `exclusiveMinimum`, `exclusiveMaximum`.
 * - not($c), allOf(...$cs), anyOf(...$cs): `{not: c}`, `{allOf: cs}`,
 *   `{anyOf: cs}`.
 *
 * Nothing JSON Schema cannot state exactly is in it: no callback, no
 * expression, no string function. A key holds nothing when its value is
 * NULL or the empty string, the pipeline's one rule; the document the
 * pipeline accepts carries every key, NULL for one holding nothing, and
 * leaves out a key that does not apply, which `absent` reads alike. A
 * watched value its own key refuses is handed over as nothing, as to any
 * refiner.
 *
 * A condition names keys of the frame the rule's key sits in, by their
 * own names: the owner's keys, and an alter's own mounted keys by the
 * names it added them under. The build step resolves each name to where
 * the value is read (a port, once ports land, would resolve there too).
 *
 * Decision: see docs/decisions.md#a-rule-declared-as-data.
 */
final class Condition {

  /**
   * The words of the vocabulary.
   */
  protected const PRESENT = 'present';
  protected const ABSENT = 'absent';
  protected const EQUALS = 'equals';
  protected const IN = 'in';
  protected const AT_LEAST = 'atLeast';
  protected const AT_MOST = 'atMost';
  protected const ABOVE = 'above';
  protected const BELOW = 'below';
  protected const NOT = 'not';
  protected const ALL = 'allOf';
  protected const ANY = 'anyOf';

  /**
   * The JSON Schema keyword each numeric bound is spelled with.
   */
  protected const BOUNDS = [
    self::AT_LEAST => 'minimum',
    self::AT_MOST => 'maximum',
    self::ABOVE => 'exclusiveMinimum',
    self::BELOW => 'exclusiveMaximum',
  ];

  /**
   * Constructs a condition; use the static constructors.
   *
   * @param string $kind
   *   The word.
   * @param string|null $key
   *   The key it tests, for a test of one key.
   * @param mixed $operand
   *   The value, the values or the bound it tests against.
   * @param \Drupal\data_surface\Refinement\Condition[] $conditions
   *   What a `not`, `allOf` or `anyOf` combines.
   */
  private function __construct(
    public readonly string $kind,
    public readonly ?string $key = NULL,
    public readonly mixed $operand = NULL,
    public readonly array $conditions = [],
  ) {
  }

  /**
   * Holds when the key holds a value.
   *
   * @param string $key
   *   The key.
   *
   * @return self
   *   The condition.
   */
  public static function present(string $key): self {
    return new self(self::PRESENT, $key);
  }

  /**
   * Holds when the key holds nothing, or does not apply.
   *
   * @param string $key
   *   The key.
   *
   * @return self
   *   The condition.
   */
  public static function absent(string $key): self {
    return new self(self::ABSENT, $key);
  }

  /**
   * Holds when the key holds this value.
   *
   * @param string $key
   *   The key.
   * @param int|float|string|bool $value
   *   The value, of the key's type.
   *
   * @return self
   *   The condition.
   */
  public static function equals(string $key, int|float|string|bool $value): self {
    return new self(self::EQUALS, $key, $value);
  }

  /**
   * Holds when the key holds one of these values.
   *
   * @param string $key
   *   The key.
   * @param array $values
   *   The values, each a scalar of the key's type; at least one.
   *
   * @return self
   *   The condition.
   *
   * @throws \InvalidArgumentException
   *   When no value is given, or one is not a scalar.
   */
  public static function in(string $key, array $values): self {
    if ($values === [] || array_filter($values, static fn (mixed $value): bool => !is_scalar($value)) !== []) {
      throw new \InvalidArgumentException(sprintf('Condition::in("%s") takes at least one value, each a scalar of the key\'s type.', $key));
    }
    return new self(self::IN, $key, array_values($values));
  }

  /**
   * Holds when the key holds a number of at least the bound.
   *
   * @param string $key
   *   The key, an integer or a float.
   * @param int|float $bound
   *   The bound.
   *
   * @return self
   *   The condition.
   */
  public static function atLeast(string $key, int|float $bound): self {
    return new self(self::AT_LEAST, $key, $bound);
  }

  /**
   * Holds when the key holds a number of at most the bound.
   *
   * @param string $key
   *   The key, an integer or a float.
   * @param int|float $bound
   *   The bound.
   *
   * @return self
   *   The condition.
   */
  public static function atMost(string $key, int|float $bound): self {
    return new self(self::AT_MOST, $key, $bound);
  }

  /**
   * Holds when the key holds a number above the bound.
   *
   * @param string $key
   *   The key, an integer or a float.
   * @param int|float $bound
   *   The bound, which itself does not satisfy it.
   *
   * @return self
   *   The condition.
   */
  public static function above(string $key, int|float $bound): self {
    return new self(self::ABOVE, $key, $bound);
  }

  /**
   * Holds when the key holds a number below the bound.
   *
   * @param string $key
   *   The key, an integer or a float.
   * @param int|float $bound
   *   The bound, which itself does not satisfy it.
   *
   * @return self
   *   The condition.
   */
  public static function below(string $key, int|float $bound): self {
    return new self(self::BELOW, $key, $bound);
  }

  /**
   * Holds when the condition does not.
   *
   * @param \Drupal\data_surface\Refinement\Condition $condition
   *   The condition.
   *
   * @return self
   *   The condition.
   */
  public static function not(Condition $condition): self {
    return new self(self::NOT, conditions: [$condition]);
  }

  /**
   * Holds when every condition does.
   *
   * @param \Drupal\data_surface\Refinement\Condition ...$conditions
   *   At least one condition.
   *
   * @return self
   *   The condition.
   */
  public static function allOf(Condition ...$conditions): self {
    if ($conditions === []) {
      throw new \InvalidArgumentException('Condition::allOf() takes at least one condition.');
    }
    return new self(self::ALL, conditions: array_values($conditions));
  }

  /**
   * Holds when at least one condition does.
   *
   * @param \Drupal\data_surface\Refinement\Condition ...$conditions
   *   At least one condition.
   *
   * @return self
   *   The condition.
   */
  public static function anyOf(Condition ...$conditions): self {
    if ($conditions === []) {
      throw new \InvalidArgumentException('Condition::anyOf() takes at least one condition.');
    }
    return new self(self::ANY, conditions: array_values($conditions));
  }

  /**
   * Lists the keys the condition reads, each once, in order.
   *
   * @return string[]
   *   The keys, by the names the condition gives them.
   */
  public function keys(): array {
    if ($this->key !== NULL) {
      return [$this->key];
    }
    $keys = [];
    foreach ($this->conditions as $condition) {
      array_push($keys, ...$condition->keys());
    }
    return array_values(array_unique($keys));
  }

  /**
   * Lists the tests of one key the condition is made of.
   *
   * @return \Drupal\data_surface\Refinement\Condition[]
   *   Every condition inside it that tests a key, itself included.
   */
  public function tests(): array {
    if ($this->key !== NULL) {
      return [$this];
    }
    $tests = [];
    foreach ($this->conditions as $condition) {
      array_push($tests, ...$condition->tests());
    }
    return $tests;
  }

  /**
   * Returns whether this test compares a number.
   *
   * @return bool
   *   TRUE for atLeast, atMost, above and below.
   */
  public function isBound(): bool {
    return isset(self::BOUNDS[$this->kind]);
  }

  /**
   * Lists the values this test compares the key's value with.
   *
   * @return array
   *   The value of equals, the values of in, the bound of a bound; none
   *   for present, absent and a combination.
   */
  public function operands(): array {
    return match ($this->kind) {
      self::EQUALS, self::AT_LEAST, self::AT_MOST, self::ABOVE, self::BELOW => [$this->operand],
      self::IN => $this->operand,
      default => [],
    };
  }

  /**
   * Evaluates the condition on values as they stand.
   *
   * @param array<string, mixed> $values
   *   The value of each key the condition reads, by its name; a key not
   *   given holds nothing.
   *
   * @return bool
   *   Whether it holds.
   */
  public function holds(array $values): bool {
    $held = $this->key === NULL ? NULL : ($values[$this->key] ?? NULL);
    return match ($this->kind) {
      self::PRESENT => ValueState::isConfigured($held),
      self::ABSENT => !ValueState::isConfigured($held),
      self::EQUALS => ValueState::isConfigured($held) && static::same($held, $this->operand),
      self::IN => ValueState::isConfigured($held) && array_filter($this->operand, static fn (mixed $value): bool => static::same($held, $value)) !== [],
      self::AT_LEAST, self::AT_MOST, self::ABOVE, self::BELOW => static::bounded($this->kind, $held, $this->operand),
      self::NOT => !$this->conditions[0]->holds($values),
      self::ALL => array_filter($this->conditions, static fn (Condition $condition): bool => !$condition->holds($values)) === [],
      self::ANY => array_filter($this->conditions, static fn (Condition $condition): bool => $condition->holds($values)) !== [],
      default => throw new \LogicException(sprintf('No condition is called %s.', $this->kind)),
    };
  }

  /**
   * Spells the condition as a JSON Schema `if`.
   *
   * @param array<string, string[]> $paths
   *   Where each key the condition reads is, as the segments of its path
   *   in the frame: a key an alter mounted is
   *   `['third_party_settings', <module>, <key>]`. A key not given is a
   *   top-level key of the frame.
   *
   * @return array
   *   The schema.
   */
  public function schema(array $paths = []): array {
    $at = $this->key === NULL ? [] : ($paths[$this->key] ?? [$this->key]);
    return match ($this->kind) {
      self::PRESENT => static::at($at, ['not' => ['type' => 'null']], TRUE),
      self::ABSENT => static::at($at, ['type' => 'null'], FALSE),
      self::EQUALS => static::at($at, ['const' => $this->operand], TRUE),
      self::IN => static::at($at, ['enum' => $this->operand], TRUE),
      self::AT_LEAST, self::AT_MOST, self::ABOVE, self::BELOW => static::at($at, [
        'type' => 'number',
        self::BOUNDS[$this->kind] => $this->operand,
      ], TRUE),
      self::NOT => ['not' => $this->conditions[0]->schema($paths)],
      self::ALL => ['allOf' => array_map(static fn (Condition $condition): array => $condition->schema($paths), $this->conditions)],
      self::ANY => ['anyOf' => array_map(static fn (Condition $condition): array => $condition->schema($paths), $this->conditions)],
      default => throw new \LogicException(sprintf('No condition is called %s.', $this->kind)),
    };
  }

  /**
   * Wraps a test of one value in the objects around it.
   *
   * @param string[] $segments
   *   The value's path in the frame.
   * @param array $test
   *   The schema the value is held to.
   * @param bool $required
   *   Whether the value must be there: every test but absent's, which a
   *   key left out, or a mount left out, satisfies.
   *
   * @return array
   *   The schema.
   */
  protected static function at(array $segments, array $test, bool $required): array {
    $schema = $test;
    while ($segments !== []) {
      $name = (string) array_pop($segments);
      $schema = ['properties' => [$name => $schema]] + ($required ? ['required' => [$name]] : []);
    }
    return $schema;
  }

  /**
   * Compares a held value with a declared one, as the accepted document would.
   *
   * The pipeline's values are typed, a form's are text: "5" and 5 are one
   * number, "1" and TRUE one boolean, as the coercion table reads them.
   *
   * @param mixed $held
   *   The value held.
   * @param mixed $value
   *   The value declared.
   *
   * @return bool
   *   Whether they are the same.
   */
  protected static function same(mixed $held, mixed $value): bool {
    if (is_bool($value)) {
      return match (TRUE) {
        is_bool($held) => $held === $value,
        $held === 1, $held === '1' => $value,
        $held === 0, $held === '0' => !$value,
        default => FALSE,
      };
    }
    if (is_int($value) || is_float($value)) {
      return !is_bool($held) && is_numeric($held) && (float) $held === (float) $value;
    }
    return (is_string($held) || is_int($held) || is_float($held)) && (string) $held === (string) $value;
  }

  /**
   * Holds a held value to a numeric bound.
   *
   * @param string $kind
   *   Which bound.
   * @param mixed $held
   *   The value held: a number, or a number's notation.
   * @param int|float $bound
   *   The bound.
   *
   * @return bool
   *   Whether the value is a number within it; nothing, and anything
   *   that is not a number, is not.
   */
  protected static function bounded(string $kind, mixed $held, int|float $bound): bool {
    if (is_bool($held) || !is_numeric($held)) {
      return FALSE;
    }
    $number = (float) $held;
    return match ($kind) {
      self::AT_LEAST => $number >= $bound,
      self::AT_MOST => $number <= $bound,
      self::ABOVE => $number > $bound,
      default => $number < $bound,
    };
  }

}
