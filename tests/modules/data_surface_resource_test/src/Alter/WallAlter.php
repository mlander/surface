<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Alter;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\data_surface\Resource\AccessAnswer;
use Drupal\data_surface\Resource\OperationAccessInterface;
use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface_resource_test\Access\WallAccess;
use Drupal\data_surface_resource_test\Structure\Gallery\WallStructure;

/**
 * Another module's answer for the wall, wherever a wall is attached.
 *
 * Asked after the wall's own access class. Static: an account that may
 * freeze walls finds them locked. Runtime: once the walls are down, a
 * State flag, they are left out.
 */
#[Alters(WallStructure::class)]
final class WallAlter implements OperationAccessInterface {

  /**
   * The State key that takes the walls down.
   */
  public const DOWN = 'data_surface_resource_test.walls_down';

  public function __construct(
    protected readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function access(OperationContext $context, AccountInterface $account): AccessAnswer {
    WallAccess::$asked[] = 'WallAlter';
    if ($account->hasPermission('freeze walls')) {
      return AccessAnswer::locked('The walls are frozen.')->addCacheContexts(['user.permissions']);
    }
    $answer = $this->state->get(self::DOWN, FALSE) ? AccessAnswer::omitted('The walls are down.') : AccessAnswer::open();
    return $answer->addCacheContexts(['user.permissions'])->mergeCacheMaxAge(0);
  }

}
