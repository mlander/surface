<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface;

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
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('mode', 'string', t('Comparison'), default: 'at_least')
      ->addConstraint('LabeledChoice', [
        'choices' => [
          'at_least' => t('At least'),
          'at_most' => t('At most'),
        ],
      ]);
    $inputs->add('threshold', 'integer', t('Threshold'), default: 10)
      ->setDescription(t('The number the reading is compared against.'))
      ->addConstraint('Range', ['min' => 0, 'max' => 100]);
    $inputs->add('reading', 'integer', t('Reading'), default: 0)
      ->setDescription(t('The number the plugin tests.'))
      ->addConstraint('Range', ['min' => 0, 'max' => 100]);
  }

  /**
   * In the at most mode, a threshold of zero would refuse every reading.
   */
  #[RefinesInput('threshold')]
  public static function atLeastOneAtMost(DataDefinitionInterface $threshold, string $mode): DataDefinitionInterface {
    if ($mode !== 'at_most') {
      return $threshold;
    }
    $threshold->addConstraint('Range', ['min' => 1] + ($threshold->getConstraints()['Range'] ?? []));
    if ($threshold instanceof DataDefinition) {
      $threshold->setDescription(t('The number no reading may pass.'));
    }
    return $threshold;
  }

}
