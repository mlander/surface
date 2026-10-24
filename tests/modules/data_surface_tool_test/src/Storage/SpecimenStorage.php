<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool_test\Storage;

use Drupal\Core\State\StateInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceStorageInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Keeps the specimen in State.
 */
final class SpecimenStorage implements ResourceStorageInterface {

  /**
   * The state key the values are kept under.
   */
  public const KEY = 'data_surface_tool_test.specimen';

  /**
   * Constructs a SpecimenStorage.
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
