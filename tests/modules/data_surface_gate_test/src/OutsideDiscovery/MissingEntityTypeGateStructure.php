<?php

declare(strict_types=1);

namespace Drupal\data_surface_gate_test\OutsideDiscovery;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Broken: a gate, "entity:spaceship:update", naming what the site lacks.
 *
 * Refused at discovery, naming it.
 */
#[Structure('gate_test.broken.missing_entity_type')]
#[Operation('update', label: 'Update', gate: ['entity:spaceship:update'])]
final class MissingEntityTypeGateStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('jam', 'string', 'Jam');
  }

}
