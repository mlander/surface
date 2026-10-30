<?php

declare(strict_types=1);

namespace Drupal\surface_gate_test\Storage;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceStorageInterface;

/**
 * The batch: each item's note, written per node, and what prepare saw.
 *
 * Keeps no values of its own. Records every item prepare() was handed, so
 * a test can tell the storage saw only what the gates allowed.
 */
final class GateBatchStorage implements ResourceStorageInterface {

  /**
   * The State key of what prepare() was handed, call after call.
   */
  public const SEEN = 'surface_gate_test.seen';

  /**
   * Constructs a GateBatchStorage.
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
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(OperationContext $context, array $values, AccountInterface $account): array {
    $seen = (array) $this->state->get(self::SEEN, []);
    $seen[] = array_map(static fn (array $item): int => (int) $item['node'], array_values((array) ($values['items'] ?? [])));
    $this->state->set(self::SEEN, $seen);
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function commit(OperationContext $context, array $prepared, AccountInterface $account): void {
    $notes = (array) $this->state->get(GateNoteStorage::KEY, []);
    foreach ($prepared['items'] ?? [] as $item) {
      $notes[(int) $item['node']] = $item['note'] ?? NULL;
    }
    $this->state->set(GateNoteStorage::KEY, $notes);
  }

}
