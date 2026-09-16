<?php

declare(strict_types=1);

namespace Drupal\data_surface_test\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Plugin\Action\DataSurfaceActionBase;

/**
 * An action that adopts surfaces and says nothing else about settings.
 *
 * The thinnest adoption in group A: one method declaring what the
 * action accepts and the two methods the action host asks for. No
 * refiner, because no setting here depends on another; no
 * defaultConfiguration; and none of the three form methods that every
 * configurable action in core writes out by hand today.
 */
#[Action(
  id: 'data_surface_test_action',
  label: new TranslatableMarkup('Data surface test action'),
)]
final class DataSurfaceTestAction extends DataSurfaceActionBase {

  use MessengerTrait;

  /**
   * {@inheritdoc}
   */
  public static function declareDataSurface(DataSurfaceBuilderInterface $builder): void {
    $message = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Message'))
      ->setDescription(new TranslatableMarkup('Shown to the person the action runs for.'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 40]);
    DefinitionMetadata::setExamples($message, ['The article was published']);
    $builder->setDefinition('message', $message);
    $builder->setDefault('message', 'Done');

    $builder->setDefinition('level', DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Level'))
      ->setRequired(FALSE)
      ->addConstraint('LabeledChoice', [
        'choices' => [
          'status' => new TranslatableMarkup('Status'),
          'warning' => new TranslatableMarkup('Warning'),
        ],
      ]));
    $builder->setDefault('level', 'status');
  }

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
