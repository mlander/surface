<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Access;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\surface\Resource\AccessAnswer;
use Drupal\surface\Resource\OperationAccessInterface;
use Drupal\surface\Resource\OperationContext;

/**
 * The four answers access can give, two of each kind.
 *
 * Named by the exhibit's provenance, which it mixes in, and by its loan,
 * which it attaches, so the same class answers for an owner's keys and
 * for a child. What each answer reads is what its cacheability says, and
 * that is what makes it static or runtime:
 *
 * - static, by the account alone: an account that may inspect the
 *   exhibit finds the keys locked (`user.permissions`), and an archivist
 *   finds them left out (`user.roles:archivist` too);
 * - runtime, once the thing is known: a sealed exhibit locks them (its
 *   stored status, under the exhibit's config tag), and a withdrawn one
 *   leaves them out (a State flag, so max-age 0); otherwise they are open,
 *   having read all of that.
 *
 * The account is asked about first, so its answers read nothing else.
 *
 * @see docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability
 */
final class ExhibitAccess implements OperationAccessInterface {

  /**
   * The State key that withdraws the exhibit's provenance and loan.
   */
  public const WITHDRAWN = 'surface_resource_test.exhibit_withdrawn';

  /**
   * The role that never sees them.
   */
  public const ARCHIVIST = 'archivist';

  /**
   * How often it was asked, so a test sees a static answer asked once.
   */
  public static int $calls = 0;

  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function access(OperationContext $context, AccountInterface $account): AccessAnswer {
    self::$calls++;
    $read = (new CacheableMetadata())->addCacheContexts(['user.permissions']);
    if ($account->hasPermission('inspect exhibit')) {
      return AccessAnswer::locked('The exhibit may be inspected, not changed.')->addCacheableDependency($read);
    }
    $read->addCacheContexts(['user.roles:' . self::ARCHIVIST]);
    if (in_array(self::ARCHIVIST, $account->getRoles(), TRUE)) {
      return AccessAnswer::omitted('An archivist does not see the exhibit\'s provenance.')->addCacheableDependency($read);
    }
    $exhibit = $this->configFactory->get('surface_resource_test.exhibit');
    $read->addCacheableDependency($exhibit);
    if ($exhibit->get('status') === 'sealed') {
      return AccessAnswer::locked('The exhibit is sealed.')->addCacheableDependency($read);
    }
    $read->setCacheMaxAge(0);
    if ($this->state->get(self::WITHDRAWN, FALSE)) {
      return AccessAnswer::omitted('The exhibit is withdrawn.')->addCacheableDependency($read);
    }
    return AccessAnswer::open()->addCacheableDependency($read);
  }

}
