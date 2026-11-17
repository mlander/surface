<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * Reads a port under the name of a key it declares.
 */
#[Structure('resource_test.broken.port_clash')]
final class PortClashStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('layout', Schema::string()
      ->title('Layout'));
    $inputs->reads('layout', 'string');
  }

}
