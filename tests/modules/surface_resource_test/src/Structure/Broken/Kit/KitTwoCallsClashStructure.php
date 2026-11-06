<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken\Kit;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Variants\KitVariants;

/**
 * Mixes in variants twice, each bringing "weight".
 */
#[Structure('resource_test.broken.kit_two_calls_clash')]
final class KitTwoCallsClashStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('kind', 'string', 'Kind');
    $inputs->add('mode', 'string', 'Mode');
    $inputs->mixInVariants('kind', from: KitVariants::class, arguments: ['a', WeightOnlyStructure::class]);
    $inputs->mixInVariants('mode', from: KitVariants::class, arguments: ['b', WeightOnlyStructure::class]);
  }

}
