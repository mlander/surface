<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * An oil lamp: one of the variants the mixed-in variants twins choose.
 */
#[Structure('property_test.lamp.oil')]
final class OilLampStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('wick', 'integer', t('Wick'));
    $inputs->add('brightness', 'integer', t('Brightness'));
  }

}
