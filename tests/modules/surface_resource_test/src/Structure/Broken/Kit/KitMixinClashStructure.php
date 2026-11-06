<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken\Kit;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Structure\Broken\CreatedOnlyStructure;
use Drupal\surface_resource_test\Structure\Mixin\TimestampsStructure;
use Drupal\surface_resource_test\Variants\KitVariants;

/**
 * Mixes in the timestamps, then a variant that declares "created".
 */
#[Structure('resource_test.broken.kit_mixin_clash')]
final class KitMixinClashStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('kind', 'string', 'Kind');
    $inputs->mixIn(TimestampsStructure::class);
    $inputs->mixInVariants('kind', from: KitVariants::class, arguments: ['a', CreatedOnlyStructure::class]);
  }

}
