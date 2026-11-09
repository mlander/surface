<?php

declare(strict_types=1);

namespace Drupal\surface_widget_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * Holds the pair twice: as it asks, and with the owner's own ask.
 */
#[Structure('widget_test.pairs')]
final class PairsStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->attach('asked', PairStructure::class, t('As it asks'));
    $inputs->attach('owned', PairStructure::class, t('As its owner asks'))
      ->setSetting('widget', 'fieldset');
  }

}
