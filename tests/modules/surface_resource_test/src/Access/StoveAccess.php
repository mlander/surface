<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Access;

use Drupal\Core\Session\AccountInterface;
use Drupal\surface\Resource\AccessAnswer;
use Drupal\surface\Resource\OperationAccessInterface;
use Drupal\surface\Resource\OperationContext;

/**
 * Locks or omits the kit's stove variant, by permission.
 */
final class StoveAccess implements OperationAccessInterface {

  /**
   * {@inheritdoc}
   */
  public function access(OperationContext $context, AccountInterface $account): AccessAnswer {
    $answer = match (TRUE) {
      $account->hasPermission('hide stove') => AccessAnswer::omitted('The stove is not offered.'),
      $account->hasPermission('inspect stove') => AccessAnswer::locked('The stove may be looked at.'),
      default => AccessAnswer::open(),
    };
    return $answer->addCacheContexts(['user.permissions']);
  }

}
