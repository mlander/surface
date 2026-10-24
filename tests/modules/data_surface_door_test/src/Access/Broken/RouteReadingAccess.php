<?php

declare(strict_types=1);

namespace Drupal\data_surface_door_test\Access\Broken;

use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\Resource\AccessAnswer;
use Drupal\data_surface\Resource\OperationAccessInterface;
use Drupal\data_surface\Resource\OperationContext;

/**
 * Answers by the route a door is answering: refused.
 */
final class RouteReadingAccess implements OperationAccessInterface {

  /**
   * {@inheritdoc}
   */
  public function access(OperationContext $context, AccountInterface $account): AccessAnswer {
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    return \Drupal::routeMatch()->getRouteName() === 'system.admin' ? AccessAnswer::locked('Not here.') : AccessAnswer::open();
  }

}
