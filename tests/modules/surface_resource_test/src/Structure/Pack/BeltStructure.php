<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Pack;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * A hip belt, the frame pack's child at a fixed key.
 */
#[Structure('resource_test.pack.belt')]
final class BeltStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('padded', Schema::bool()
      ->title(t('Padded'))
      ->default(TRUE));
    $inputs->add('width', Schema::integer()
      ->title(t('Width'))
      ->minimum(1));
  }

}
