<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * Watches a key only its parent declares, which the wall refuses.
 */
#[Structure('resource_test.broken.nosy_child')]
final class NosyChildStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('height', 'integer', 'Height');
  }

  /**
   * Reads the parent's pantry.
   */
  #[RefinesInput('height')]
  public static function heightInPantry(DataDefinitionInterface $height, string $pantry): DataDefinitionInterface {
    return $height;
  }

}
