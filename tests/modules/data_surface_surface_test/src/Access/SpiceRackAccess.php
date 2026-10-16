<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\Surface\SurfaceAccess;
use Drupal\data_surface\Surface\SurfaceAccessInterface;
use Drupal\data_surface\Surface\SurfaceContext;

/**
 * Open, locked or omitted, by permission: the three answers of access.
 *
 * Hiding wins over inspecting, inspecting over stocking. Two answers are
 * the new type and two are core's, read the old way: allowed is open, and
 * forbidden, for an account with none of the three, is omitted.
 */
final class SpiceRackAccess implements SurfaceAccessInterface {

  /**
   * {@inheritdoc}
   */
  public function access(SurfaceContext $context, AccountInterface $account): AccessResultInterface|SurfaceAccess {
    if ($account->hasPermission('hide spice rack')) {
      return SurfaceAccess::omitted('The rack is hidden.')->addCacheContexts(['user.permissions']);
    }
    if ($account->hasPermission('inspect spice rack')) {
      return SurfaceAccess::locked('The rack may be looked at.')->addCacheContexts(['user.permissions']);
    }
    return $account->hasPermission('stock spice rack')
      ? AccessResult::allowed()->cachePerPermissions()
      : AccessResult::forbidden('Nobody said anything about the rack.')->cachePerPermissions();
  }

}
