<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Structure\Depot\ShelfTagStructure;

/**
 * Wires the tag's stock code port to a plain string.
 */
#[Structure('resource_test.broken.wires_shape_port')]
final class WiresShapePortStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('code', 'string', 'Code');
    $inputs->attach('tag', ShelfTagStructure::class, reads: ['code' => 'code']);
  }

}
