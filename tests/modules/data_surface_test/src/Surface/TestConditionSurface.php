<?php

declare(strict_types=1);

namespace Drupal\data_surface_test\Surface;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * The test condition's configuration: a reading against a threshold.
 *
 * Named by DataSurfaceTestCondition with #[UsesSurface]. Deliberately
 * context free: it compares two of its own values, so a test can
 * evaluate the condition without gathering contexts.
 */
#[Surface('test.condition')]
final class TestConditionSurface implements SurfaceInterface {

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
      ->setDescription(new TranslatableMarkup('The number the condition tests.'))
      ->addConstraint('Range', ['min' => 0, 'max' => 100]);
  }

  /**
   * In the at most mode, a threshold of zero would refuse every reading.
   *
   * Narrowing, not widening: the advertised range already allowed
   * everything the refined one allows.
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
