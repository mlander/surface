<?php

declare(strict_types=1);

namespace Drupal\data_surface_reference_test\Structure;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * An item of a collection: a reference in each item.
 */
#[Structure('reference_test.item')]
final class ItemStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('node', 'entity:node', 'Node')->setRequired(TRUE);
  }

}
