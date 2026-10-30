<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Keys\DefaultedKeys;
use Drupal\surface_resource_test\Structure\Workshop\BenchStructure;

/**
 * Holds a bench at every entry of a list that says what one starts from.
 */
#[Structure('resource_test.broken.defaulted_entry')]
final class DefaultedEntryStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->attachEach('benches', BenchStructure::class, from: DefaultedKeys::class);
  }

}
