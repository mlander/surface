<?php

declare(strict_types=1);

namespace Drupal\data_surface_test\Surface;

use Drupal\Core\StringTranslation\TranslatableMarkup;
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
  public function defineInputs(ShapeInterface $inputs): void {
    $message = $inputs->add('message', 'string', new TranslatableMarkup('Message'), default: 'Done')
      ->setDescription(new TranslatableMarkup('Shown to the person the action runs for.'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 40]);
    DefinitionMetadata::setExamples($message, ['The article was published']);
    $inputs->add('level', 'string', new TranslatableMarkup('Level'), default: 'status')
      ->addConstraint('LabeledChoice', [
        'choices' => [
          'status' => new TranslatableMarkup('Status'),
          'warning' => new TranslatableMarkup('Warning'),
        ],
      ]);
  }

}
