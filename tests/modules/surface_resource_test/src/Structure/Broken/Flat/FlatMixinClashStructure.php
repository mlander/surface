<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken\Flat;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface\Variants;
use Drupal\surface_resource_test\Structure\Broken\Kit\WeightOnlyStructure;

/**
 * Mixes in a "weight", then stores flat a variant that declares it.
 */
#[Structure('resource_test.broken.flat_mixin_clash')]
final class FlatMixinClashStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('kind', 'string', 'Kind');
    $inputs->mixIn(WeightOnlyStructure::class);
    $inputs->attachVariants('gear', by: 'kind', label: 'Gear')
      ->setSetting(Variants::STORED_AS, Variants::FLAT);
  }

}
