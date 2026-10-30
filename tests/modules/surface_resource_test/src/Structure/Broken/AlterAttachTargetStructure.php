<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * What OwnerLevelAttachAlter tries to attach to at the owner's level.
 */
#[Structure('resource_test.broken.alter_attach_target')]
final class AlterAttachTargetStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('layout', 'string', 'Layout')
      ->addConstraint('Choice', ['choices' => ['theatre', 'banquet', 'standing']]);
  }

}
