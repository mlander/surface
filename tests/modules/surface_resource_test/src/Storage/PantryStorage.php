<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Storage;

use Drupal\Core\State\StateInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceStorageInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Keeps one part of a pantry in state, by the pantry its context knows.
 *
 * The pantry and the two children that store apart each name a subclass,
 * so a test reads exactly what each storage class was handed, and with which
 * identity: the context's known identity is stored beside the values.
 */
class PantryStorage implements ResourceStorageInterface {

  /**
   * The part of the pantry this storage keeps.
   */
  public const PART = 'pantry';

  /**
   * Constructs a PantryStorage.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service, autowired.
   */
  public function __construct(protected readonly StateInterface $state) {}

  /**
   * Gets the state key one part of a pantry is kept under.
   *
   * @param string $pantry
   *   The pantry.
   * @param string $part
   *   The part.
   *
   * @return string
   *   The key.
   */
  public static function key(string $pantry, string $part = self::PART): string {
    return 'surface_resource_test.' . $part . '.' . $pantry;
  }

  /**
   * {@inheritdoc}
   */
  public function load(OperationContext $context): array {
    $pantry = $context->known['pantry'] ?? NULL;
    return is_string($pantry) ? ($this->state->get(self::key($pantry, static::PART), [])['values'] ?? []) : [];
  }

  /**
   * {@inheritdoc}
   *
   * State has no schema and refuses nothing, so what would be stored is
   * what was accepted.
   */
  public function prepare(OperationContext $context, array $values, AccountInterface $account): array {
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function commit(OperationContext $context, array $prepared, AccountInterface $account): void {
    $values = $prepared;
    // A new pantry is named by what was accepted; a part stored apart
    // has only its context to go by.
    $pantry = $context->known['pantry'] ?? $values['pantry'] ?? NULL;
    if (!is_string($pantry)) {
      throw new \LogicException(sprintf('The %s was committed with no pantry known.', static::PART));
    }
    $this->state->set(self::key($pantry, static::PART), ['values' => $values, 'known' => $context->known]);
  }

}
