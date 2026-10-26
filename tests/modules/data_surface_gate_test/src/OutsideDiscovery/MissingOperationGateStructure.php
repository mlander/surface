<?php

declare(strict_types=1);

namespace Drupal\data_surface_gate_test\OutsideDiscovery;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Broken: a gate, "gate_test.note:rename", naming what the site does not have.
 *
 * Refused at discovery, naming it.
 */
#[Structure('gate_test.broken.missing_operation')]
#[Operation('update', label: 'Update', gate: ['gate_test.note:rename'])]
final class MissingOperationGateStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('jam', 'string', 'Jam');
  }

}
