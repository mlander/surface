<?php

declare(strict_types=1);

namespace Drupal\surface_gate_test\Resource;

use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_gate_test\Access\GateNoteAccess;
use Drupal\surface_gate_test\Storage\GateNoteStorage;

/**
 * A note on one node, which borrows the node's own access.
 *
 * Its identity key refers to the node, so each gate is asked about the
 * node the context knows: update asks core's update access alone, which
 * reads the account's permissions; annotate asks the title's edit access
 * too, which the module's hook makes depend on the node.
 */
#[Resource('gate_test.note', identity: ['node'], storage: GateNoteStorage::class, access: GateNoteAccess::class)]
final class GatedNoteResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->refer('node', 'node', 'Node')->setRequired(TRUE);
    $inputs->add('note', 'string', 'Note');
  }

  /**
   * Writes the note on a node: core's update access borrowed.
   */
  #[Operation('update', label: 'Edit the note', permission: 'write gated notes', gate: 'entity:node:update')]
  public static function update(string $node): OperationContext {
    $loaded = \Drupal::classResolver(GateNoteStorage::class)->named($node, 'The "update" operation of gate_test.note');
    return new OperationContext(known: ['node' => (int) $loaded->id()]);
  }

  /**
   * Writes the note on a node: its update and its title's edit borrowed.
   */
  #[Operation('annotate',
    label: 'Annotate the node',
    permission: 'write gated notes',
    gate: ['entity:node:update', 'field:node.title:edit'],
  )]
  public static function annotate(string $node): OperationContext {
    $loaded = \Drupal::classResolver(GateNoteStorage::class)->named($node, 'The "annotate" operation of gate_test.note');
    return new OperationContext(known: ['node' => (int) $loaded->id()]);
  }

}
