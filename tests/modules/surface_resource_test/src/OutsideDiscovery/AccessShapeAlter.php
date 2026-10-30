<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery;

use Drupal\Core\Session\AccountInterface;
use Drupal\surface\Resource\AccessAnswer;
use Drupal\surface\Resource\OperationAccessInterface;
use Drupal\surface\Resource\AltersInputsInterface;
use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\BlueprintAdditionsInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface_resource_test\Shape\Sku;

/**
 * Answers access for a shape, which is one value with no access of its own.
 */
#[Alters(Sku::class)]
final class AccessShapeAlter implements AltersInputsInterface, OperationAccessInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
  }

  /**
   * {@inheritdoc}
   */
  public function access(OperationContext $context, AccountInterface $account): AccessAnswer {
    return AccessAnswer::locked()->addCacheContexts(['user.permissions']);
  }

}
