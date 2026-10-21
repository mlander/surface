<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Gallery;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * One room of the gallery, at every entry of its keys from the site.
 */
#[Structure('resource_test.gallery_room')]
final class RoomStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('lights', 'boolean', t('Lights'), default: FALSE);
  }

}
