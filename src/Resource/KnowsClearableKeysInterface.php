<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource;

/**
 * A storage that says which keys it may hold nothing for.
 *
 * Null on an optional key clears it, where clearing is possible, and only
 * the storage knows where it is: a config object whose schema is fully
 * validatable holds a value for every key its schema does not call
 * nullable, and would refuse null there. A storage implementing this is
 * asked once per key when a resource is built, and a key it answers FALSE
 * for is marked as always held
 * (\Drupal\data_surface\DefinitionMetadata::setAlwaysHeld()), which the
 * pipeline, both schemas and the widgets read. A storage that does not
 * implement it, or answers NULL, says nothing, and the key may be
 * cleared as before.
 *
 * @see docs/decisions.md#a-key-its-storage-always-holds
 */
interface KnowsClearableKeysInterface {

  /**
   * Answers whether a key may be stored as nothing.
   *
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   Where the resource is being asked for.
   * @param string $path
   *   The key's dotted path from the top of what this storage stores: a
   *   key of the resource, or a key of a part attached to it that the
   *   storage stores under the part's key (`seating.aisle`).
   *
   * @return bool|null
   *   TRUE when it may hold nothing, FALSE when it always holds a value,
   *   NULL when the storage does not know.
   */
  public function mayClear(OperationContext $context, string $path): ?bool;

}
