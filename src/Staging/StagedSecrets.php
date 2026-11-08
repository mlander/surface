<?php

declare(strict_types=1);

namespace Drupal\surface\Staging;

use Drupal\Component\Utility\NestedArray;
use Drupal\surface\Pipeline\Outbound;
use Drupal\surface\Pipeline\SurfacePipelineInterface;
use Drupal\surface\Storage\SecretCodecException;
use Drupal\surface\Storage\SecretCodecInterface;
use Drupal\surface\Pipeline\ValueState;

/**
 * Seals the secrets a stage records, and opens them by recorded path.
 *
 * A stage entry holds the whole document the pipeline merged, the
 * secrets stored for the thing among them. Before the record is written
 * each string at a secret path is sealed with the site's codec, and the
 * paths sealed are recorded on the entry under `sealed`: a list of paths,
 * each a list of segments, a list position as a string. Only those paths
 * are opened again, at a load through the stage and at apply, never a
 * value found to look like an envelope: a caller could place an envelope
 * at a key that is not secret, and apply would open it and store the
 * secret in the clear.
 *
 * A secret cleared in the stage is NULL in its values, which apply would
 * read as "keep what is stored". Its path is recorded under `cleared`,
 * and apply sends the clear marker there.
 *
 * Decision: see
 * docs/decisions.md#a-secret-is-sealed-in-the-stage-record-by-its-recorded-paths.
 *
 * @internal
 */
final class StagedSecrets {

  /**
   * Lists every path at which a secret sits in a set of values.
   *
   * @param array $values
   *   The values, keyed like the data surface.
   * @param array<string, true|array> $secrets
   *   What Outbound::secrets() found.
   * @param list<string> $parents
   *   The path to the values, for a nested call.
   *
   * @return list<list<string>>
   *   Each path, its segments as strings: a list of secrets is one path
   *   per item, and a list of maps one path per item's secret.
   */
  public static function paths(array $values, array $secrets, array $parents = []): array {
    $found = [];
    $each = $secrets[Outbound::EACH] ?? NULL;
    unset($secrets[Outbound::EACH]);
    if (is_array($each)) {
      foreach ($values as $delta => $item) {
        if (is_array($item)) {
          $found = [...$found, ...self::paths($item, $each, [...$parents, (string) $delta])];
        }
      }
    }
    foreach ($secrets as $name => $secret) {
      $name = (string) $name;
      if (!array_key_exists($name, $values)) {
        continue;
      }
      $path = [...$parents, $name];
      if ($secret !== TRUE) {
        if (is_array($values[$name])) {
          $found = [...$found, ...self::paths($values[$name], $secret, $path)];
        }
        continue;
      }
      if (!is_array($values[$name])) {
        $found[] = $path;
        continue;
      }
      // A list whose items are secret.
      foreach (array_keys($values[$name]) as $delta) {
        $found[] = [...$path, (string) $delta];
      }
    }
    return $found;
  }

  /**
   * Seals every secret in a set of values.
   *
   * Each string at a secret path is encrypted, an envelope a caller sent
   * wrapped again, so opening it answers exactly what was sent; NULL and
   * the empty string are kept as they are.
   *
   * @param \Drupal\surface\Storage\SecretCodecInterface $codec
   *   The codec.
   * @param array $values
   *   The values, in the clear.
   * @param array<string, true|array> $secrets
   *   What Outbound::secrets() found.
   *
   * @return array{0: array, 1: list<list<string>>}
   *   The values sealed, and the paths sealed.
   *
   * @throws \Drupal\surface\Storage\SecretCodecException
   *   When a secret path holds what is not a string, or the codec has no
   *   key.
   */
  public static function seal(SecretCodecInterface $codec, array $values, array $secrets): array {
    $sealed = [];
    foreach (self::paths($values, $secrets) as $path) {
      $value = NestedArray::getValue($values, $path);
      if ($value === NULL || $value === '') {
        continue;
      }
      if (!is_string($value)) {
        throw new SecretCodecException(sprintf(
          'The secret at "%s" holds a %s, and a secret holds one string. Declare it as a string definition, or stop declaring it secret.',
          implode('.', $path),
          get_debug_type($value),
        ));
      }
      NestedArray::setValue($values, $path, $codec->encrypt($value));
      $sealed[] = $path;
    }
    return [$values, $sealed];
  }

  /**
   * Opens the secrets an entry recorded it sealed.
   *
   * @param \Drupal\surface\Storage\SecretCodecInterface $codec
   *   The codec.
   * @param array $values
   *   The entry's values.
   * @param array $sealed
   *   The entry's `sealed` paths.
   *
   * @return array
   *   The values in the clear.
   *
   * @throws \Drupal\surface\Storage\SecretCodecException
   *   When the codec cannot open one: the hash salt it was sealed under
   *   changed, or the record was tampered with.
   */
  public static function unseal(SecretCodecInterface $codec, array $values, array $sealed): array {
    foreach ($sealed as $path) {
      $path = array_map('strval', (array) $path);
      $value = NestedArray::getValue($values, $path, $exists);
      if ($exists && is_string($value)) {
        NestedArray::setValue($values, $path, $codec->decrypt($value));
      }
    }
    return $values;
  }

  /**
   * Lists the secret paths a write cleared.
   *
   * A secret path whose value the write leaves empty where the view it
   * was merged over held one: keep on empty never yields an empty value
   * for a secret that held one, so the write sent the clear marker. Only
   * a key the write holds: every parent on the path is an array in its
   * values and the last key is present. A missing key, or a parent that
   * is missing or not an array (a map set to NULL, a list that shrank),
   * removed the secret with its parent, and is no clear.
   *
   * @param array $values
   *   What the write prepared, in the clear.
   * @param array $current
   *   What it was merged over: what the stage held, or what was stored.
   * @param array<string, true|array> $secrets
   *   What Outbound::secrets() found.
   *
   * @return list<list<string>>
   *   The paths.
   */
  public static function cleared(array $values, array $current, array $secrets): array {
    $cleared = [];
    foreach (self::paths($current, $secrets) as $path) {
      if (ValueState::isConfigured(NestedArray::getValue($current, $path)) && self::holds($values, $path) && !ValueState::isConfigured(NestedArray::getValue($values, $path))) {
        $cleared[] = $path;
      }
    }
    return $cleared;
  }

  /**
   * Joins the paths a write cleared to an entry's, less those set again.
   *
   * A path whose parent the write's values no longer hold as an array is
   * dropped, as cleared() would not record it.
   *
   * @param array $recorded
   *   The entry's `cleared` paths.
   * @param list<list<string>> $cleared
   *   The paths this write cleared.
   * @param array $values
   *   What this write prepared, in the clear.
   *
   * @return list<list<string>>
   *   The paths still cleared.
   */
  public static function stillCleared(array $recorded, array $cleared, array $values): array {
    $paths = [];
    foreach ([...$recorded, ...$cleared] as $path) {
      $path = array_map('strval', (array) $path);
      if (self::parentsHeld($values, $path) && !ValueState::isConfigured(NestedArray::getValue($values, $path))) {
        $paths[implode("\0", $path)] = $path;
      }
    }
    return array_values($paths);
  }

  /**
   * Sends the clear marker at each cleared path of an apply's input.
   *
   * Only where every parent on the path is an array in the input: a key
   * the stage did not move is not sent, and keeps what is stored, and a
   * parent the input removed or holds as a scalar is not built again.
   *
   * @param array $input
   *   What apply sends.
   * @param array $cleared
   *   The entry's `cleared` paths.
   *
   * @return array
   *   The input, SurfacePipelineInterface::CLEAR_SECRET at each.
   */
  public static function clearing(array $input, array $cleared): array {
    foreach ($cleared as $path) {
      $path = array_map('strval', (array) $path);
      if ($path !== [] && array_key_exists($path[0], $input) && self::parentsHeld($input, $path)) {
        NestedArray::setValue($input, $path, SurfacePipelineInterface::CLEAR_SECRET);
      }
    }
    return $input;
  }

  /**
   * Answers whether a set of values holds the last key of a path.
   *
   * @param array $values
   *   The values.
   * @param list<string> $path
   *   The path.
   *
   * @return bool
   *   TRUE when every parent is an array and the last key is present.
   */
  protected static function holds(array $values, array $path): bool {
    if ($path === [] || !self::parentsHeld($values, $path)) {
      return FALSE;
    }
    $parent = NestedArray::getValue($values, array_slice($path, 0, -1));
    return array_key_exists((string) end($path), $parent);
  }

  /**
   * Answers whether every parent on a path is an array in a set of values.
   *
   * @param array $values
   *   The values.
   * @param list<string> $path
   *   The path.
   *
   * @return bool
   *   TRUE when each key above the last is present and holds an array.
   */
  protected static function parentsHeld(array $values, array $path): bool {
    $at = $values;
    foreach (array_slice($path, 0, -1) as $key) {
      if (!array_key_exists($key, $at) || !is_array($at[$key])) {
        return FALSE;
      }
      $at = $at[$key];
    }
    return TRUE;
  }

}
