<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * A plugin's configuration: a reading compared with a threshold.
 *
 * Named by a condition and an action with #[UsesSurface], so one surface
 * is two plugins' configuration and each host supplies its own context
 * and target. No target, no situations: a plugin's surface has neither.
 */
#[Surface('test.threshold')]
final class ThresholdSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('mode', 'string', new TranslatableMarkup('Comparison'), default: 'at_least')
      ->addConstraint('LabeledChoice', [
        'choices' => [
          'at_least' => new TranslatableMarkup('At least'),
          'at_most' => new TranslatableMarkup('At most'),
        ],
      ]);
    $inputs->add('threshold', 'integer', new TranslatableMarkup('Threshold'), default: 10)
      ->setDescription(new TranslatableMarkup('The number the reading is compared against.'))
      ->addConstraint('Range', ['min' => 0, 'max' => 100]);
    $inputs->add('reading', 'integer', new TranslatableMarkup('Reading'), default: 0)
      ->setDescription(new TranslatableMarkup('The number the plugin tests.'))
      ->addConstraint('Range', ['min' => 0, 'max' => 100]);
  }

  /**
   * In the at most mode, a threshold of zero would refuse every reading.
   */
  #[RefinesInput('threshold')]
  public function atLeastOneAtMost(DataDefinitionInterface $threshold, string $mode): DataDefinitionInterface {
    if ($mode !== 'at_most') {
      return $threshold;
    }
    $threshold->addConstraint('Range', ['min' => 1] + ($threshold->getConstraints()['Range'] ?? []));
    if ($threshold instanceof DataDefinition) {
      $threshold->setDescription(new TranslatableMarkup('The number no reading may pass.'));
    }
    return $threshold;
  }

}
