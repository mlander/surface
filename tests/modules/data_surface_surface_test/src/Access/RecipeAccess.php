<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\Surface\SurfaceAccessInterface;
use Drupal\data_surface\Surface\SurfaceContext;

/**
 * What no permission can say: nobody cooks in a closed kitchen.
 */
final class RecipeAccess implements SurfaceAccessInterface {

  /**
   * {@inheritdoc}
   */
  public function access(SurfaceContext $context, AccountInterface $account): AccessResultInterface {
    return AccessResult::forbiddenIf(($context->known['kitchen'] ?? NULL) === 'closed', 'The kitchen is closed.')
      ->orIf(AccessResult::allowed());
  }

}
