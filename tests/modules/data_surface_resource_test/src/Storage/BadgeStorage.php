<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Storage;

use Drupal\Core\State\StateInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceStorageInterface;

/**
 * Keeps the one badge in state.
 */
final class BadgeStorage implements ResourceStorageInterface {

  /**
   * The state key the badge is kept under.
   */
  public const KEY = 'data_surface_resource_test.badge';

  /**
   * Constructs a BadgeStorage.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service, autowired.
   */
  public function __construct(protected readonly StateInterface $state) {}

  /**
   * {@inheritdoc}
   */
  public function load(OperationContext $context): array {
    return $this->state->get(self::KEY, []);
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(OperationContext $context, array $values): array {
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function commit(OperationContext $context, array $prepared): void {
    $this->state->set(self::KEY, $prepared);
  }

}
