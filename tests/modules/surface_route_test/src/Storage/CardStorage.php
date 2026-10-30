<?php

declare(strict_types=1);

namespace Drupal\surface_route_test\Storage;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceStorageInterface;

/**
 * Keeps each card in state, by its machine name.
 */
final class CardStorage implements ResourceStorageInterface {

  /**
   * Constructs a CardStorage.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service, autowired.
   */
  public function __construct(protected readonly StateInterface $state) {}

  /**
   * Gets the state key a card is kept under.
   *
   * @param string $card
   *   The card's machine name.
   *
   * @return string
   *   The key.
   */
  public static function key(string $card): string {
    return 'surface_route_test.card.' . $card;
  }

  /**
   * {@inheritdoc}
   */
  public function load(OperationContext $context): array {
    $card = $context->known['card'] ?? NULL;
    return is_string($card) ? $this->state->get(self::key($card), []) : [];
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
    $card = $context->known['card'] ?? $prepared['card'] ?? NULL;
    if (!is_string($card)) {
      throw new \LogicException('A card was committed with no machine name.');
    }
    $this->state->set(self::key($card), $prepared);
  }

}
