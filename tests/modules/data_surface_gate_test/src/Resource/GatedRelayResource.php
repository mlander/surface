<?php

declare(strict_types=1);

namespace Drupal\data_surface_gate_test\Resource;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_gate_test\Storage\GateNoteStorage;
use Drupal\node\NodeInterface;

/**
 * A relay of the note: borrows the note's update whole.
 *
 * Its own permission is none; the note's permission, its gate and its
 * access class answer for it.
 */
#[Resource('gate_test.relay', identity: ['node'], storage: GateNoteStorage::class)]
final class GatedRelayResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('node', 'entity:node', 'Node')->setRequired(TRUE);
    $inputs->add('note', 'string', 'Note');
  }

  /**
   * Relays a note: the note's own update borrowed.
   */
  #[Operation('update', label: 'Relay the note', gate: ['gate_test.note:update'])]
  public static function update(NodeInterface $node): OperationContext {
    return new OperationContext(known: ['node' => (int) $node->id()]);
  }

}
