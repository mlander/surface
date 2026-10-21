<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Broken;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Has a refiner watching a key its shape never declares.
 */
#[Structure('resource_test.broken.watches_undeclared')]
final class WatchesUndeclaredStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('name', 'string', 'Name');
  }

  /**
   * Watches a sibling that is not there.
   */
  #[RefinesInput('name')]
  public static function nameOfGhost(DataDefinitionInterface $name, string $ghost): DataDefinitionInterface {
    return $name;
  }

}
