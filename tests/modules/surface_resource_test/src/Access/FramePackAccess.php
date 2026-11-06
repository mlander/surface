<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Access;

use Drupal\Core\Session\AccountInterface;
use Drupal\surface\Resource\AccessAnswer;
use Drupal\surface\Resource\OperationAccessInterface;
use Drupal\surface\Resource\OperationContext;

/**
 * Omits the pack's frame pack variant, by permission.
 */
final class FramePackAccess implements OperationAccessInterface {

  /**
   * {@inheritdoc}
   */
  public function access(OperationContext $context, AccountInterface $account): AccessAnswer {
    $answer = $account->hasPermission('hide frame pack')
      ? AccessAnswer::omitted('The frame pack is not offered.')
      : AccessAnswer::open();
    return $answer->addCacheContexts(['user.permissions']);
  }

}
