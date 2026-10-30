<?php

declare(strict_types=1);

namespace Drupal\surface_gate_test\OutsideDiscovery;

use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

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
