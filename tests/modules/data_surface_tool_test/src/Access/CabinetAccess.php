<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool_test\Access;

use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\Resource\AccessAnswer;
use Drupal\data_surface\Resource\OperationAccessInterface;
use Drupal\data_surface\Resource\OperationContext;

/**
 * Open for a keeper, locked for an auditor, omitted for anyone else.
 *
 * Static: the account's permissions alone decide it.
 */
final class CabinetAccess implements OperationAccessInterface {

  /**
   * {@inheritdoc}
   */
  public function access(OperationContext $context, AccountInterface $account): AccessAnswer {
    $answer = match (TRUE) {
      $account->hasPermission('keep cabinet') => AccessAnswer::open(),
      $account->hasPermission('audit cabinet') => AccessAnswer::locked('The cabinet may be audited, not changed.'),
      default => AccessAnswer::omitted('Only a keeper or an auditor may see the cabinet.'),
    };
    return $answer->addCacheContexts(['user.permissions']);
  }

}
