<?php

declare(strict_types=1);

namespace Drupal\data_surface_test\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\Attribute\DataSurfaceAware;
use Drupal\data_surface\Plugin\Action\DataSurfaceActionBase;

/**
 * An action that adopts surfaces and says nothing else about settings.
 *
 * The thinnest adoption in group A: the attribute declaring what the
 * action accepts and the two methods the action host asks for. No
 * refiner, because no setting here depends on another; no
 * defaultConfiguration; and none of the three form methods that every
 * configurable action in core writes out by hand today.
 */
#[Action(
  id: 'data_surface_test_action',
  label: new TranslatableMarkup('Data surface test action'),
)]
#[DataSurfaceAware(
  definitions: [
    'message' => new DataDefinition([
      'type' => 'string',
      'label' => new TranslatableMarkup('Message'),
      'description' => new TranslatableMarkup('Shown to the person the action runs for.'),
      'required' => TRUE,
      'default_value' => 'Done',
      'examples' => ['The article was published'],
      'constraints' => ['Length' => ['max' => 40]],
    ]),
    'level' => new DataDefinition([
      'type' => 'string',
      'label' => new TranslatableMarkup('Level'),
      'required' => FALSE,
      'default_value' => 'status',
      'constraints' => [
        'LabeledChoice' => [
          'choices' => ['status', 'warning'],
          'labels' => [
            'status' => new TranslatableMarkup('Status'),
            'warning' => new TranslatableMarkup('Warning'),
          ],
        ],
      ],
    ]),
  ],
)]
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
