<?php

declare(strict_types=1);

namespace Drupal\data_surface_gate_test\OutsideDiscovery;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Broken: a field gate, "field:node.title:delete", asking for an operation.
 *
 * Core's field access takes view or edit; refused at discovery.
 */
#[Structure('gate_test.broken.field_delete')]
#[Operation('update', label: 'Update', gate: ['field:node.title:delete'])]
final class FieldDeleteGateStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('jam', 'string', 'Jam');
  }

}
