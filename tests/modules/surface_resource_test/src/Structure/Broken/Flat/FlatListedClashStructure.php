<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken\Flat;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface\Variants;
use Drupal\surface_resource_test\Structure\Broken\Kit\WeightOnlyStructure;
use Drupal\surface_resource_test\Variants\KitVariants;

/**
 * Declares "weight" itself, then stores flat a listed variant declaring it.
 *
 * The variant is the site's, from a provider, so it is built when a door
 * reads it, and refused then.
 */
#[Structure('resource_test.broken.flat_listed_clash')]
final class FlatListedClashStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('kind', 'string', 'Kind');
    $inputs->add('weight', 'integer', 'Weight');
    $weight = ['a', WeightOnlyStructure::class];
    $inputs->attachVariants('gear', by: 'kind', label: 'Gear', from: KitVariants::class, arguments: $weight)
      ->setSetting(Variants::STORED_AS, Variants::FLAT);
  }

}
