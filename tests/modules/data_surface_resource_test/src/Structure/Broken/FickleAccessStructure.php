<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Broken;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_resource_test\Access\Broken\FickleAccess;

/**
 * Its access class says it reads the account alone, and is fickle.
 *
 * It answers differently when asked again.
 *
 * Attached by FickleAccessParentStructure, where it is asked.
 */
#[Structure('resource_test.broken.fickle_access', access: FickleAccess::class)]
final class FickleAccessStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('note', 'string', 'Note');
  }

}
