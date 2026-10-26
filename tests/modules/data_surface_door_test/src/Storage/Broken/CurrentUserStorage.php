<?php

declare(strict_types=1);

namespace Drupal\data_surface_door_test\Storage\Broken;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceStorageInterface;

/**
 * Built with the current user, beside the account each verb is handed.
 *
 * Refused when it is first used: the account a write is made as is the
 * one the operation hands in.
 */
final class CurrentUserStorage implements ResourceStorageInterface {

  /**
   * Constructs a CurrentUserStorage.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user, autowired.
   */
  public function __construct(protected readonly AccountProxyInterface $currentUser) {}

  /**
   * {@inheritdoc}
   */
  public function load(OperationContext $context): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(OperationContext $context, array $values, AccountInterface $account): array {
    return $values + ['by' => (int) $this->currentUser->id()];
  }

  /**
   * {@inheritdoc}
   */
  public function commit(OperationContext $context, array $prepared, AccountInterface $account): void {
  }

}
