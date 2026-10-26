<?php

declare(strict_types=1);

namespace Drupal\data_surface\Drush\Generators\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Writes a value as PHP source, the way this module's classes write it.
 *
 * A scalar as its literal (`TRUE`, `NULL`, a single-quoted string); a
 * text a person reads, `t('...')`; an array short and inline while every
 * value in it is a plain scalar, one entry a line otherwise, with a
 * trailing comma, each level two spaces in.
 */
final class PhpLiteral {

  /**
   * The longest an inline array may be.
   */
  protected const INLINE = 60;

  /**
   * Writes a value.
   *
   * @param mixed $value
   *   The value: a scalar, a translatable text, or an array of them.
   * @param int $indent
   *   The indentation of the line the value starts on.
   * @param callable|null $translate
   *   Says, of a string at a path of keys, whether a person reads it: it
   *   is then written as `t()`. NULL translates nothing.
   * @param array<int, int|string> $path
   *   The keys above the value.
   *
   * @return string
   *   The source; lines after the first indented from $indent.
   */
  public static function write(mixed $value, int $indent = 0, ?callable $translate = NULL, array $path = []): string {
    if ($value instanceof TranslatableMarkup) {
      return static::translated($value->getUntranslatedString(), $value->getArguments());
    }
    if (is_string($value) && $translate !== NULL && $translate($path)) {
      return static::translated($value);
    }
    if (!is_array($value)) {
      return static::scalar($value);
    }
    if ($value === []) {
      return '[]';
    }
    $list = array_is_list($value);
    if (static::plain($value, $translate, $path)) {
      $inline = static::inline($value);
      if (strlen($inline) <= self::INLINE) {
        return $inline;
      }
    }
    $pad = str_repeat(' ', $indent + 2);
    $lines = ['['];
    foreach ($value as $key => $item) {
      $below = [...$path, $key];
      $lines[] = $pad . ($list ? '' : static::scalar($key) . ' => ') . static::write($item, $indent + 2, $translate, $below) . ',';
    }
    $lines[] = str_repeat(' ', $indent) . ']';
    return implode("\n", $lines);
  }

  /**
   * Answers whether an array holds plain scalars alone, at every depth.
   *
   * @param array $value
   *   The array.
   * @param callable|null $translate
   *   What says a string is a text a person reads.
   * @param array<int, int|string> $path
   *   The keys above it.
   *
   * @return bool
   *   TRUE when nothing in it is translated or an object.
   */
  protected static function plain(array $value, ?callable $translate, array $path): bool {
    foreach ($value as $key => $item) {
      $below = [...$path, $key];
      $translated = is_string($item) && $translate !== NULL && $translate($below);
      if (is_array($item) ? !static::plain($item, $translate, $below) : (is_object($item) || $translated)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Writes an array of plain scalars on one line.
   *
   * @param array $value
   *   The array.
   *
   * @return string
   *   The source.
   */
  protected static function inline(array $value): string {
    $list = array_is_list($value);
    $items = [];
    foreach ($value as $key => $item) {
      $items[] = ($list ? '' : static::scalar($key) . ' => ') . (is_array($item) ? static::inline($item) : static::scalar($item));
    }
    return '[' . implode(', ', $items) . ']';
  }

  /**
   * Writes a scalar.
   *
   * @param mixed $value
   *   The value.
   *
   * @return string
   *   Its literal.
   */
  public static function scalar(mixed $value): string {
    return match (TRUE) {
      $value === NULL => 'NULL',
      $value === TRUE => 'TRUE',
      $value === FALSE => 'FALSE',
      is_int($value) => (string) $value,
      is_float($value) => var_export($value, TRUE),
      $value instanceof \Stringable => static::string((string) $value),
      default => static::string((string) $value),
    };
  }

  /**
   * Writes a string, single-quoted.
   *
   * @param string $value
   *   The string.
   *
   * @return string
   *   Its literal.
   */
  public static function string(string $value): string {
    return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
  }

  /**
   * Writes a text a person reads.
   *
   * @param string $text
   *   The text, untranslated.
   * @param array $arguments
   *   Its placeholders' values.
   *
   * @return string
   *   `t('...')`.
   */
  public static function translated(string $text, array $arguments = []): string {
    return 't(' . static::string($text) . ($arguments === [] ? '' : ', ' . static::write(array_map(static fn (mixed $argument): mixed => is_scalar($argument) ? $argument : (string) $argument, $arguments))) . ')';
  }

}
