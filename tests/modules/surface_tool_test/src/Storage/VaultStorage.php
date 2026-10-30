<?php

declare(strict_types=1);

namespace Drupal\surface_tool_test\Storage;

use Drupal\Core\State\StateInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceStorageInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Keeps the vault's settings in state, secrets and all.
 */
final class VaultStorage implements ResourceStorageInterface {

  /**
   * The state key the settings are kept under.
   */
  public const KEY = 'surface_tool_test.vault';

  /**
   * Constructs a VaultStorage.
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
