<?php

declare(strict_types=1);

namespace Drupal\data_surface_test\Structure;

use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\ShapeInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * The test action's configuration: a message and its level.
 *
 * Named by DataSurfaceTestAction with #[UsesStructure]. No refiner,
 * because no setting here depends on another.
 */
#[Structure('test.action')]
final class TestActionStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $message = $inputs->add('message', 'string', t('Message'), default: 'Done')
      ->setDescription(t('Shown to the person the action runs for.'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 40]);
    DefinitionMetadata::setExamples($message, ['The article was published']);
    $inputs->add('level', 'string', t('Level'), default: 'status')
      ->addConstraint('LabeledChoice', [
        'choices' => [
          'status' => t('Status'),
          'warning' => t('Warning'),
        ],
      ]);
  }

}
