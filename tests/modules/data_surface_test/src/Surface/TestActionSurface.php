<?php

declare(strict_types=1);

namespace Drupal\data_surface_test\Surface;

use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * The test action's configuration: a message and its level.
 *
 * Named by DataSurfaceTestAction with #[UsesSurface]. No refiner,
 * because no setting here depends on another.
 */
#[Surface('test.action')]
final class TestActionSurface implements SurfaceInterface {

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
