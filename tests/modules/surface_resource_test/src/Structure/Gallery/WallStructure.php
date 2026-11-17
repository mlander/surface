<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Gallery;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Access\WallAccess;
use PortableSchema\Builder\Schema;

/**
 * A wall, attached at the gallery's north and south and at the annex's.
 *
 * Its own access class answers first; two alters answer after it, one
 * narrowing and one that tries to open.
 */
#[Structure('resource_test.wall', access: WallAccess::class)]
final class WallStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('artwork', Schema::string()
      ->title(t('Artwork')));
    $inputs->addSchema('height', Schema::integer()
      ->title(t('Height')));
  }

}
