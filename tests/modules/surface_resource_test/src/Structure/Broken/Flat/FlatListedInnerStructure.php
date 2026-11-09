<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken\Flat;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface\Variants;

/**
 * The flat listed nested owner's variant for "a", listed by the site.
 *
 * Its own variants are stored flat, which lands them at its owner's level.
 */
#[Structure('resource_test.broken.flat.listed_inner')]
final class FlatListedInnerStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('mode', 'string', 'Mode');
    $inputs->attachVariants('inner', by: 'mode', label: 'Inner')
      ->setSetting(Variants::STORED_AS, Variants::FLAT);
  }

}
