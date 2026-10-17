<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\OutsideDiscovery;

use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\ShapeInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Carries both #[Resource] and #[Structure], which discovery refuses.
 *
 * Outside src/Resource and src/Structure, as every class refused at
 * discovery is: the test hands it to a registry of its own.
 */
#[Resource('surface_test.broken.both_kinds', identity: ['name'])]
#[Structure('surface_test.broken.both_kinds')]
final class BothKindsShape implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('name', 'string', 'Name');
  }

}
