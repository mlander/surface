<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken\Kit;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * A variant whose size is a word, beside one whose size is a count.
 */
#[Structure('resource_test.broken.kit.size_word')]
#[Variant(of: KitClashStructure::class, at: 'kind', for: 'word')]
final class SizeWordStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('size', 'string', 'Size');
  }

}
