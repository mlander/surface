<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\OutsideDiscovery;

use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\Resource\AccessAnswer;
use Drupal\data_surface\Resource\AltersAccessInterface;
use Drupal\data_surface\Resource\AltersInputsInterface;
use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface\Resource\BlueprintAdditionsInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface_resource_test\Shape\Sku;

/**
 * Answers access for a shape, which is one value with no access of its own.
 */
#[Alters(Sku::class)]
final class AccessShapeAlter implements AltersInputsInterface, AltersAccessInterface {

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
