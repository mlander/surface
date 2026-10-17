<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Structure\Broken;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\ShapeInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Has a refiner watching a key its shape never declares.
 */
#[Structure('surface_test.broken.watches_undeclared')]
final class WatchesUndeclaredStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
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
