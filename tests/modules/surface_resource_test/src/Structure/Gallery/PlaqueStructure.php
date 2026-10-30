<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Gallery;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * A gallery's plaque, mixed in: its members the gallery's own keys.
 *
 * No access class: PlaqueAlter answers for its members, in the gallery's
 * frame.
 */
#[Structure('resource_test.plaque')]
final class PlaqueStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('caption', 'string', t('Caption'));
    $inputs->add('credit', 'string', t('Credit'));
  }

}
