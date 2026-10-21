<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Pantry;

use Drupal\data_surface_resource_test\Resource\Pantry\PantryResource;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\Attribute\Variant;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * A jar's settings: one of the pantry's variants, stored by the pantry.
 */
#[Structure('resource_test.pantry.jar')]
#[Variant(of: PantryResource::class, at: 'kind_settings', for: 'jar')]
final class JarStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('lid', 'string', 'Lid', default: 'screw')
      ->setDescription('How the jar closes.')
      ->addConstraint('Choice', ['choices' => ['screw', 'clip']]);
    $inputs->add('volume', 'integer', 'Volume', default: 500)
      ->addConstraint('Range', ['min' => 1, 'max' => 5000]);
  }

}
