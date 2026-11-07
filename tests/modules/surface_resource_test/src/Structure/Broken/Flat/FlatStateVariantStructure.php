<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken\Flat;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Resource\Broken\FlatStateResource;

/**
 * The flat state resource's variant for "a".
 */
#[Structure('resource_test.broken.flat.state')]
#[Variant(of: FlatStateResource::class, at: 'gear', for: 'a')]
final class FlatStateVariantStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('size', 'integer', 'Size');
  }

}
