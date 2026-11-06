<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * A battery lamp: one of the variants the mixed-in variants twins choose.
 */
#[Structure('property_test.lamp.battery')]
final class BatteryLampStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('cells', 'integer', t('Cells'))
      ->setRequired(TRUE);
    $inputs->add('brightness', 'integer', t('Brightness'));
  }

}
