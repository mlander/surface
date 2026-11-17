<?php

declare(strict_types=1);

namespace Drupal\surface_widget_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * Two strings a widget of its own draws side by side.
 */
#[Structure('widget_test.pair', widget: 'widget_test_pair')]
final class PairStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('left', Schema::string()
      ->title(t('Left')));
    $inputs->addSchema('right', Schema::string()
      ->title(t('Right'))
      ->maxLength(8));
  }

}
