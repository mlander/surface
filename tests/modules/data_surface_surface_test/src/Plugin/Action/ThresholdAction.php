<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Plugin\Action\DataSurfaceActionBase;
use Drupal\data_surface\Surface\Attribute\UsesSurface;
use Drupal\data_surface_surface_test\Surface\ThresholdSurface;

/**
 * An action whose configuration is the same surface a condition's is.
 *
 * One surface, two hosts: the action host supplies its own context and
 * target, and its execute() and access() are its own.
 */
#[Action(
  id: 'data_surface_surface_test_threshold',
  label: new TranslatableMarkup('Threshold (surface test)'),
)]
#[UsesSurface(ThresholdSurface::class)]
final class ThresholdAction extends DataSurfaceActionBase {

  /**
   * {@inheritdoc}
   *
   * @param mixed $object
   *   Unused: the action exists for its configuration.
   */
  public function execute(mixed $object = NULL): void {
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $result = AccessResult::allowed();
    return $return_as_object ? $result : $result->isAllowed();
  }

}
