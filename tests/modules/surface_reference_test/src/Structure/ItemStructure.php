<?php

declare(strict_types=1);

namespace Drupal\surface_reference_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * An item of a collection: a reference in each item.
 */
#[Structure('reference_test.item')]
final class ItemStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('node', Schema::integer()
      ->title('Node')
      ->check('drupal:SurfaceValidReference', ['entityTypeId' => 'node'])
      ->required());
  }

}
