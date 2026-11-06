<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken\Kit;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Structure\Notice\NoticeStructure;
use Drupal\surface_resource_test\Variants\KitVariants;

/**
 * Mixes in a structure that holds variants at a key as a variant.
 */
#[Structure('resource_test.broken.kit_child_variant')]
final class KitChildVariantStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('kind', 'string', 'Kind');
    $inputs->mixInVariants('kind', from: KitVariants::class, arguments: ['notice', NoticeStructure::class]);
  }

}
