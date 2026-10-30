<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Variants\UncacheableLayouts;

/**
 * Asks for variants from a provider that cannot be cached.
 */
#[Structure('resource_test.broken.uncacheable_variants')]
final class UncacheableVariantsStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('kind', 'string', 'Kind');
    $inputs->attachVariants('detail', by: 'kind', from: UncacheableLayouts::class);
  }

}
