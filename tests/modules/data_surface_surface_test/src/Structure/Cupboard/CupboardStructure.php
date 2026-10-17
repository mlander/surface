<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Structure\Cupboard;

use Drupal\data_surface_surface_test\Resource\Cupboard\BinResource;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\ShapeInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_surface_test\Structure\Pantry\ShelfStructure;

/**
 * A cupboard: the same child class at two keys, twice over.
 *
 * Two shelves, a child with a refiner of its own and an alter, and two
 * bins, a child with an identity key, which a context may lock in one of
 * them and not the other. Built by hand in the tests, so it declares no
 * operation and no storage.
 */
#[Structure('surface_test.cupboard')]
final class CupboardStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->attach('upper', ShelfStructure::class)
      ->setLabel('Upper shelf');
    $inputs->attach('lower', ShelfStructure::class)
      ->setLabel('Lower shelf');
    $inputs->attach('left', BinResource::class)
      ->setLabel('Left bin');
    $inputs->attach('right', BinResource::class)
      ->setLabel('Right bin');
  }

}
