<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Pack;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * A hip belt, the frame pack's child at a fixed key.
 */
#[Structure('resource_test.pack.belt')]
final class BeltStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('padded', 'boolean', t('Padded'), default: TRUE);
    $inputs->add('width', 'integer', t('Width'))
      ->addConstraint('Range', ['min' => 1]);
  }

}
