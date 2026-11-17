<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Pantry;

use Drupal\surface_resource_test\Resource\Pantry\PantryResource;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

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
    $inputs->addSchema('lid', Schema::enum(['screw', 'clip'])
      ->title('Lid')
      ->description('How the jar closes.')
      ->default('screw'));
    $inputs->addSchema('volume', Schema::integer()
      ->title('Volume')
      ->default(500)
      ->minimum(1)
      ->maximum(5000));
  }

}
