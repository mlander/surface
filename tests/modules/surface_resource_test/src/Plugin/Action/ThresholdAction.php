<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Plugin\Action\TypedConfigurableActionBase;
use Drupal\surface\Resource\Attribute\UsesStructure;
use Drupal\surface_resource_test\Structure\ThresholdStructure;

/**
 * An action whose configuration is the same structure a condition's is.
 *
 * One structure, two hosts: the action host supplies its own context and
 * storage, and its execute() and access() are its own.
 */
#[Action(
  id: 'surface_resource_test_threshold',
  label: new TranslatableMarkup('Threshold (resource test)'),
)]
#[UsesStructure(ThresholdStructure::class)]
final class ThresholdAction extends TypedConfigurableActionBase {

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
