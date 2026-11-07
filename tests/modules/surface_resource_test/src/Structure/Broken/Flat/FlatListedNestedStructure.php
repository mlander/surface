<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken\Flat;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface\Variants;
use Drupal\surface_resource_test\Variants\KitVariants;

/**
 * Stores flat a listed variant that stores variants flat of its own.
 *
 * The variant is the site's, from a provider, so it is built when a door
 * reads it, and refused then.
 */
#[Structure('resource_test.broken.flat_listed_nested')]
final class FlatListedNestedStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('kind', 'string', 'Kind');
    $inner = ['a', FlatListedInnerStructure::class];
    $inputs->attachVariants('gear', 'kind', 'Gear', from: KitVariants::class, arguments: $inner)
      ->setSetting(Variants::STORED_AS, Variants::FLAT);
  }

}
