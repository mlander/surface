<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Alter;

use Drupal\Core\Session\AccountInterface;
use Drupal\surface\Resource\AccessAnswer;
use Drupal\surface\Resource\OperationAccessInterface;
use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface_resource_test\Access\WallAccess;
use Drupal\surface_resource_test\Structure\Gallery\WallStructure;

/**
 * An alter that tries to open the wall for every account, after the rest.
 *
 * Open, static. Composed with the stricter standing, it opens nothing the
 * wall's access class or an earlier alter locked or left out.
 */
#[Alters(WallStructure::class)]
final class WallOpeningAlter implements OperationAccessInterface {

  /**
   * {@inheritdoc}
   */
  public function access(OperationContext $context, AccountInterface $account): AccessAnswer {
    WallAccess::$asked[] = 'WallOpeningAlter';
    return AccessAnswer::open()->addCacheContexts(['user.permissions']);
  }

}
