<?php

declare(strict_types=1);

namespace Drupal\surface\Staging;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\surface\ResourceBuild\NamesNothingException;

/**
 * The shipped store: one record per stage, until it expires.
 *
 * The `surface.stages` service. Each record is kept under the stage's id
 * in the expirable key-value collection `surface.stage`, for its `keep`
 * seconds after its last write; a record with no `keep` is kept for the
 * temp store expiry (`tempstore.expire`), the $expire it is given. Every
 * write starts the time again; a read ignores a record that expired. The
 * record names its owner: who may use a stage is the stage's access
 * class's answer, which the door asks, so the store takes no account and
 * reads none.
 *
 * Writes to one record are serialized by a lock named for the stage, on
 * core's `lock` service, taken and released around each write: a staged
 * write's commit, and the stage's own create, update, apply and delete.
 *
 * It does not implement ListsStagesInterface: the expirable collection
 * has no query by owner, and listing an account's stages would read
 * every stage of every account.
 *
 * Decision: see
 * docs/decisions.md#a-stage-is-a-resource-of-the-base-module-kept-in-an-expirable-key-value-collection.
 */
final class StageStore implements StageStoreInterface {

  /**
   * The expirable key-value collection the records are kept in.
   *
   * @internal
   */
  public const COLLECTION = 'surface.stage';

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
   *   How long a record with no `keep` is kept after its last write, in
   *   seconds.
   */
  public function __construct(
    protected readonly KeyValueExpirableFactoryInterface $keyValue,
    protected readonly LockBackendInterface $lock,
    protected readonly TimeInterface $time,
    protected readonly int $expire,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function named(string $id): Stage {
    $record = $this->read($id);
    if ($record === NULL) {
      throw new NamesNothingException(sprintf('There is no stage "%s": it expired, was discarded or never was.', $id), 'stage');
    }
    return new Stage($record['id'], (int) $record['owner'], $record['label'] ?? NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function read(string $id): ?array {
    if ($id === '') {
      return NULL;
    }
    $record = $this->records()->get($id);
    return StageRecord::readable($record, $id) ? $record : NULL;
  }

  /**
   * {@inheritdoc}
   *
   * One record read.
   */
  public function created(Stage $stage, string $resource, array $arguments): ?array {
    return StageRecord::created($this->read($stage->id), $resource, $arguments);
  }

  /**
   * {@inheritdoc}
   */
  public function createdAt(Stage $stage, string $resource, int $position): ?array {
    return StageRecord::createdAt($this->read($stage->id), $resource, $position);
  }

  /**
   * {@inheritdoc}
   *
   * Kept for the record's `keep` seconds, or for $expire when it has
   * none, starting now.
   */
  public function write(array $record): void {
    $keep = $record['keep'] ?? NULL;
    $this->records()->setWithExpire((string) $record['id'], $record, is_int($keep) && $keep > 0 ? $keep : $this->expire);
  }

  /**
   * {@inheritdoc}
   */
  public function delete(string $id): void {
    $this->records()->delete($id);
  }

  /**
   * {@inheritdoc}
   *
   * The lock is named for the collection and the stage, and the wait is
   * WAIT seconds.
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
   * {@inheritdoc}
   */
  public function release(string $id): void {
    $this->lock->release(self::COLLECTION . ':' . $id);
  }

  /**
   * {@inheritdoc}
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
