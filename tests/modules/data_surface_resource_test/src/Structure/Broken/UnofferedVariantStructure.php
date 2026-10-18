<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Broken;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Is filled by a variant its deciding key does not allow.
 *
 * UnofferedTinStructure fills the slot for `tin`, which `kind` refuses.
 */
#[Structure('resource_test.broken.unoffered_variant')]
final class UnofferedVariantStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('kind', 'string', 'Kind')->addConstraint('Choice', ['choices' => ['jar']]);
    $inputs->attachBy('settings', by: 'kind');
  }

}
