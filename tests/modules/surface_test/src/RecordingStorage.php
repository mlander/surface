<?php

declare(strict_types=1);

namespace Drupal\surface_test;

use Drupal\surface\SurfaceInterface;
use Drupal\surface\Pipeline\SurfaceStorageInterface;
use Drupal\surface\Pipeline\PreparedValues;

/**
 * Storage that writes nothing and records what it was asked to do.
 *
 * Several of these share one log, so what the composite storage did and
 * in which order is readable as a plain list of strings.
 */
final class RecordingStorage implements SurfaceStorageInterface {

  /**
   * The values this storage was last asked to prepare.
   *
   * @var array<string, mixed>
   */
  public array $received = [];

  /**
   * How many times this storage was asked what it holds.
   *
   * Counted rather than logged, so the composite ordering assertions the
   * log exists for keep reading as the list of writes they always were.
   * A stage that must not have run is proved by a zero here.
   *
   * @var int
   */
  public int $loads = 0;

  /**
   * Constructs a RecordingStorage.
   *
   * @param string $name
   *   The name this storage records itself under.
   * @param \ArrayObject<int, string> $log
   *   The shared log every recording storage appends to.
   */
  public function __construct(
    protected readonly string $name,
    protected readonly \ArrayObject $log,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function load(SurfaceInterface $surface): array {
    $this->loads++;
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(SurfaceInterface $surface, array $values): PreparedValues {
    $this->received = $values;
    $this->log[] = $this->name . ' prepare';
    return new PreparedValues($values, $values, ['module' => [$this->name]]);
  }

  /**
   * {@inheritdoc}
   */
  public function commit(PreparedValues $prepared): void {
    $this->log[] = $this->name . ' commit';
  }

}
