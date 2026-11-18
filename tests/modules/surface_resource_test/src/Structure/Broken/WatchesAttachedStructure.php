<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Structure\Pantry\ShelfStructure;
use PortableSchema\Builder\Schema;

/**
 * Reads its child's value from a refiner, which the wall refuses.
 */
#[Structure('resource_test.broken.watches_child')]
final class WatchesAttachedStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('note', Schema::string()
      ->title('Note'));
    $inputs->attach('shelf', ShelfStructure::class);
  }

  /**
   * Reads the shelf.
   */
  #[RefinesInput('note')]
  public static function noteForShelf(array $shelf): Schema {
    return Schema::any();
  }

}
