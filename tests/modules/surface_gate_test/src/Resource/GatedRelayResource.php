<?php

declare(strict_types=1);

namespace Drupal\surface_gate_test\Resource;

use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_gate_test\Storage\GateNoteStorage;

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
    $inputs->refer('node', 'node', 'Node')->setRequired(TRUE);
    $inputs->add('note', 'string', 'Note');
  }

  /**
   * Relays a note: the note's own update borrowed.
   */
  #[Operation('update', label: 'Relay the note', gate: ['gate_test.note:update'])]
  public static function update(string $node): OperationContext {
    $loaded = \Drupal::classResolver(GateNoteStorage::class)->named($node, 'The "update" operation of gate_test.relay');
    return new OperationContext(known: ['node' => (int) $loaded->id()]);
  }

}
