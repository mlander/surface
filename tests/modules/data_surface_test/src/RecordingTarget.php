<?php

declare(strict_types=1);

namespace Drupal\data_surface_test;

use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;
use Drupal\data_surface\Pipeline\PreparedValues;

/**
 * A target that writes nothing and records what it was asked to do.
 *
 * Several of these share one log, so what the composite target did and
 * in which order is readable as a plain list of strings.
 */
final class RecordingTarget implements DataSurfaceTargetInterface {

  /**
   * The values the target was last asked to prepare.
   *
   * @var array<string, mixed>
   */
  public array $received = [];

  /**
   * How many times the target was asked what storage holds.
   *
   * Counted rather than logged, so the composite ordering assertions the
   * log exists for keep reading as the list of writes they always were.
   * A stage that must not have run is proved by a zero here.
   *
   * @var int
   */
  public int $loads = 0;

  /**
   * Constructs a RecordingTarget.
   *
   * @param string $name
   *   The name the target records itself under.
   * @param \ArrayObject<int, string> $log
   *   The shared log every recording target appends to.
   */
  public function __construct(
    protected readonly string $name,
    protected readonly \ArrayObject $log,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function load(DataSurfaceInterface $surface): array {
    $this->loads++;
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(DataSurfaceInterface $surface, array $values): PreparedValues {
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
