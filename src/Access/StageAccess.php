<?php

declare(strict_types=1);

namespace Drupal\surface\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\surface\Resource\OperationAccessInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Staging\StageStoreInterface;

/**
 * A stage is its owner's: opened by an account, used by that one alone.
 *
 * Opening one is open to any authenticated account and refused to an
 * anonymous one, which keeps nothing between requests but a session
 * cookie, and an HTTP or tool client does not keep that. Every operation
 * on a stage the context names is the owner's: another account is
 * refused, by what the record says, so the answer is never cached. The
 * read the build adds is asked in the update's context, so it is
 * answered as the update.
 *
 * Nothing of the request is read: a workspace is negotiated from the
 * request, and how a stage and a workspace compose is stated in
 * docs/decisions.md, not decided here.
 *
 * Decision: see
 * docs/decisions.md#a-stage-is-a-resource-of-the-base-module-kept-in-an-expirable-key-value-collection.
 *
 * @internal
 */
final class StageAccess implements OperationAccessInterface {

  /**
   * Constructs a StageAccess.
   *
   * @param \Drupal\surface\Staging\StageStoreInterface $stages
   *   Where the records are kept, which name their owners.
   */
  public function __construct(protected readonly StageStoreInterface $stages) {}

  /**
   * {@inheritdoc}
   */
  public function access(OperationContext $context, AccountInterface $account): AccessResultInterface {
    $id = $context->known['id'] ?? NULL;
    if (!is_string($id) || $id === '') {
      // Opening one, or no stage to ask about: the account alone.
      return $account->isAuthenticated()
        ? AccessResult::allowed()->addCacheContexts(['user.roles:authenticated'])
        : AccessResult::forbidden('A stage outlives the request that opens it, and an anonymous account keeps nothing between requests but a session cookie.')->addCacheContexts(['user.roles:authenticated']);
    }
    $record = $this->stages->read($id);
    if ($record === NULL) {
      return AccessResult::forbidden('The stage expired or was discarded.')->setCacheMaxAge(0);
    }
    return (int) $record['owner'] === (int) $account->id() && $account->isAuthenticated()
      ? AccessResult::allowed()->setCacheMaxAge(0)
      : AccessResult::forbidden('The stage is another account\'s.')->setCacheMaxAge(0);
  }

}
