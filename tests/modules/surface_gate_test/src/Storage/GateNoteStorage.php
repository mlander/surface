<?php

declare(strict_types=1);

namespace Drupal\surface_gate_test\Storage;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceStorageInterface;

/**
 * A note per node, in State.
 */
final class GateNoteStorage implements ResourceStorageInterface {

  /**
   * The State key of the notes, by node id.
   */
  public const KEY = 'surface_gate_test.notes';

  /**
   * Constructs a GateNoteStorage.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The State.
   */
  public function __construct(
    protected readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function load(OperationContext $context): array {
    $node = $context->known['node'] ?? NULL;
    $notes = (array) $this->state->get(self::KEY, []);
    return $node === NULL || !isset($notes[$node]) ? [] : ['node' => $node, 'note' => $notes[$node]];
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(OperationContext $context, array $values, AccountInterface $account): array {
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function commit(OperationContext $context, array $prepared, AccountInterface $account): void {
    $notes = (array) $this->state->get(self::KEY, []);
    $notes[$prepared['node'] ?? $context->known['node']] = $prepared['note'] ?? NULL;
    $this->state->set(self::KEY, $notes);
  }

}
