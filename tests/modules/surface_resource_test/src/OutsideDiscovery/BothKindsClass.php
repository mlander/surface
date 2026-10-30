<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery;

use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * Carries both #[Resource] and #[Structure], which discovery refuses.
 *
 * Outside src/Resource and src/Structure, as every class refused at
 * discovery is: the test hands it to a registry of its own.
 */
#[Resource('resource_test.broken.both_kinds', identity: ['name'])]
#[Structure('resource_test.broken.both_kinds')]
final class BothKindsClass implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('name', 'string', 'Name');
  }

}
