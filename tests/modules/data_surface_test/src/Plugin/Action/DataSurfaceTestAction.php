<?php

declare(strict_types=1);

namespace Drupal\data_surface_test\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Surface\Attribute\UsesSurface;
use Drupal\data_surface_test\Surface\TestActionSurface;
use Drupal\data_surface\Plugin\Action\DataSurfaceActionBase;

/**
 * An action that adopts surfaces and says nothing else about settings.
 *
 * The thinnest adoption in group A: #[UsesSurface] naming what the
 * action accepts and the two methods the action host asks for. No
 * defaultConfiguration; and none of the three form methods that every
 * configurable action in core writes out by hand today.
 */
#[Action(
  id: 'data_surface_test_action',
  label: new TranslatableMarkup('Data surface test action'),
)]
#[UsesSurface(TestActionSurface::class)]
final class DataSurfaceTestAction extends DataSurfaceActionBase {

  use MessengerTrait;

  /**
   * {@inheritdoc}
   */
  public function execute(mixed $object = NULL): void {
    $configuration = $this->getConfiguration();
    $this->messenger()->addMessage($configuration['message'], $configuration['level']);
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $result = AccessResult::allowed();
    return $return_as_object ? $result : $result->isAllowed();
  }

}
