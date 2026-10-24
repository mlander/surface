<?php

declare(strict_types=1);

namespace Drupal\data_surface_door_test\Storage\Broken;

use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceStorageInterface;

/**
 * Rehearses a write by what the request carries: refused.
 *
 * A form posts a request and a tool call has none of its own, so a
 * storage that reads it writes one thing at one door and another at the
 * next.
 */
final class RequestReadingStorage implements ResourceStorageInterface {

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
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    return $values + ['path' => \Drupal::request()->getPathInfo()];
  }

  /**
   * {@inheritdoc}
   */
  public function commit(OperationContext $context, array $prepared, AccountInterface $account): void {
  }

}
