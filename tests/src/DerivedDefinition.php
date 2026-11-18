<?php

declare(strict_types=1);

namespace Drupal\Tests\surface;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Contract\DefinitionFromSchema;
use Drupal\surface\Entries;
use Drupal\surface\Entry;
use Drupal\surface\SurfaceInterface;

/**
 * Gets the Typed Data definition of a data surface's key, for tests.
 *
 * A data surface answers schemas only (SurfaceInterface::getSchema()). A
 * test that holds a fact a definition states reads it here, as an edge
 * reads a key's definition: from the entry.
 */
final class DerivedDefinition {

  /**
   * Gets the definition of one key of a data surface.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param string $key
   *   The key.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The key's definition, or NULL when the data surface declares no
   *   such key.
   */
  public static function at(SurfaceInterface $surface, string $key): ?DataDefinitionInterface {
    return static::in($surface->entries(), $key);
  }

  /**
   * Gets the definition of one key of a set of entries.
   *
   * @param \Drupal\surface\Entries $entries
   *   The entries.
   * @param string $key
   *   The key.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The key's definition, or NULL when there is no such key.
   */
  public static function in(Entries $entries, string $key): ?DataDefinitionInterface {
    $entry = $entries->entry($key);
    return $entry === NULL ? NULL : DefinitionFromSchema::forEntry($entry);
  }

  /**
   * Gets the definition of every key of a set of entries.
   *
   * @param \Drupal\surface\Entries $entries
   *   The entries.
   *
   * @return array<string, \Drupal\Core\TypedData\DataDefinitionInterface>
   *   Each key's definition, in declaration order.
   */
  public static function all(Entries $entries): array {
    return array_map(static fn (Entry $entry): DataDefinitionInterface => DefinitionFromSchema::forEntry($entry), $entries->entries());
  }

}
