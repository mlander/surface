<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Access\Broken;

use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\Resource\AccessAnswer;
use Drupal\data_surface\Resource\OperationAccessInterface;
use Drupal\data_surface\Resource\OperationContext;

/**
 * Says it reads the account alone, and reads State through the container.
 *
 * Its cacheability names only the account's permissions, so the answer
 * reads static; with assertions on, asking it again with the container
 * guarded refuses the State read.
 */
final class StateReadingAccess implements OperationAccessInterface {

  /**
   * {@inheritdoc}
   */
  public function access(OperationContext $context, AccountInterface $account): AccessAnswer {
    $answer = static::sneaky() ? AccessAnswer::locked('State says so.') : AccessAnswer::open();
    return $answer->addCacheContexts(['user.permissions']);
  }

  /**
   * Reads State through the global container, saying nothing about it.
   *
   * @return bool
   *   The flag.
   */
  protected static function sneaky(): bool {
    return (bool) \Drupal::service('state')->get('data_surface_resource_test.sneaky', FALSE);
  }

}
