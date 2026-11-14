<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\HandCorpus;

use Drupal\surface\Pipeline\PreparedValues;
use Drupal\surface\Pipeline\SurfaceStorageInterface;
use Drupal\surface\SurfaceInterface;

/**
 * What a hand-built surface's storage holds, for the door corpus.
 *
 * It loads what it was handed, prepares what it is handed and commits
 * nothing.
 */
final class HandStorage implements SurfaceStorageInterface {

  /**
   * Constructs the storage.
   *
   * @param array<string, mixed> $stored
   *   What it holds.
   */
  public function __construct(protected readonly array $stored) {
  }

  /**
   * {@inheritdoc}
   */
  public function load(SurfaceInterface $surface): array {
    return $this->stored;
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(SurfaceInterface $surface, array $values): PreparedValues {
    return new PreparedValues($values, $values);
  }

  /**
   * {@inheritdoc}
   */
  public function commit(PreparedValues $prepared): void {
  }

}
