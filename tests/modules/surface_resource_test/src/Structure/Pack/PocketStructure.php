<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Pack;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * A pocket, each item of the frame pack's collection.
 */
#[Structure('resource_test.pack.pocket')]
final class PocketStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('name', 'string', t('Name'))
      ->setRequired(TRUE);
  }

}
