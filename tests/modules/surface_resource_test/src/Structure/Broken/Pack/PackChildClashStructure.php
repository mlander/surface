<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken\Pack;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Variants\KitVariants;

/**
 * Mixes in two variants that hold different children at "belt".
 */
#[Structure('resource_test.broken.pack_child_clash')]
final class PackChildClashStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('kind', 'string', 'Kind');
    $inputs->mixInVariants('kind', from: KitVariants::class, arguments: [
      'belted',
      BeltedStructure::class,
      'pocketed',
      PocketBeltStructure::class,
    ]);
  }

}
