<?php

declare(strict_types=1);

namespace Drupal\data_surface\Pipeline;

use Drupal\Core\TypedData\DataDefinitionInterface;

/**
 * The coercion table, the Tool API's, applied to every door.
 *
 * A mirror of the Tool API's InputTypeCoercionSubscriber, rule for rule
 * and in its order, so a value a form posts and the same value a tool
 * caller sends are accepted as the same value. The Tool API offers its
 * table only as an event subscriber on a tool's inputs (final, its rules
 * private, its event needing a tool instance and a tool input
 * definition), and this module does not depend on it, so the table is
 * repeated here, once. The tool door runs the Tool API's own subscriber
 * first and this one after it; every rule is idempotent, so the second
 * pass changes nothing the first made.
 *
 * Its principle is the Tool API's: coerce only when the intended value
 * is unambiguous, and leave anything doubtful exactly as it arrived for
 * validation to refuse. One row is narrower than the Tool API's code,
 * on that principle: a numeric string with a fraction for an integer
 * ('1.9') is left for validation, where the subscriber truncates it.
 *
 * What holds nothing (NULL, and the empty string, which is how a form
 * says nothing) never reaches this class: the pipeline reads it first,
 * as a cleared key, which a required key refuses (docs/semantics.md).
 *
 * @see \Drupal\tool\EventSubscriber\InputTypeCoercionSubscriber
 * @see docs/semantics.md#the-coercion-table
 */
final class Coercion {

  /**
   * The timezone datetime values are stored in, as the subscriber's.
   */
  private const STORAGE_TIMEZONE = 'UTC';

  /**
   * The storage format for a value with a date and a time.
   */
  private const DATETIME_STORAGE_FORMAT = 'Y-m-d\TH:i:s';

  /**
   * The storage format for a date.
   */
  private const DATE_STORAGE_FORMAT = 'Y-m-d';

  /**
   * The input formats for a target declaring a date and a time.
   *
   * Every one carries a timezone, so no zone is ever guessed.
   */
  private const DATETIME_INPUT_FORMATS = [
    \DateTimeInterface::RFC3339,
    \DateTimeInterface::ISO8601,
    'Y-m-d\TH:i:s.uP',
    'Y-m-d H:i:sP',
    'Y-m-d H:i:sO',
  ];

  /**
   * The input formats for a target declaring a date alone.
   */
  private const DATE_INPUT_FORMATS = [
    self::DATE_STORAGE_FORMAT,
  ];

  /**
   * Reads a JSON string as the structure a list or a map expects.
   *
   * Only a string that decodes to the shape the target holds is read: a
   * list for a list, an object (or `{}`) for a map. Anything else is
   * returned as it arrived.
   *
   * @param mixed $value
   *   The raw value.
   * @param bool $list
   *   Whether the target is a list; a map otherwise.
   *
   * @return mixed
   *   The decoded structure, or the value untouched.
   */
  public static function decoded(mixed $value, bool $list): mixed {
    if (!is_string($value)) {
      return $value;
    }
    $trimmed = trim($value);
    $first = $trimmed[0] ?? '';
    if ($first !== '{' && $first !== '[') {
      return $value;
    }
    $decoded = json_decode($trimmed, TRUE);
    if (!is_array($decoded)) {
      return $value;
    }
    $matches = $list ? array_is_list($decoded) : ($decoded === [] || !array_is_list($decoded));
    return $matches ? $decoded : $value;
  }

  /**
   * Coerces one configured value that is not a structure to its type.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition the value belongs to.
   * @param mixed $value
   *   The raw value; never NULL or the empty string.
   * @param array $choices
   *   The values the key declares, for a near miss to snap onto; empty
   *   for a key that declares none.
   *
   * @return mixed
   *   The value in the definition's type, or untouched when the intended
   *   value is not unambiguous.
   */
  public static function scalar(DataDefinitionInterface $definition, mixed $value, array $choices = []): mixed {
    $type = $definition->getDataType();

    // A case or whitespace near miss of a declared choice ("Article" for
    // "article"), when exactly one choice matches.
    if (is_string($value) && $choices !== []) {
      $snapped = self::snap($value, $choices);
      if ($snapped !== NULL) {
        return $snapped;
      }
    }

    if ($type === 'boolean') {
      if ($value === 0 || $value === 1 || $value === 0.0 || $value === 1.0) {
        return (bool) $value;
      }
      if (is_string($value)) {
        $coerced = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $coerced ?? $value;
      }
      return $value;
    }

    if ($type === 'datetime_iso8601') {
      return is_string($value) && !is_numeric($value) ? self::datetime($definition, $value) : $value;
    }

    if ($type === 'timestamp') {
      if (is_string($value) && is_numeric($value)) {
        return self::integral($value) ?? $value;
      }
      if (is_float($value)) {
        return self::integral($value) ?? $value;
      }
      if (is_string($value)) {
        try {
          return (new \DateTimeImmutable($value))->getTimestamp();
        }
        catch (\Exception) {
          return $value;
        }
      }
      return $value;
    }

    if ($type === 'integer') {
      if (is_bool($value)) {
        return $value ? 1 : 0;
      }
      if (is_float($value) || (is_string($value) && is_numeric($value))) {
        return self::integral($value) ?? $value;
      }
      return $value;
    }

    if ($type === 'float') {
      return is_string($value) && is_numeric($value) ? (float) $value : $value;
    }

    if ($type === 'string' || $type === 'decimal') {
      if (is_int($value)) {
        return (string) $value;
      }
      if (is_float($value)) {
        return self::decimalString($value);
      }
    }

    return $value;
  }

  /**
   * Answers the whole number a numeric value spells, if it spells one.
   *
   * @param string|float $value
   *   A float, or a numeric string.
   *
   * @return int|null
   *   The integer, or NULL when the number has a fraction or does not
   *   fit an integer.
   */
  private static function integral(string|float $value): ?int {
    if (is_string($value)) {
      $value = trim($value);
      if (preg_match('/^([+-]?)0*(\d+)$/D', $value, $match)) {
        // Digits alone are an integer when they fit one, which is when
        // the integer spells them back.
        $digits = ($match[1] === '-' && $match[2] !== '0' ? '-' : '') . $match[2];
        $integer = (int) $digits;
        return (string) $integer === $digits ? $integer : NULL;
      }
      $value = (float) $value;
    }
    return is_finite($value) && fmod($value, 1.0) === 0.0 && $value >= (float) PHP_INT_MIN && $value < (float) PHP_INT_MAX
      ? (int) $value
      : NULL;
  }

  /**
   * Snaps a near miss onto the one declared choice it matches.
   *
   * @param string $value
   *   The value sent.
   * @param array $choices
   *   The declared choices.
   *
   * @return string|null
   *   The choice, when exactly one matches case- and whitespace-blind
   *   and it differs from what was sent; NULL otherwise.
   */
  private static function snap(string $value, array $choices): ?string {
    $needle = mb_strtolower(trim($value));
    $matches = [];
    foreach ($choices as $choice) {
      if (is_string($choice) && mb_strtolower(trim($choice)) === $needle) {
        $matches[] = $choice;
      }
    }
    return count($matches) === 1 && $matches[0] !== $value ? $matches[0] : NULL;
  }

  /**
   * Re-emits a datetime string in the format its definition declares.
   *
   * A DateOnly constraint declares a date, a DateTime constraint a date
   * and a time in UTC; with neither, a parsable value is written as
   * ISO 8601 and a bare date is left alone. Anything not parsed exactly
   * stays for validation.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param string $value
   *   The value sent.
   *
   * @return string
   *   The value in the declared format, or as it arrived.
   */
  private static function datetime(DataDefinitionInterface $definition, string $value): string {
    $constraints = $definition->getConstraints();
    $date_only = array_key_exists('DateOnly', $constraints);
    if (!$date_only && !array_key_exists('DateTime', $constraints)) {
      if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
        return $value;
      }
      try {
        return (new \DateTimeImmutable($value))->format('c');
      }
      catch (\Exception) {
        return $value;
      }
    }
    foreach ($date_only ? self::DATE_INPUT_FORMATS : self::DATETIME_INPUT_FORMATS as $format) {
      $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $value);
      if ($parsed === FALSE) {
        continue;
      }
      $errors = \DateTimeImmutable::getLastErrors();
      if ($errors !== FALSE && ($errors['errors'] !== [] || $errors['warnings'] !== [])) {
        continue;
      }
      return $date_only
        ? $parsed->format(self::DATE_STORAGE_FORMAT)
        : $parsed->setTimezone(new \DateTimeZone(self::STORAGE_TIMEZONE))->format(self::DATETIME_STORAGE_FORMAT);
    }
    return $value;
  }

  /**
   * Spells a float as a fixed-point decimal string, as the Tool API does.
   *
   * Fifteen significant digits, insignificant trailing zeros trimmed,
   * never exponent notation.
   *
   * @param float $value
   *   The float.
   *
   * @return string
   *   Its spelling.
   */
  private static function decimalString(float $value): string {
    if ($value == 0.0) {
      return '0';
    }
    $decimals = max(0, 14 - (int) floor(log10(abs($value))));
    $formatted = number_format($value, $decimals, '.', '');
    return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
  }

}
