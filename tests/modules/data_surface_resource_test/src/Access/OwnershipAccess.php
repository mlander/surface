<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Access;

use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\Resource\AccessAnswer;
use Drupal\data_surface\Resource\OperationAccessInterface;
use Drupal\data_surface\Resource\OperationContext;

/**
 * Open, locked or omitted, by permission: the ownership's members.
 *
 * Hiding wins over inspecting; with neither, the members are open.
 */
final class OwnershipAccess implements OperationAccessInterface {

  /**
   * {@inheritdoc}
   */
  public function access(OperationContext $context, AccountInterface $account): AccessAnswer {
    $answer = match (TRUE) {
      $account->hasPermission('hide ownership') => AccessAnswer::omitted('The ownership is hidden.'),
      $account->hasPermission('inspect ownership') => AccessAnswer::locked('The ownership may be looked at.'),
      default => AccessAnswer::open(),
    };
    return $answer->addCacheContexts(['user.permissions']);
  }

}
