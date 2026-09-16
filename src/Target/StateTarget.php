<?php

declare(strict_types=1);

namespace Drupal\data_surface\Target;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\State\StateInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;
use Drupal\data_surface\Pipeline\PreparedValues;

/**
 * Stores a surface's values under one State key.
 *
 * The simplest target there is: the artifact is the accepted values
 * themselves. Handy for a standalone demo and for tests that need a real
 * write without a config object or an entity behind it.
 *
 * @see docs/targets.md
 */
final class StateTarget implements DataSurfaceTargetInterface {

  use DependencySerializationTrait;

  /**
   * Constructs a StateTarget.
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
  public function load(DataSurfaceInterface $surface): array {
    $values = $this->state->get($this->key);
    return is_array($values) ? $values : [];
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(DataSurfaceInterface $surface, array $values): PreparedValues {
    return new PreparedValues($values, $values);
  }

  /**
   * {@inheritdoc}
   */
  public function commit(PreparedValues $prepared): void {
    $this->state->set($this->key, $prepared->artifact);
  }

}
