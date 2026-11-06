<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken\Pack;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Variants\KitVariants;

/**
 * Mixes in a variant that wires the ports of the child it holds.
 */
#[Structure('resource_test.broken.pack_wired_child')]
final class PackWiredChildStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('kind', 'string', 'Kind');
    $inputs->mixInVariants('kind', from: KitVariants::class, arguments: ['shared', WiredOwnershipStructure::class]);
  }

}
