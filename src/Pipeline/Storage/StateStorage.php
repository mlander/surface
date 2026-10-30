<?php

declare(strict_types=1);

namespace Drupal\surface\Pipeline\Storage;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\State\StateInterface;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Pipeline\SurfaceStorageInterface;
use Drupal\surface\Pipeline\PreparedValues;

/**
 * Stores a data surface's values under one State key.
 *
 * The simplest storage there is: the artifact is the accepted values
 * themselves. Handy for a standalone demo and for tests that need a real
 * write without a config object or an entity behind it.
 *
 * @see docs/storage.md
 */
final class StateStorage implements SurfaceStorageInterface {

  use DependencySerializationTrait;

  /**
   * Constructs a StateStorage.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state store.
   * @param string $key
   *   The state key holding the values.
   */
  public function __construct(
    protected readonly StateInterface $state,
    protected readonly string $key,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function load(SurfaceInterface $surface): array {
    $values = $this->state->get($this->key);
    return is_array($values) ? $values : [];
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
    $this->state->set($this->key, $prepared->artifact);
  }

}
