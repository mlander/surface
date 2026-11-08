<?php

declare(strict_types=1);

namespace Drupal\surface_stage_test\Staging;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\surface\ResourceBuild\NamesNothingException;
use Drupal\surface\Staging\ListsStagesInterface;
use Drupal\surface\Staging\Stage;
use Drupal\surface\Staging\StageRecord;
use Drupal\surface\Staging\StageStoreInterface;

/**
 * A second store: the records in memory, kept for good, and listed.
 *
 * What a test swaps in for `surface.stages` to show the engine asks no
 * more of a store than StageStoreInterface. Records live in a static
 * array for the PHP process, so every instance of the class shares them.
 * It ignores `keep`, as a store that keeps stages for good would, and
 * every lock is free, as one request is all a test runs.
 */
final class ArrayStageStore implements StageStoreInterface, ListsStagesInterface {

  /**
   * The records, by stage id.
   *
   * @var array<string, array>
   */
  public static array $records = [];

  /**
   * Constructs an ArrayStageStore.
   *
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time, for when a record is written.
   */
  public function __construct(protected readonly TimeInterface $time) {}

  /**
   * {@inheritdoc}
   */
  public function named(string $id): Stage {
    $record = $this->read($id) ?? throw new NamesNothingException(sprintf('There is no stage "%s".', $id), 'stage');
    return new Stage($record['id'], (int) $record['owner'], $record['label'] ?? NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function read(string $id): ?array {
    $record = self::$records[$id] ?? NULL;
    return StageRecord::readable($record, $id) ? $record : NULL;
  }

  /**
   * {@inheritdoc}
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
   */
  public function write(array $record): void {
    self::$records[(string) $record['id']] = $record;
  }

  /**
   * {@inheritdoc}
   */
  public function delete(string $id): void {
    unset(self::$records[$id]);
  }

  /**
   * {@inheritdoc}
   */
  public function lock(string $id): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function release(string $id): void {}

  /**
   * {@inheritdoc}
   */
  public function now(): int {
    return $this->time->getRequestTime();
  }

  /**
   * {@inheritdoc}
   */
  public function openStages(int $owner): array {
    $open = array_filter(self::$records, static fn (array $record): bool => (int) $record['owner'] === $owner && $record['status'] === StageRecord::OPEN);
    // Newest first: by when it was opened, then the last added first.
    $order = array_reverse(array_keys($open));
    usort($order, static fn (string $a, string $b): int => $open[$b]['opened'] <=> $open[$a]['opened']);
    return $order;
  }

}
