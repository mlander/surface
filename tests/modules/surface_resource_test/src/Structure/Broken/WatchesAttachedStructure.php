<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Structure\Pantry\ShelfStructure;

/**
 * Reads its child's value from a refiner, which the wall refuses.
 */
#[Structure('resource_test.broken.watches_child')]
final class WatchesAttachedStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('note', 'string', 'Note');
    $inputs->attach('shelf', ShelfStructure::class);
  }

  /**
   * Reads the shelf.
   */
  #[RefinesInput('note')]
  public static function noteForShelf(DataDefinitionInterface $note, array $shelf): DataDefinitionInterface {
    return $note;
  }

}
