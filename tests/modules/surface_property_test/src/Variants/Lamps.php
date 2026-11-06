<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Variants;

use Drupal\surface\Resource\SiteList;
use Drupal\surface\Resource\SiteListInterface;
use Drupal\surface_property_test\Structure\BatteryLampStructure;
use Drupal\surface_property_test\Structure\OilLampStructure;

/**
 * Lists the two lamps the mixed-in variants twins choose between.
 */
final class Lamps implements SiteListInterface {

  /**
   * {@inheritdoc}
   */
  public function list(): SiteList {
    return (new SiteList())
      ->add('oil', 'Oil', structure: OilLampStructure::class)
      ->add('battery', 'Battery', structure: BatteryLampStructure::class);
  }

}
