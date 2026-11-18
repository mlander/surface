<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken\Flat;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface\Variants;
use PortableSchema\Builder\Schema;

/**
 * Stores the variants at two keys flat, at one level.
 */
#[Structure('resource_test.broken.flat_two_keys')]
final class FlatTwoKeysStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('kind', Schema::string()
      ->title('Kind'));
    $inputs->attachVariants('gear', by: 'kind', label: 'Gear', storedAs: Variants::FLAT);
    $inputs->attachVariants('spare', by: 'kind', label: 'Spare', storedAs: Variants::FLAT);
  }

}
