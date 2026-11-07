<?php

declare(strict_types=1);

namespace Drupal\surface_stage_test\Storage;

use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceStorageInterface;
use Drupal\surface\ResourceBuild\NamesNothingException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Keeps ledger rows in a key-value collection, one row per id.
 *
 * The collection is the database's, with no cache in front of it, so a
 * commit is one database write and a transaction around it rolls it back
 * whole. Touching a row counts the touches beside its keys. A commit
 * fails, as a runtime failure would, while the State key FAIL names the
 * row's id: how a test makes an apply roll back.
 */
final class LedgerStorage implements ResourceStorageInterface {

  /**
   * The key-value collection the rows are kept in.
   */
  public const COLLECTION = 'stage_test.ledger';

  /**
   * The State key that names a row whose commit fails.
   */
  public const FAIL = 'surface_stage_test.fail';

  /**
   * Constructs a LedgerStorage.
   *
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $keyValue
   *   The database's key-value factory, whatever the site's default is.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service, autowired, read for the failing row alone.
   */
  public function __construct(
    #[Autowire(service: 'keyvalue.database')]
    protected readonly KeyValueFactoryInterface $keyValue,
    protected readonly StateInterface $state,
  ) {}

  /**
   * Gets the row an operation's id names, for its context to check.
   *
   * @param string $id
   *   The id the operation was handed.
   * @param string $operation
   *   The operation and the resource, for the message.
   *
   * @return array
   *   The row.
   *
   * @throws \Drupal\surface\ResourceBuild\NamesNothingException
   *   At `id`, when no row has the id.
   */
  public function named(string $id, string $operation): array {
    $row = $this->rows()->get($id);
    if (!is_array($row)) {
      throw new NamesNothingException(sprintf('%s takes the id of a ledger row, and there is none with the id "%s".', $operation, $id), 'id');
    }
    return $row;
  }

  /**
   * {@inheritdoc}
   */
  public function load(OperationContext $context): array {
    $id = $context->known['id'] ?? NULL;
    $row = is_string($id) ? $this->rows()->get($id) : NULL;
    if (!is_array($row)) {
      return [];
    }
    unset($row['touched']);
    return $row;
  }

  /**
   * {@inheritdoc}
   *
   * The row as it would be written; a touch is the id it touches.
   */
  public function prepare(OperationContext $context, array $values, AccountInterface $account): array {
    $id = $context->known['id'] ?? $values['id'] ?? NULL;
    if (!is_string($id) || $id === '') {
      throw new \LogicException('A ledger row was prepared with no id.');
    }
    return $context->id === 'touch' ? ['id' => $id] : ['id' => $id] + $values;
  }

  /**
   * {@inheritdoc}
   */
  public function commit(OperationContext $context, array $prepared, AccountInterface $account): void {
    $id = (string) $prepared['id'];
    if ($this->state->get(self::FAIL) === $id) {
      throw new \RuntimeException(sprintf('The ledger row "%s" could not be written.', $id));
    }
    $row = $this->rows()->get($id);
    $touched = is_array($row) ? (int) ($row['touched'] ?? 0) : 0;
    if ($context->id === 'touch') {
      $this->rows()->set($id, ['touched' => $touched + 1] + (is_array($row) ? $row : []));
      return;
    }
    $this->rows()->set($id, $prepared + ['touched' => $touched]);
  }

  /**
   * Counts how many times a row was touched.
   *
   * @param string $id
   *   The row.
   *
   * @return int
   *   The count.
   */
  public function touches(string $id): int {
    $row = $this->rows()->get($id);
    return is_array($row) ? (int) ($row['touched'] ?? 0) : 0;
  }

  /**
   * Gets the collection.
   *
   * @return \Drupal\Core\KeyValueStore\KeyValueStoreInterface
   *   The rows, by id.
   */
  protected function rows(): KeyValueStoreInterface {
    return $this->keyValue->get(self::COLLECTION);
  }

}
