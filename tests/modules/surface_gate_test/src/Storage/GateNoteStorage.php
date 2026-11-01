<?php

declare(strict_types=1);

namespace Drupal\surface_gate_test\Storage;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceStorageInterface;
use Drupal\surface\ResourceBuild\NamesNothingException;
use Drupal\node\NodeInterface;

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
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, which loads the node a note is on.
   */
  public function __construct(
    protected readonly StateInterface $state,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Loads the node a note's identity names, for an operation's context.
   *
   * What an operation is handed is the node's id, the identity key's
   * value; the node is this storage's to load.
   *
   * @param mixed $node
   *   The node's id.
   * @param string $operation
   *   The operation, for the message.
   *
   * @return \Drupal\node\NodeInterface
   *   The node.
   *
   * @throws \Drupal\surface\ResourceBuild\NamesNothingException
   *   At `node`, when the id names no node.
   */
  public function named(mixed $node, string $operation): NodeInterface {
    $loaded = (is_int($node) || is_string($node)) && $node !== '' ? $this->entityTypeManager->getStorage('node')->load($node) : NULL;
    if (!$loaded instanceof NodeInterface) {
      throw new NamesNothingException(sprintf('%s takes the id of a node, and there is none with the id "%s".', $operation, is_scalar($node) ? (string) $node : get_debug_type($node)), 'node');
    }
    return $loaded;
  }

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
