<?php

declare(strict_types=1);

namespace Drupal\surface_paths_test\Resource;

use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;

/**
 * Shelves: things with identity, each kind under its standard id.
 *
 * `create` and `list` on the collection, `read` and `delete` on a shelf,
 * and a second create, `stock`, and a second delete, `clear`, beside
 * them, which the standard ids leave custom methods.
 */
#[Resource('paths_test.shelf', identity: ['id'])]
#[Operation('create', label: 'Add a shelf', creates: TRUE)]
#[Operation('stock', label: 'Stock a new shelf', creates: TRUE)]
#[Operation('list', label: 'List the shelves', readOnly: TRUE)]
final class ShelfResource implements ResourceInterface {

  /**
   * Reads a shelf.
   */
  #[Operation('read', label: 'View a shelf', readOnly: TRUE)]
  public static function read(string $id): OperationContext {
    return new OperationContext(known: ['id' => $id]);
  }

  /**
   * Removes a shelf.
   */
  #[Operation('delete', label: 'Remove a shelf', destructive: TRUE, deletes: TRUE)]
  public static function delete(string $id): OperationContext {
    return new OperationContext(known: ['id' => $id]);
  }

  /**
   * Empties a shelf, and removes it.
   */
  #[Operation('clear', label: 'Clear a shelf away', destructive: TRUE, deletes: TRUE)]
  public static function clear(string $id): OperationContext {
    return new OperationContext(known: ['id' => $id]);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('id', 'string', 'Machine name')->setRequired(TRUE);
  }

}
