<?php

declare(strict_types=1);

namespace Drupal\surface_test\Structure;

use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * The test action's configuration: a message and its level.
 *
 * Named by SurfaceTestAction with #[UsesStructure]. No refiner,
 * because no setting here depends on another.
 */
#[Structure('test.action')]
final class TestActionStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
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
