<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\OutsideDiscovery;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Broken: an operation under a retired id, `edit` where `update` is meant.
 *
 * Refused at discovery, naming the standard id.
 */
#[Structure('resource_test.broken.retired_id')]
#[Operation('edit', label: 'Edit a jar')]
final class RetiredIdStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('jam', 'string', 'Jam');
  }

}
