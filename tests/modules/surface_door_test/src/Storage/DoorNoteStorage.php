<?php

declare(strict_types=1);

namespace Drupal\surface_door_test\Storage;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceStorageInterface;

/**
 * Keeps a note in State, reading nothing a door has.
 */
final class DoorNoteStorage implements ResourceStorageInterface {

  /**
   * The State key the note is kept under.
   */
  public const KEY = 'surface_door_test.note';

  /**
   * Constructs a DoorNoteStorage.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The State service, autowired.
   */
  public function __construct(protected readonly StateInterface $state) {}

  /**
   * {@inheritdoc}
   */
  public function load(OperationContext $context): array {
    return $this->state->get(self::KEY, []);
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
    $this->state->set(self::KEY, $prepared);
  }

}
