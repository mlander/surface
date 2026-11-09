<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Mixin;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Structure\Pack\BeltStructure;
use Drupal\surface_resource_test\Structure\Pack\PocketStructure;

/**
 * A harness, mixed in: its straps, and three children of the owner's.
 *
 * Its hip belt at a fixed key, its pockets a collection of two at most,
 * and its rain cover a map holding a pouch, a structure with no id:
 * each is the owner's child at its key where the harness is mixed in.
 */
#[Structure('resource_test.harness')]
final class HarnessStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('straps', 'integer', t('Straps'), default: 2);

    $inputs->attach('belt', BeltStructure::class, t('Hip belt'));

    $inputs->attachList('pockets', PocketStructure::class, t('Pockets'), max: 2);

    $inputs->addMap('cover', t('Rain cover'), static function (BlueprintInterface $cover): void {
      $cover->add('color', 'string', t('Color'));
      $cover->attach('pouch', PocketStructure::class, t('Pouch'));
    });
  }

}
