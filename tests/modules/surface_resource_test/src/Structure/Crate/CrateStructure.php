<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Crate;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * A crate: variants, one of which refines one of its keys by another.
 *
 * For the served contract, which enumerates a variant's refined keys
 * inside the variant's own conditional.
 */
#[Structure('resource_test.crate')]
final class CrateStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('contents', 'string', 'Contents', default: 'fruit')
      ->setRequired(TRUE);
    $inputs->attachVariants('contents_settings', by: 'contents')
      ->setLabel('Contents settings');
  }

}
