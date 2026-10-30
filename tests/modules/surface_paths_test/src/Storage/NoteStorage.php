<?php

declare(strict_types=1);

namespace Drupal\surface_paths_test\Storage;

use Drupal\Core\State\StateInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceStorageInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Keeps a singleton's note in State, under one key for all of them.
 */
final class NoteStorage implements ResourceStorageInterface {

  /**
   * The state key the values are kept under.
   */
  public const KEY = 'surface_paths_test.note';

  /**
   * Constructs a NoteStorage.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service, autowired.
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
   *
   * State has no schema and refuses nothing.
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
