<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Access;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\Resource\AccessAnswer;
use Drupal\data_surface\Resource\OperationAccessInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface_examples\Storage\ProgramStorage;

/**
 * Example 10: who may change the fees, and when, answered two ways.
 *
 * Its cacheability makes each answer static or runtime; nothing declares
 * it. An account without the permission finds the fees locked, read from
 * its permissions alone, so the full schema states it for that account.
 * For any other account the program's stored status decides, read under
 * its config tag, so the full schema marks the fees runtime and the
 * schema for the values as stored settles it.
 *
 * @see docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability
 */
final class FeesAccess implements OperationAccessInterface {

  /**
   * The permission that may change the fees.
   */
  public const PERMISSION = 'set event fees';

  /**
   * Constructs the access class, which the container builds as a service.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory, for the program's stored status.
   */
  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function access(OperationContext $context, AccountInterface $account): AccessAnswer {
    $read = (new CacheableMetadata())->addCacheContexts(['user.permissions']);
    if (!$account->hasPermission(self::PERMISSION)) {
      // Static: the account alone.
      return AccessAnswer::locked('Only an account that may set event fees changes them.')->addCacheableDependency($read);
    }
    $program = $this->configFactory->get(ProgramStorage::CONFIG);
    $read->addCacheableDependency($program);
    if ($program->get('published') === TRUE) {
      // Runtime: a stored value, under its config tag.
      return AccessAnswer::locked('The fees are fixed once the program is published.')->addCacheableDependency($read);
    }
    return AccessAnswer::open()->addCacheableDependency($read);
  }

}
