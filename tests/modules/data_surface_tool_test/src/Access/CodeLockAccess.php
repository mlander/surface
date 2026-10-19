<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool_test\Access;

use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\Resource\AccessAnswer;
use Drupal\data_surface\Resource\OperationAccessInterface;
use Drupal\data_surface\Resource\OperationContext;

/**
 * A code lock is open to an account that sets codes, and locked otherwise.
 */
final class CodeLockAccess implements OperationAccessInterface {

  /**
   * {@inheritdoc}
   */
  public function access(OperationContext $context, AccountInterface $account): AccessAnswer {
    $answer = $account->hasPermission('set cabinet codes')
      ? AccessAnswer::open()
      : AccessAnswer::locked('Only a locksmith sets a code.');
    return $answer->addCacheContexts(['user.permissions']);
  }

}
