<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Access;

use Drupal\Core\Session\AccountInterface;
use Drupal\surface\Resource\AccessAnswer;
use Drupal\surface\Resource\OperationAccessInterface;
use Drupal\surface\Resource\OperationContext;

/**
 * Open, locked or omitted, by permission: the journal's archive.
 *
 * The archive mixes the ownership in, whose access answers for its
 * members inside the archive: the stricter of the two answers stands.
 */
final class ArchiveAccess implements OperationAccessInterface {

  /**
   * {@inheritdoc}
   */
  public function access(OperationContext $context, AccountInterface $account): AccessAnswer {
    $answer = match (TRUE) {
      $account->hasPermission('hide archive') => AccessAnswer::omitted('The archive is hidden.'),
      $account->hasPermission('inspect archive') => AccessAnswer::locked('The archive may be looked at.'),
      default => AccessAnswer::open(),
    };
    return $answer->addCacheContexts(['user.permissions']);
  }

}
