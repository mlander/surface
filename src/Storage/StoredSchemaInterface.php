<?php

declare(strict_types=1);

namespace Drupal\surface\Storage;

/**
 * A layout that says the config schema of each key it translates.
 *
 * A config storage's layout (ConfigObjectStorage::layout()) stores some
 * keys otherwise than they are asked for: a checkbox per type, stored as
 * the list of the types checked. The build holds the resource to the
 * config schema its storage writes, and the config schema generator
 * writes that schema from the resource, so both need the stored shape of
 * those keys, and the layout is the one place that knows it. A layout
 * implementing this says it: the check skips each key it names, and the
 * generator writes the schema it gives instead of the asked one.
 *
 * Optional, beside StorageLayoutInterface rather than on it: a field
 * type's layout (AddressStorageLayout) is stored under a schema its owner
 * writes, which no generator reads. An alter's layout of its mount may
 * implement it too: the check and the generator then read each key it
 * names at `third_party_settings.<module>.<key>`.
 *
 * Decision: see
 * docs/decisions.md#a-layout-is-the-storages-one-word-on-its-shape.
 *
 * @see \Drupal\surface\Storage\ConfigObjectStorage::layout()
 */
interface StoredSchemaInterface extends StorageLayoutInterface {

  /**
   * Gives the config schema of each key this layout stores otherwise.
   *
   * @return array<string, array>
   *   Each key's config schema as stored, as a schema file writes a
   *   property (`['type' => 'sequence', 'sequence' => ['type' =>
   *   'string']]`), by the key's dotted path in the asked layout. A
   *   label left out is the key's own; `nullable` left out follows the
   *   generator's rule for the key.
   */
  public function storedSchema(): array;

}
