<?php

declare(strict_types=1);

namespace Drupal\surface_widget_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * Two strings a widget of its own draws side by side.
 */
#[Structure('widget_test.pair', widget: 'widget_test_pair')]
final class PairStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('left', 'string', t('Left'));
    $inputs->add('right', 'string', t('Right'))
      ->addConstraint('Length', ['max' => 8]);
  }

}
