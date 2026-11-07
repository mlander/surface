<?php

declare(strict_types=1);

namespace Drupal\surface\Staging;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\surface\ResourceBuild\NamesNothingException;

/**
 * Where the stages are kept: one record per stage, until it expires.
 *
 * The `surface.stages` service. Each record is a PHP array with no
 * objects, under the stage's id in the expirable key-value collection
 * `surface.stage`, written with the site's temp store expiry
 * (`tempstore.expire`), which every write resets; a read ignores a
 * record that expired. The record names its owner: who may use a stage
 * is the stage's access class's answer, which the door asks, so the store
 * takes no account and reads none.
 *
 * Writes to one record are serialized by a lock named for the stage,
 * taken and released inside one commit.
 *
 * Decision: see
 * docs/decisions.md#a-stage-is-a-resource-of-the-base-module-kept-in-an-expirable-key-value-collection.
 */
final class StageStore {

  /**
   * The expirable key-value collection the records are kept in.
   *
   * @internal
   */
  public const COLLECTION = 'surface.stage';

  /**
   * The version of the record's shape this store reads and writes.
   *
   * @internal
   */
  public const VERSION = 1;

  /**
   * How long a write waits, in seconds, for a lock another request holds.
   *
   * @internal
   */
  public const WAIT = 2;

  /**
   * Constructs a StageStore.
   *
   * @param \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface $keyValue
   *   The expirable key-value factory.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock backend.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time, for when a record is written.
   * @param int $expire
   *   How long a record is kept after its last write, in seconds.
   */
  public function __construct(
    protected readonly KeyValueExpirableFactoryInterface $keyValue,
    protected readonly LockBackendInterface $lock,
    protected readonly TimeInterface $time,
    protected readonly int $expire,
  ) {}

  /**
   * Gets the stage an id names, for a storage to be handed.
   *
   * @param string $id
   *   The stage's id.
   *
   * @return \Drupal\surface\Staging\Stage
   *   The stage, with no arguments yet.
   *
   * @throws \Drupal\surface\ResourceBuild\NamesNothingException
   *   At `stage`, when no stage has the id, or it expired.
   */
  public function named(string $id): Stage {
    $record = $this->read($id);
    if ($record === NULL) {
      throw new NamesNothingException(sprintf('There is no stage "%s": it expired, was discarded or never was.', $id), 'stage');
    }
    return new Stage($record['id'], (int) $record['owner'], $record['label'] ?? NULL);
  }

  /**
   * Reads a stage's record.
   *
   * @param string $id
   *   The stage's id.
   *
   * @return array|null
   *   The record, or NULL when there is none, it expired, or it is of a
   *   shape this store does not read.
   *
   * @internal
   */
  public function read(string $id): ?array {
    if ($id === '') {
      return NULL;
    }
    $record = $this->records()->get($id);
    if (!is_array($record) || ($record['version'] ?? NULL) !== self::VERSION || ($record['id'] ?? NULL) !== $id) {
      return NULL;
    }
    return $record;
  }

  /**
   * Writes a stage's record, its expiry starting again.
   *
   * @param array $record
   *   The record, which names its id.
   *
   * @internal
   */
  public function write(array $record): void {
    $this->records()->setWithExpire((string) $record['id'], $record, $this->expire);
  }

  /**
   * Deletes a stage's record.
   *
   * @param string $id
   *   The stage's id.
   *
   * @internal
   */
  public function delete(string $id): void {
    $this->records()->delete($id);
  }

  /**
   * Takes the lock of one stage, waiting once for another request's.
   *
   * @param string $id
   *   The stage's id.
   *
   * @return bool
   *   TRUE when this request holds it; FALSE when another still did after
   *   the wait.
   *
   * @internal
   */
  public function lock(string $id): bool {
    $name = self::COLLECTION . ':' . $id;
    if ($this->lock->acquire($name)) {
      return TRUE;
    }
    $this->lock->wait($name, self::WAIT);
    return $this->lock->acquire($name);
  }

  /**
   * Releases the lock of one stage.
   *
   * @param string $id
   *   The stage's id.
   *
   * @internal
   */
  public function release(string $id): void {
    $this->lock->release(self::COLLECTION . ':' . $id);
  }

  /**
   * Gets the time a record is written at.
   *
   * @return int
   *   The request time.
   *
   * @internal
   */
  public function now(): int {
    return $this->time->getRequestTime();
  }

  /**
   * Gets the collection.
   *
   * @return \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface
   *   The records, by stage id.
   */
  protected function records(): KeyValueStoreExpirableInterface {
    return $this->keyValue->get(self::COLLECTION);
  }

}
