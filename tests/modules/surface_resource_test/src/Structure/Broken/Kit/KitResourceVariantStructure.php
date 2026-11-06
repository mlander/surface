<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken\Kit;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Resource\Journal\LedgerResource;
use Drupal\surface_resource_test\Variants\KitVariants;

/**
 * Mixes in a resource with storage of its own as a variant.
 */
#[Structure('resource_test.broken.kit_resource_variant')]
final class KitResourceVariantStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('kind', 'string', 'Kind');
    $inputs->mixInVariants('kind', from: KitVariants::class, arguments: ['ledger', LedgerResource::class]);
  }

}
