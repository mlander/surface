<?php

declare(strict_types=1);

namespace Drupal\surface_stage_test\Storage;

use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceStorageInterface;

/**
 * Keeps the vault note in a key-value collection, secret and all.
 */
final class VaultNoteStorage implements ResourceStorageInterface {

  /**
   * The key-value collection and key the note is kept under.
   */
  public const COLLECTION = 'stage_test.vault_note';

  /**
   * Constructs a VaultNoteStorage.
   *
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $keyValue
   *   The key-value factory, autowired.
   */
  public function __construct(protected readonly KeyValueFactoryInterface $keyValue) {}

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

}
