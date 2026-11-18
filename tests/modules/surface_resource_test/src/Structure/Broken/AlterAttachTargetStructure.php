<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * What DeclaringClassLevelAttachAlter tries to attach at that level.
 */
#[Structure('resource_test.broken.alter_attach_target')]
final class AlterAttachTargetStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('layout', Schema::enum(['theatre', 'banquet', 'standing'])
      ->title('Layout'));
  }

}
