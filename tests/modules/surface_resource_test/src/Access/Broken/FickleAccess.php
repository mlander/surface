<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Access\Broken;

use Drupal\Core\Session\AccountInterface;
use Drupal\surface\Resource\AccessAnswer;
use Drupal\surface\Resource\OperationAccessInterface;
use Drupal\surface\Resource\OperationContext;

/**
 * Says it reads the account alone, and counts how often it is asked.
 *
 * Open the first time, locked the next, its cacheability naming only the
 * account's permissions: with assertions on, the second asking catches it.
 */
final class FickleAccess implements OperationAccessInterface {

  /**
   * How often it was asked.
   */
  public static int $calls = 0;

  /**
   * {@inheritdoc}
   */
  public function access(OperationContext $context, AccountInterface $account): AccessAnswer {
    self::$calls++;
    $answer = self::$calls % 2 === 1 ? AccessAnswer::open() : AccessAnswer::locked('Asked again.');
    return $answer->addCacheContexts(['user.permissions']);
  }

}
