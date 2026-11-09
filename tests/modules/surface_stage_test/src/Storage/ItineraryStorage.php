<?php

declare(strict_types=1);

namespace Drupal\surface_stage_test\Storage;

use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\surface\Pipeline\StorageViolationsException;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Pipeline\ViolationSet;
use Drupal\surface\Pipeline\TransactionalStorageInterface;
use Drupal\surface\Resource\DeletableStorageInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\NamesNothingException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Keeps trips in a key-value collection, one per id.
 *
 * The database's collection, with no cache in front of it, as the
 * ledger's: a transaction around a commit rolls it back whole. While the
 * State key REFUSE holds a count, each prepare takes one from it, and the
 * prepare that takes the last refuses the trip: how a test makes a write
 * refused after its dry run held, as the flow's first step is.
 */
final class ItineraryStorage implements DeletableStorageInterface, TransactionalStorageInterface {

  /**
   * The key-value collection the trips are kept in.
   */
  public const COLLECTION = 'stage_test.itinerary';

  /**
   * The State key counting the prepares before one refuses.
   */
  public const REFUSE = 'surface_stage_test.itinerary_refuse';

  /**
   * Constructs an ItineraryStorage.
   *
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $keyValue
   *   The database's key-value factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service, autowired, read for REFUSE alone.
   */
  public function __construct(
    #[Autowire(service: 'keyvalue.database')]
    protected readonly KeyValueFactoryInterface $keyValue,
    protected readonly StateInterface $state,
  ) {}

  /**
   * Gets the trip an operation's id names, for its context to check.
   *
   * @param string $id
   *   The id the operation was handed.
   * @param string $operation
   *   The operation and the resource, for the message.
   *
   * @return array
   *   The trip.
   *
   * @throws \Drupal\surface\ResourceBuild\NamesNothingException
   *   At `id`, when no trip has the id.
   */
  public function named(string $id, string $operation): array {
    $trip = $this->trips()->get($id);
    if (!is_array($trip)) {
      throw new NamesNothingException(sprintf('%s takes the id of a trip, and there is none with the id "%s".', $operation, $id), 'id');
    }
    return $trip;
  }

  /**
   * {@inheritdoc}
   */
  public function load(OperationContext $context): array {
    $id = $context->known['id'] ?? NULL;
    $trip = is_string($id) ? $this->trips()->get($id) : NULL;
    return is_array($trip) ? $trip : [];
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(OperationContext $context, array $values, AccountInterface $account): array {
    $id = $context->known['id'] ?? $values['id'] ?? NULL;
    if (!is_string($id) || $id === '') {
      throw new \LogicException('A trip was prepared with no id.');
    }
    $left = $this->state->get(self::REFUSE);
    if (is_int($left) && $left > 0) {
      $this->state->set(self::REFUSE, $left - 1);
      if ($left === 1) {
        throw new StorageViolationsException(new ViolationSet([
          new Violation('id', '', 'The trip cannot be written now.', Violation::CONFLICT),
        ]));
      }
    }
    return ['id' => $id] + $values;
  }

  /**
   * {@inheritdoc}
   */
  public function commit(OperationContext $context, array $prepared, AccountInterface $account): void {
    $this->trips()->set((string) $prepared['id'], $prepared);
  }

  /**
   * {@inheritdoc}
   */
  public function prepareDelete(OperationContext $context, AccountInterface $account): array {
    $id = $context->known['id'] ?? NULL;
    if (!is_string($id) || $id === '') {
      throw new \LogicException('A trip was canceled with no id.');
    }
    return ['id' => $id];
  }

  /**
   * {@inheritdoc}
   */
  public function delete(OperationContext $context, array $prepared, AccountInterface $account): void {
    $this->trips()->delete($prepared['id']);
  }

  /**
   * {@inheritdoc}
   */
  public function exists(OperationContext $context): bool {
    $id = $context->known['id'] ?? NULL;
    return is_string($id) && $this->trips()->has($id);
  }

  /**
   * {@inheritdoc}
   */
  public function singularLabel(): string {
    return 'trip';
  }

  /**
   * {@inheritdoc}
   */
  public function rolledBack(): void {
    // The database's key-value collection keeps no cache to drop.
  }

  /**
   * Gets the collection.
   *
   * @return \Drupal\Core\KeyValueStore\KeyValueStoreInterface
   *   The trips, by id.
   */
  protected function trips(): KeyValueStoreInterface {
    return $this->keyValue->get(self::COLLECTION);
  }

}
