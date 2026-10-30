<?php

declare(strict_types=1);

namespace Drupal\surface_gate_test\Resource;

use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_gate_test\Access\GateNoteAccess;
use Drupal\surface_gate_test\Storage\GateBatchStorage;
use Drupal\surface_gate_test\Structure\GatedBatchItemStructure;

/**
 * A note on many nodes at once, each node's access asked for its item.
 */
#[Resource('gate_test.batch', storage: GateBatchStorage::class, access: GateNoteAccess::class)]
#[Operation('update',
  label: 'Note many nodes',
  permission: 'write gated notes',
  gate: ['entity:node:update', 'field:node.title:edit'],
)]
final class GatedBatchResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->attachList('items', GatedBatchItemStructure::class, min: 1, max: 10)
      ->setLabel('Items')
      ->setDescription('Each node and its note.');
  }

}
