<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken\Flat;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface\Variants;

/**
 * Stores flat a variant that stores variants flat of its own.
 */
#[Structure('resource_test.broken.flat_nested')]
final class FlatNestedStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('kind', 'string', 'Kind');
    $inputs->attachVariants('gear', 'kind', 'Gear')
      ->setSetting(Variants::STORED_AS, Variants::FLAT);
  }

}
