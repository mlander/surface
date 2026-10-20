<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Alter;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\data_surface\Resource\AccessAnswer;
use Drupal\data_surface\Resource\AltersAccessInterface;
use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface_resource_test\Structure\Gallery\OilFinishStructure;

/**
 * An answer for one variant, the oil finish, and no other.
 *
 * Static: an account that may freeze walls finds the oil finish locked,
 * offered read only. Runtime: once oil is banned, a State flag, it is not
 * offered.
 */
#[Alters(OilFinishStructure::class)]
final class OilFinishAlter implements AltersAccessInterface {

  /**
   * The State key that bans oil.
   */
  public const BANNED = 'data_surface_resource_test.oil_banned';

  public function __construct(
    protected readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function access(OperationContext $context, AccountInterface $account): AccessAnswer {
    if ($account->hasPermission('freeze walls')) {
      return AccessAnswer::locked('The oil finish is frozen.')->addCacheContexts(['user.permissions']);
    }
    $answer = $this->state->get(self::BANNED, FALSE) ? AccessAnswer::omitted('Oil is banned.') : AccessAnswer::open();
    return $answer->addCacheContexts(['user.permissions'])->mergeCacheMaxAge(0);
  }

}
