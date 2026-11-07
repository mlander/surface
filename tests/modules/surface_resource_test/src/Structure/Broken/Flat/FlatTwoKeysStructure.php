<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken\Flat;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface\Variants;

/**
 * Stores the variants at two keys flat, at one level.
 */
#[Structure('resource_test.broken.flat_two_keys')]
final class FlatTwoKeysStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('kind', 'string', 'Kind');
    $inputs->attachVariants('gear', 'kind', 'Gear')
      ->setSetting(Variants::STORED_AS, Variants::FLAT);
    $inputs->attachVariants('spare', 'kind', 'Spare')
      ->setSetting(Variants::STORED_AS, Variants::FLAT);
  }

}
