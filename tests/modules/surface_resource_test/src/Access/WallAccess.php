<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Access;

use Drupal\Core\Session\AccountInterface;
use Drupal\surface\Resource\AccessAnswer;
use Drupal\surface\Resource\OperationAccessInterface;
use Drupal\surface\Resource\OperationContext;

/**
 * The wall's own answer: locked for an account that may inspect walls.
 *
 * Static, by the account alone. The wall's alters answer after it.
 */
final class WallAccess implements OperationAccessInterface {

  /**
   * Who answered for a wall, in order: this class and the wall's alters.
   *
   * @var string[]
   */
  public static array $asked = [];

  /**
   * {@inheritdoc}
   */
  public function access(OperationContext $context, AccountInterface $account): AccessAnswer {
    self::$asked[] = 'WallAccess';
    $answer = $account->hasPermission('inspect walls')
      ? AccessAnswer::locked('The wall may be inspected, not changed.')
      : AccessAnswer::open();
    return $answer->addCacheContexts(['user.permissions']);
  }

}
