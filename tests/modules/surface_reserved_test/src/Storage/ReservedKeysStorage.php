<?php

declare(strict_types=1);

namespace Drupal\surface_reserved_test\Storage;

use Drupal\Core\Session\AccountInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceStorageInterface;

/**
 * Keeps nothing: the resource it serves is refused before it is built.
 */
final class ReservedKeysStorage implements ResourceStorageInterface {

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
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function commit(OperationContext $context, array $prepared, AccountInterface $account): void {}

}
