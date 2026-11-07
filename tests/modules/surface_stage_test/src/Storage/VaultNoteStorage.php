<?php

declare(strict_types=1);

namespace Drupal\surface_stage_test\Storage;

use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\surface\Pipeline\TransactionalStorageInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceStorageInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Keeps the vault note in a key-value collection, secret and all.
 *
 * It declares a transaction rolls its writes back whole, so a stage
 * refuses it for its secret and nothing before.
 */
final class VaultNoteStorage implements ResourceStorageInterface, TransactionalStorageInterface {

  /**
   * The key-value collection and key the note is kept under.
   */
  public const COLLECTION = 'stage_test.vault_note';

  /**
   * Constructs a VaultNoteStorage.
   *
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $keyValue
   *   The database's key-value factory, whatever the site's default is.
   */
  public function __construct(
    #[Autowire(service: 'keyvalue.database')]
    protected readonly KeyValueFactoryInterface $keyValue,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function load(OperationContext $context): array {
    $note = $this->keyValue->get(self::COLLECTION)->get('note');
    return is_array($note) ? $note : [];
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
    $this->keyValue->get(self::COLLECTION)->set('note', $prepared);
  }

  /**
   * {@inheritdoc}
   */
  public function rolledBack(): void {
    // The database's key-value collection keeps no cache to drop.
  }

}
