<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Broken;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_resource_test\Structure\Pantry\ShelfStructure;

/**
 * Refines the key its own child is attached at, which the wall refuses.
 */
#[Structure('resource_test.broken.refines_child')]
final class RefinesAttachedStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->attach('shelf', ShelfStructure::class);
  }

  /**
   * Reaches into the child.
   */
  #[RefinesInput('shelf')]
  public static function intoTheShelf(DataDefinitionInterface $shelf): DataDefinitionInterface {
    return $shelf;
  }

}
