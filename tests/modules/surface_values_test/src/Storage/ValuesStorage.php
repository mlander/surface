<?php

declare(strict_types=1);

namespace Drupal\surface_values_test\Storage;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceStorageInterface;

/**
 * Keeps the value baseline's resource in State; the baseline never writes.
 */
final class ValuesStorage implements ResourceStorageInterface {

  /**
   * The State key the values are kept under.
   */
  public const KEY = 'surface_values_test.values';

  /**
   * Constructs the storage.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The State service.
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
  public function prepare(OperationContext $context, array $values, AccountInterface $account): array {
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function commit(OperationContext $context, array $prepared, AccountInterface $account): void {
    $this->state->set(self::KEY, $prepared);
  }

}
