<?php

declare(strict_types=1);

namespace Drupal\data_surface\Reference;

use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMap;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Entry;
use Drupal\data_surface\Storage\StoredSchemaInterface;

/**
 * Stores a reference to a content entity by its uuid, in config.
 *
 * A content entity's id differs between sites: node 7 here is another
 * node, or none, on the site a config export is imported into. Its uuid
 * is the same on both, which is why core names content by uuid in a
 * config entity's dependencies. A config entity's id is its machine name,
 * the same everywhere, so a reference to one stays its id.
 *
 * The engine applies this layout to every resource stored in a config
 * storage, ConfigObjectStorage or ConfigEntityStorage (StorageAdapter),
 * outside any layout the storage declares, in the layout values are asked
 * for: every door sends and answers the id, and only the config holds the
 * uuid.
 *
 * - A key that refers to one content entity type (`entity:user`,
 *   `entity:node:article`) is stored as the entity's uuid, its config
 *   schema type `uuid`.
 * - A bare `entity` key is stored as `{entity_type, id}` whatever entity
 *   type it holds: `id` holds the uuid where `entity_type` names a
 *   content entity type and the id where it names a config entity type,
 *   so its schema, two strings, is one shape.
 * - A key whose author kept the id (References::STORED_AS) is left alone.
 *
 * Read back, a uuid whose entity is gone, or not on this site yet, reads
 * as nothing stored (a bare key's two parts both), and a write that holds
 * nothing for such a key keeps it (keepUnresolved()), as a stale value is
 * kept: it names its entity again once that entity is there. A stored
 * value that is no uuid is read as an id, which is what such a key held
 * before this layout, and stored as the uuid at the next write.
 *
 * Keys are found where a config storage stores them: at the top, inside
 * an attached structure and a map, in each item of a collection, in the
 * variant a deciding key chose among variants a class declares, and in
 * what another module mounts. Variants the site lists are stored open
 * (`type: ignore`) and keep the id.
 *
 * Unlike an author's layout, this one reads the site: a uuid's entity is
 * site state by definition. It reads it the same way for every door.
 *
 * Decision: see docs/decisions.md#a-config-object-stores-a-reference-by-uuid.
 *
 * @see \Drupal\data_surface\ResourceBuild\StorageAdapter
 */
final class ReferenceStorageLayout implements StoredSchemaInterface {

  /**
   * The config schema of a key stored as a uuid.
   */
  public const UUID_SCHEMA = ['type' => 'uuid'];

  /**
   * The bare `entity` key's parts.
   */
  protected const TYPE = 'entity_type';

  /**
   * The bare `entity` key's id part.
   */
  protected const ID = 'id';

  /**
   * Constructs a ReferenceStorageLayout.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The resource, whose keys say which are references.
   * @param \Drupal\data_surface\Reference\References $references
   *   The references service, which finds an entity by id or uuid.
   * @param string[] $skip
   *   Top-level keys left alone: those a child with storage of its own
   *   holds, which its own storage translates.
   */
  public function __construct(
    protected readonly DataSurfaceInterface $surface,
    protected readonly References $references,
    protected readonly array $skip = [],
  ) {}

  /**
   * Gives the layout for a resource, when it has a key to translate.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The resource.
   * @param \Drupal\data_surface\Reference\References $references
   *   The references service.
   * @param string[] $skip
   *   Top-level keys left alone.
   *
   * @return self|null
   *   The layout, or NULL when no key may be stored by uuid.
   */
  public static function forSurface(DataSurfaceInterface $surface, References $references, array $skip = []): ?self {
    return static::holds($surface->getDefinitions(), $skip) ? new self($surface, $references, $skip) : NULL;
  }

  /**
   * Gives the config schema of each key a resource stores as a uuid.
   *
   * What the config schema check and the generator read
   * (ConfigObjectStorage::translatedKeys()): a key that refers to one
   * content entity type. A bare `entity` key is left out: it is stored as
   * two strings, as it is asked for.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The resource.
   *
   * @return array<string, array>
   *   UUID_SCHEMA, by each such key's dotted path: a collection's item key
   *   and a variant's key under the key holding them, with no delta and
   *   no variant.
   */
  public static function storedSchemaOf(DataSurfaceInterface $surface): array {
    $schema = [];
    static::listUuidKeys($surface->getDefinitions(), '', $schema);
    return $schema;
  }

  /**
   * {@inheritdoc}
   */
  public function storedSchema(): array {
    return array_filter(static::storedSchemaOf($this->surface), fn (string $path): bool => !in_array(explode('.', $path)[0], $this->skip, TRUE), ARRAY_FILTER_USE_KEY);
  }

  /**
   * {@inheritdoc}
   *
   * Each id of a content entity becomes its uuid. An id that names no
   * entity is left as it is, for the config schema to refuse.
   */
  public function toStorage(array $values): array {
    return $this->map($this->surface->getDefinitions(), $values, TRUE, TRUE);
  }

  /**
   * {@inheritdoc}
   *
   * Each uuid becomes its entity's id, typed as the key holds it; one that
   * names no entity reads as nothing stored.
   */
  public function fromStorage(array $settings): array {
    return $this->map($this->surface->getDefinitions(), $settings, FALSE, TRUE);
  }

  /**
   * Keeps a stored reference to an entity that is not there.
   *
   * What fromStorage() read as nothing stored: a write that still holds
   * nothing for it writes the stored uuid back, so a reference to an
   * entity not on this site yet survives a save made before it arrives.
   * A new choice replaces it. An id stored before this layout that names
   * nothing is not kept: it names nothing on any site. Inside a
   * collection an item's position may hold another item, so nothing is
   * kept there.
   *
   * @param array $values
   *   What is being written, after toStorage().
   * @param array $stored
   *   What the storage holds, before fromStorage().
   *
   * @return array
   *   The values, each such reference kept.
   */
  public function keepUnresolved(array $values, array $stored): array {
    return $this->keep($this->surface->getDefinitions(), $values, $stored, TRUE);
  }

  /**
   * Gives the config dependencies a config entity's references bring.
   *
   * As core's own config entities name them: a config entity by its
   * config name under `config`, a content entity as
   * `<entity type>:<bundle>:<uuid>` under `content`.
   *
   * @param array $values
   *   The values, in the layout they are asked for (ids).
   *
   * @return array<string, string[]>
   *   The dependency names by type, `config` and `content`, each sorted.
   */
  public function dependencies(array $values): array {
    $found = [];
    $this->collect($this->surface->getDefinitions(), $values, TRUE, $found);
    $dependencies = [];
    foreach ($found as $entity) {
      $dependencies[$entity->getConfigDependencyKey()][] = $entity->getConfigDependencyName();
    }
    foreach ($dependencies as $type => $names) {
      $names = array_values(array_unique($names));
      sort($names);
      $dependencies[$type] = $names;
    }
    ksort($dependencies);
    return $dependencies;
  }

  /**
   * Answers whether a map holds a key that may be stored by uuid.
   *
   * @param \Drupal\data_surface\DefinitionMap $map
   *   The keys.
   * @param string[] $skip
   *   Keys left alone.
   *
   * @return bool
   *   TRUE when one does, at any depth.
   */
  protected static function holds(DefinitionMap $map, array $skip = []): bool {
    foreach ($map->entries() as $name => $entry) {
      if (in_array((string) $name, $skip, TRUE)) {
        continue;
      }
      if (References::storedByUuid($entry->definition) || static::bare($entry) !== NULL) {
        return TRUE;
      }
      foreach (static::children($entry) as $child) {
        if (static::holds($child)) {
          return TRUE;
        }
      }
      if (static::definitionHolds($entry->definition)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Answers whether a plain map definition holds a key stored by uuid.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return bool
   *   TRUE when one of its properties, at any depth, is.
   */
  protected static function definitionHolds(DataDefinitionInterface $definition): bool {
    if (!$definition instanceof ComplexDataDefinitionInterface) {
      return References::storedByUuid($definition);
    }
    foreach ($definition->getPropertyDefinitions() as $property) {
      if (static::definitionHolds($property)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Lists every child map a key holds whose keys are stored with it.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The key.
   *
   * @return array<string, \Drupal\data_surface\DefinitionMap>
   *   An attached structure's keys, a collection's item's, each variant a
   *   class declares, and each structure an alter mounted, by variant id
   *   or module; empty for any other key.
   */
  protected static function children(Entry $entry): array {
    if ($entry->list !== NULL) {
      return ['' => $entry->list->child()->getDefinitions()];
    }
    if ($entry->attachment !== NULL) {
      return DefinitionMetadata::eachOf($entry->definition) === NULL && static::bare($entry) === NULL ? ['' => $entry->attachment->child->getDefinitions()] : [];
    }
    if ($entry->variants !== NULL) {
      if (DefinitionMetadata::variantsFrom($entry->variants->shell) !== NULL) {
        return [];
      }
      $maps = [];
      foreach ($entry->variants->variants as $id => $variant) {
        $maps[(string) $id] = $variant->child->getDefinitions();
      }
      return $maps;
    }
    $maps = [];
    foreach ($entry->mounted as $module => $mounted) {
      $maps[(string) $module] = $mounted->child->getDefinitions();
    }
    return $maps;
  }

  /**
   * Reads a bare `entity` key's id part, when a key is one.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The key.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The id part, unless its author kept the id; NULL for any other key.
   */
  protected static function bare(Entry $entry): ?DataDefinitionInterface {
    if ($entry->attachment === NULL || $entry->list !== NULL) {
      return NULL;
    }
    $id = $entry->attachment->child->getDefinition(self::ID);
    if ($id === NULL || $id->getSetting(EntityReferenceStructure::TARGETS) === NULL || $id->getSetting(References::STORED_AS) === References::BY_ID) {
      return NULL;
    }
    return $id;
  }

  /**
   * Lists the keys of a map stored as a uuid, with their schema.
   *
   * @param \Drupal\data_surface\DefinitionMap $map
   *   The keys.
   * @param string $prefix
   *   Their path, a dotted path and a dot; empty at the top.
   * @param array<string, array> $schema
   *   The keys found, added to.
   */
  protected static function listUuidKeys(DefinitionMap $map, string $prefix, array &$schema): void {
    foreach ($map->entries() as $name => $entry) {
      $path = $prefix . $name;
      if (References::storedByUuid($entry->definition)) {
        $schema[$path] = self::UUID_SCHEMA;
        continue;
      }
      $children = static::children($entry);
      if ($entry->attachment !== NULL || $entry->list !== NULL || $entry->variants !== NULL) {
        // An item's keys, and a variant's, sit under the key itself.
        foreach ($children as $child) {
          static::listUuidKeys($child, $path . '.', $schema);
        }
        continue;
      }
      // A plain map, and the structures other modules mounted in it, each
      // under the module's name.
      foreach ($children as $module => $child) {
        static::listUuidKeys($child, $path . '.' . $module . '.', $schema);
      }
      if ($entry->definition instanceof ComplexDataDefinitionInterface) {
        foreach ($entry->definition->getPropertyDefinitions() as $part => $property) {
          if (isset($children[(string) $part])) {
            continue;
          }
          if (References::storedByUuid($property)) {
            $schema[$path . '.' . $part] = self::UUID_SCHEMA;
          }
          else {
            static::listDefinition($property, $path . '.' . $part, $schema);
          }
        }
      }
    }
  }

  /**
   * Lists the properties of a plain map stored as a uuid.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The map.
   * @param string $path
   *   Its dotted path.
   * @param array<string, array> $schema
   *   The keys found, added to.
   */
  protected static function listDefinition(DataDefinitionInterface $definition, string $path, array &$schema): void {
    if (!$definition instanceof ComplexDataDefinitionInterface) {
      return;
    }
    foreach ($definition->getPropertyDefinitions() as $name => $property) {
      if (References::storedByUuid($property)) {
        $schema[$path . '.' . $name] = self::UUID_SCHEMA;
      }
      else {
        static::listDefinition($property, $path . '.' . $name, $schema);
      }
    }
  }

  /**
   * Translates the references of one map's values, either way.
   *
   * @param \Drupal\data_surface\DefinitionMap $map
   *   The keys.
   * @param array $values
   *   Their values.
   * @param bool $to
   *   TRUE to store (id to uuid), FALSE to read (uuid to id).
   * @param bool $top
   *   TRUE for the resource's own keys, where some are skipped.
   *
   * @return array
   *   The values, translated.
   */
  protected function map(DefinitionMap $map, array $values, bool $to, bool $top = FALSE): array {
    foreach ($map->entries() as $name => $entry) {
      $name = (string) $name;
      if (!array_key_exists($name, $values) || ($top && in_array($name, $this->skip, TRUE))) {
        continue;
      }
      $value = $values[$name];
      if (References::storedByUuid($entry->definition)) {
        $values[$name] = $this->one($entry->definition, $value, $to);
        continue;
      }
      if (!is_array($value)) {
        continue;
      }
      if (static::bare($entry) !== NULL) {
        $values[$name] = $this->pair($value, $to);
        continue;
      }
      if ($entry->list !== NULL) {
        $item = $entry->list->child()->getDefinitions();
        foreach ($value as $delta => $one) {
          if (is_array($one)) {
            $values[$name][$delta] = $this->map($item, $one, $to);
          }
        }
        continue;
      }
      if ($entry->variants !== NULL) {
        $chosen = $entry->variants->chosen($values[$entry->variants->by] ?? NULL);
        $children = static::children($entry);
        if ($chosen !== NULL && isset($children[$chosen])) {
          $values[$name] = $this->map($children[$chosen], $value, $to);
        }
        continue;
      }
      if ($entry->attachment !== NULL) {
        $children = static::children($entry);
        if (isset($children[''])) {
          $values[$name] = $this->map($children[''], $value, $to);
        }
        continue;
      }
      // A plain map, and the structures other modules mounted in it.
      $mounted = static::children($entry);
      foreach ($value as $part => $settings) {
        if (isset($mounted[(string) $part])) {
          if (is_array($settings)) {
            $values[$name][$part] = $this->map($mounted[(string) $part], $settings, $to);
          }
        }
        elseif ($entry->definition instanceof ComplexDataDefinitionInterface && ($property = $entry->definition->getPropertyDefinition((string) $part)) !== NULL) {
          $values[$name][$part] = $this->plain($property, $settings, $to);
        }
      }
    }
    return $values;
  }

  /**
   * Translates the references in a plain map's value.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The map.
   * @param mixed $value
   *   Its value.
   * @param bool $to
   *   TRUE to store, FALSE to read.
   *
   * @return mixed
   *   The value, translated.
   */
  protected function plain(DataDefinitionInterface $definition, mixed $value, bool $to): mixed {
    if (References::storedByUuid($definition)) {
      return $this->one($definition, $value, $to);
    }
    if (!$definition instanceof ComplexDataDefinitionInterface || !is_array($value)) {
      return $value;
    }
    foreach ($definition->getPropertyDefinitions() as $name => $property) {
      if (array_key_exists($name, $value)) {
        $value[$name] = $this->plain($property, $value[$name], $to);
      }
    }
    return $value;
  }

  /**
   * Translates one key that refers to one content entity type.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key.
   * @param mixed $value
   *   An id to store, or a stored uuid to read.
   * @param bool $to
   *   TRUE to store, FALSE to read.
   *
   * @return mixed
   *   The uuid, or the id typed as the key holds it; NULL for nothing.
   */
  protected function one(DataDefinitionInterface $definition, mixed $value, bool $to): mixed {
    $type = References::target($definition)['entityType'] ?? NULL;
    if ($type === NULL || $value === NULL || $value === '') {
      return $to ? $value : NULL;
    }
    if ($to) {
      return $this->references->uuidOf($type, $value) ?? $value;
    }
    $entity = $this->references->stored($type, $value);
    return $entity === NULL ? NULL : static::typed($entity, $definition->getDataType());
  }

  /**
   * Translates a bare `entity` key's two parts.
   *
   * @param array $value
   *   The two parts, `{entity_type, id}`.
   * @param bool $to
   *   TRUE to store, FALSE to read.
   *
   * @return array
   *   The two parts, the id the uuid where the entity type is a content
   *   entity type; read back, both nothing where the uuid names nothing.
   */
  protected function pair(array $value, bool $to): array {
    $type = $value[self::TYPE] ?? NULL;
    $id = $value[self::ID] ?? NULL;
    if (!is_string($type) || $type === '' || $id === NULL || $id === '' || !$this->references->isContent($type)) {
      return $value;
    }
    if ($to) {
      $value[self::ID] = $this->references->uuidOf($type, $id) ?? $id;
      return $value;
    }
    $entity = $this->references->stored($type, $id);
    if ($entity === NULL) {
      return [self::TYPE => NULL, self::ID => NULL] + $value;
    }
    $value[self::ID] = (string) $entity->id();
    return $value;
  }

  /**
   * Keeps each unresolved reference of one map that a write holds nothing for.
   *
   * @param \Drupal\data_surface\DefinitionMap $map
   *   The keys.
   * @param array $values
   *   What is written, in storage layout.
   * @param array $stored
   *   What is stored, in storage layout.
   * @param bool $top
   *   TRUE for the resource's own keys.
   *
   * @return array
   *   The values.
   */
  protected function keep(DefinitionMap $map, array $values, array $stored, bool $top = FALSE): array {
    foreach ($map->entries() as $name => $entry) {
      $name = (string) $name;
      if (!array_key_exists($name, $values) || !array_key_exists($name, $stored) || ($top && in_array($name, $this->skip, TRUE))) {
        continue;
      }
      $value = $values[$name];
      $held = $stored[$name];
      if (References::storedByUuid($entry->definition)) {
        if (($value === NULL || $value === '') && is_string($held) && Uuid::isValid($held) && $this->one($entry->definition, $held, FALSE) === NULL) {
          $values[$name] = $held;
        }
        continue;
      }
      if (!is_array($value) || !is_array($held) || $entry->list !== NULL) {
        continue;
      }
      if (static::bare($entry) !== NULL) {
        $nothing = ($value[self::TYPE] ?? NULL) === NULL && ($value[self::ID] ?? NULL) === NULL;
        if ($nothing && is_string($held[self::ID] ?? NULL) && Uuid::isValid($held[self::ID]) && $this->pair($held, FALSE)[self::ID] === NULL) {
          $values[$name] = $held;
        }
        continue;
      }
      if ($entry->variants !== NULL) {
        $chosen = $entry->variants->chosen($values[$entry->variants->by] ?? NULL);
        $children = static::children($entry);
        if ($chosen !== NULL && isset($children[$chosen]) && $chosen === $entry->variants->chosen($stored[$entry->variants->by] ?? NULL)) {
          $values[$name] = $this->keep($children[$chosen], $value, $held);
        }
        continue;
      }
      foreach (static::children($entry) as $id => $child) {
        if ($id === '') {
          $values[$name] = $this->keep($child, $value, $held);
        }
        elseif (is_array($value[$id] ?? NULL) && is_array($held[$id] ?? NULL)) {
          $values[$name][$id] = $this->keep($child, $value[$id], $held[$id]);
        }
      }
    }
    return $values;
  }

  /**
   * Collects the entities one map's references name.
   *
   * @param \Drupal\data_surface\DefinitionMap $map
   *   The keys.
   * @param array $values
   *   Their values, ids.
   * @param bool $top
   *   TRUE for the resource's own keys.
   * @param \Drupal\Core\Entity\EntityInterface[] $found
   *   The entities, added to.
   */
  protected function collect(DefinitionMap $map, array $values, bool $top, array &$found): void {
    foreach ($map->entries() as $name => $entry) {
      $name = (string) $name;
      if (!array_key_exists($name, $values) || ($top && in_array($name, $this->skip, TRUE))) {
        continue;
      }
      $value = $values[$name];
      $target = References::target($entry->definition);
      if ($target !== NULL) {
        $entity = $value === NULL || $value === '' ? NULL : $this->entity($target['entityType'], $value);
        if ($entity !== NULL) {
          $found[] = $entity;
        }
        continue;
      }
      if (!is_array($value)) {
        continue;
      }
      $id = $entry->attachment?->child->getDefinition(self::ID);
      if ($entry->list === NULL && $id !== NULL && $id->getSetting(EntityReferenceStructure::TARGETS) !== NULL) {
        if (is_string($value[self::TYPE] ?? NULL) && isset($value[self::ID]) && $value[self::ID] !== '') {
          $entity = $this->entity($value[self::TYPE], $value[self::ID]);
          if ($entity !== NULL) {
            $found[] = $entity;
          }
        }
        continue;
      }
      if ($entry->list !== NULL) {
        foreach ($value as $one) {
          if (is_array($one)) {
            $this->collect($entry->list->child()->getDefinitions(), $one, FALSE, $found);
          }
        }
        continue;
      }
      $children = static::children($entry);
      if ($entry->variants !== NULL) {
        $chosen = $entry->variants->chosen($values[$entry->variants->by] ?? NULL);
        if ($chosen !== NULL && isset($children[$chosen])) {
          $this->collect($children[$chosen], $value, FALSE, $found);
        }
        continue;
      }
      foreach ($children as $child_id => $child) {
        if ($child_id === '') {
          $this->collect($child, $value, FALSE, $found);
        }
        elseif (is_array($value[$child_id] ?? NULL)) {
          $this->collect($child, $value[$child_id], FALSE, $found);
        }
      }
    }
  }

  /**
   * Loads an entity by its id, of any entity type.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param mixed $id
   *   The id.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity, or NULL.
   */
  protected function entity(string $entity_type_id, mixed $id): ?EntityInterface {
    return is_int($id) || is_string($id) ? $this->references->stored($entity_type_id, $id) : NULL;
  }

  /**
   * Gives an entity's id as a key holds it.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param string $data_type
   *   The key's data type: 'integer' or 'string'.
   *
   * @return int|string
   *   The id.
   */
  protected static function typed(EntityInterface $entity, string $data_type): int|string {
    $id = $entity->id();
    return $data_type === 'integer' && !$entity instanceof ConfigEntityInterface ? (int) $id : (string) $id;
  }

}
