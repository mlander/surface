<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery\Labels;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * The soil in a planter, which says neither what it is called nor for.
 *
 * Its def is titled by its id. Registered only by the test that says so.
 */
#[Structure('resource_test.planter.soil')]
final class SoilStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('depth', 'integer', t('Depth'), default: 20);
  }

}
