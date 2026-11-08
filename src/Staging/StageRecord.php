<?php

declare(strict_types=1);

namespace Drupal\surface\Staging;

use Drupal\Core\Site\Settings;

/**
 * The shape of a stage's record: the one thing every store shares.
 *
 * A record is a PHP array with no objects, written whole by the stage's
 * storage (StageStorage, StagedStorage) and kept by a store
 * (StageStoreInterface). No store may change its shape. Its keys:
 * - `version`: VERSION. A store reads a record of another version as
 *   absent (readable()).
 * - `id`: the stage's id.
 * - `owner`: the id of the account that opened it.
 * - `label`: its label.
 * - `status`: OPEN (`open`) while it takes writes, APPLIED (`applied`)
 *   once applied, VOIDED (`voided`) once an apply failed after a schema
 *   change and could not be rolled back.
 * - `opened`, `updated`: when it was opened and last written, from the
 *   store's now().
 * - `keep`: the seconds the stage lives after its last write, resolved
 *   at open by keep() from SHORT, LONG or seconds. A record written
 *   before stages carried `keep` has none, and its store gives it the
 *   store's default.
 * - `applied_at`, `applied`: when it was applied and what the apply made.
 * - `voided`: on a VOIDED record only, `position`, the write whose apply
 *   failed.
 * - `next`: the position the next write takes.
 * - `writes`: the stage's entries, in order. A create entry may carry
 *   `address` (address()).
 *
 * Every store answers StageStoreInterface::created() and createdAt()
 * from its read() through created() and createdAt() here, so the rules
 * that find a staged create stay the same in every store.
 *
 * Decision: see
 * docs/decisions.md#one-store-contract-a-stages-lifetime-is-data-on-its-record.
 */
final class StageRecord {

  /**
   * The version of the record's shape, which every store reads and writes.
   */
  public const VERSION = 1;

  /**
   * A stage's status while it takes writes.
   */
  public const OPEN = 'open';

  /**
   * A stage's status once its writes are applied.
   */
  public const APPLIED = 'applied';

  /**
   * A stage's status once an apply failed after a schema change.
   *
   * Its writes could not be rolled back, so it holds none and takes none.
   */
  public const VOIDED = 'voided';

  /**
   * The short lifetime: the form cache's, for a flow finished at once.
   */
  public const SHORT = 'short';

  /**
   * The long lifetime: the temp store's, the default.
   */
  public const LONG = 'long';

  /**
   * The longest lifetime a record is given: ten years, in seconds.
   *
   * Larger lifetimes are cut to it, so a time to live added to the
   * current time stays within a PHP integer.
   */
  public const MAX = 315360000;

  /**
   * Resolves a lifetime to the seconds a record keeps.
   *
   * @param mixed $keep
   *   SHORT, LONG, or a positive integer of seconds.
   * @param int $long
   *   The seconds of LONG, the `tempstore.expire` container parameter.
   *
   * @return int
   *   For SHORT, the `form_cache_expiration` setting (21600, six hours,
   *   by default); for LONG, $long; seconds as given. Never less than 1
   *   and never more than MAX.
   *
   * @throws \InvalidArgumentException
   *   When it is neither name nor a positive integer: a float, an array,
   *   zero or less, or another string.
   */
  public static function keep(mixed $keep, int $long): int {
    $seconds = match (TRUE) {
      $keep === self::SHORT => max(1, (int) Settings::get('form_cache_expiration', 21600)),
      $keep === self::LONG => max(1, $long),
      is_int($keep) && $keep > 0 => $keep,
      default => throw new \InvalidArgumentException(sprintf('A stage\'s lifetime is "%s", "%s" or a positive integer of seconds, not %s.', self::SHORT, self::LONG, var_export($keep, TRUE))),
    };
    return min($seconds, self::MAX);
  }

  /**
   * Reads the address a create entry was given, by identity key.
   *
   * Written once, at the create's first commit: `-position` for each
   * identity key its context knew to be empty, the name the caller gave
   * for each it did not know. An entry staged before addresses were
   * written has none, and is read as `-position` for each key its context
   * knew to be empty.
   *
   * @param array $write
   *   A create entry.
   *
   * @return array<string, int|string>
   *   The address.
   */
  public static function address(array $write): array {
    if (isset($write['address']) && is_array($write['address'])) {
      return $write['address'];
    }
    $empty = array_filter((array) ($write['known'] ?? []), static fn (mixed $value): bool => $value === NULL);
    return array_map(static fn (): int => -(int) $write['position'], $empty);
  }

  /**
   * Finds the create an open record holds of a resource, by address.
   *
   * What StageStoreInterface::created() answers, from the record a store
   * read: the create entry whose every address key is among the
   * arguments and equal to it as text.
   *
   * @param array|null $record
   *   The record, or NULL for none.
   * @param string $resource
   *   The resource's id.
   * @param array<string, mixed> $arguments
   *   The operation's arguments, by name.
   *
   * @return array|null
   *   The entry, or NULL when there is no open record or no such create.
   */
  public static function created(?array $record, string $resource, array $arguments): ?array {
    if ($record === NULL || $record['status'] !== self::OPEN) {
      return NULL;
    }
    foreach ((array) $record['writes'] as $write) {
      $address = $write['kind'] === 'create' && $write['resource'] === $resource ? self::address($write) : [];
      if ($address === []) {
        continue;
      }
      foreach ($address as $key => $value) {
        $given = $arguments[$key] ?? NULL;
        if (!is_scalar($given) || (string) $given !== (string) $value) {
          continue 2;
        }
      }
      return $write;
    }
    return NULL;
  }

  /**
   * Finds the create an open record holds of a resource, by position.
   *
   * What StageStoreInterface::createdAt() answers, from the record a
   * store read.
   *
   * @param array|null $record
   *   The record, or NULL for none.
   * @param string $resource
   *   The resource's id.
   * @param int $position
   *   The create's position.
   *
   * @return array|null
   *   The entry, or NULL when there is no open record or no such create.
   */
  public static function createdAt(?array $record, string $resource, int $position): ?array {
    if ($record === NULL || $record['status'] !== self::OPEN) {
      return NULL;
    }
    foreach ((array) $record['writes'] as $write) {
      if ($write['kind'] === 'create' && $write['resource'] === $resource && (int) $write['position'] === $position) {
        return $write;
      }
    }
    return NULL;
  }

  /**
   * Answers whether a record is one a store returns from a read.
   *
   * @param mixed $record
   *   What the store holds under the id.
   * @param string $id
   *   The id read.
   *
   * @return bool
   *   TRUE when it is an array of this version under this id.
   */
  public static function readable(mixed $record, string $id): bool {
    return $id !== '' && is_array($record) && ($record['version'] ?? NULL) === self::VERSION && ($record['id'] ?? NULL) === $id;
  }

}
