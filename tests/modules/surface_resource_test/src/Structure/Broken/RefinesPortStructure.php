<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface\Resource\Attribute\RefinesInput;
use PortableSchema\Builder\Schema;

/**
 * Refines a port it reads, which only its container may.
 */
#[Structure('resource_test.broken.refines_port')]
final class RefinesPortStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->reads('layout', 'string');
    $inputs->addSchema('note', Schema::string()
      ->title('Note'));
  }

  /**
   * Narrows the port.
   */
  #[RefinesInput('layout')]
  public static function narrowsTheLayout(): Schema {
    return Schema::any();
  }

}
