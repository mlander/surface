<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken\Kit;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Structure\Shelter\HutStructure;
use Drupal\surface_resource_test\Variants\KitVariants;

/**
 * Mixes in a variant holding variants at "covering", and one a word there.
 */
#[Structure('resource_test.broken.kit_covering_clash')]
final class KitCoveringClashStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('kind', 'string', 'Kind');
    $inputs->mixInVariants('kind', from: KitVariants::class, arguments: [
      'hut',
      HutStructure::class,
      'flat',
      CoveringWordStructure::class,
    ]);
  }

}
