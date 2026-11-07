<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken\Flat;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface\Variants;

/**
 * The flat nested owner's variant for "a", its own variants stored flat.
 */
#[Structure('resource_test.broken.flat.inner')]
#[Variant(of: FlatNestedStructure::class, at: 'gear', for: 'a')]
final class FlatInnerStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('mode', 'string', 'Mode');
    $inputs->attachVariants('inner', 'mode', 'Inner')
      ->setSetting(Variants::STORED_AS, Variants::FLAT);
  }

}
