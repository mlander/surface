<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Mixin;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * Mixes the harness in between keys of its own, its children with it.
 */
#[Structure('resource_test.harnessed')]
final class HarnessedStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('name', 'string', t('Name'))
      ->setRequired(TRUE);
    $inputs->mixIn(HarnessStructure::class);
    $inputs->add('notes', 'string', t('Notes'));
  }

}
