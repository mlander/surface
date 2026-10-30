<?php

declare(strict_types=1);

namespace Drupal\surface_tool_test\Access;

use Drupal\Core\Session\AccountInterface;
use Drupal\surface\Resource\AccessAnswer;
use Drupal\surface\Resource\OperationAccessInterface;
use Drupal\surface\Resource\OperationContext;

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
