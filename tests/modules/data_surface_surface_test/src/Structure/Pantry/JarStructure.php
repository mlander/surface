<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Structure\Pantry;

use Drupal\data_surface_surface_test\Resource\Pantry\PantryResource;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\Attribute\Variant;
use Drupal\data_surface\Resource\ShapeInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * A jar's settings: fills the pantry's open slot, stored by the pantry.
 */
#[Structure('surface_test.pantry.jar')]
#[Variant(of: PantryResource::class, key: 'kind_settings', value: 'jar')]
final class JarStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('lid', 'string', 'Lid', default: 'screw')
      ->setDescription('How the jar closes.')
      ->addConstraint('Choice', ['choices' => ['screw', 'clip']]);
    $inputs->add('volume', 'integer', 'Volume', default: 500)
      ->addConstraint('Range', ['min' => 1, 'max' => 5000]);
  }

}
