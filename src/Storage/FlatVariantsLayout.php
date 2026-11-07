<?php

declare(strict_types=1);

namespace Drupal\surface\Storage;

use Drupal\surface\DefinitionMap;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Variants;

/**
 * Stores the chosen variant's keys beside the deciding key, in config.
 *
 * Variants at a key are asked for under the key: the contract, the form,
 * the tool and the pipeline hold the chosen variant there, in its own
 * frame. Where the key's map says `Variants::STORED_AS` `Variants::FLAT`,
 * a config storage keeps the chosen keys beside the deciding key instead,
 * and nothing at the key's own name: the shape core's own-key schemas
 * describe (`condition.plugin.[id]`, a views handler), and the one
 * variants mixed in beside their deciding key are stored in.
 *
 * The engine applies this layout to every resource stored in a config
 * storage, ConfigObjectStorage or ConfigEntityStorage (StorageAdapter),
 * beside ReferenceStorageLayout and in the layout values are asked for:
 * fromStorage() before the references are read, toStorage() after they
 * are written; the layouts alters give their mounts are applied outside
 * it, as stored.
 *
 * - toStorage(): at each level holding a flat key, the map under the key
 *   is laid into the level where the key stands, and the key is removed;
 *   in every item of a collection, and every entry of a map of keys from
 *   the site.
 * - fromStorage(): at each such level, every stored key that is not one
 *   of the owner's own keys there is gathered under the key, by exclusion,
 *   as BlockPlacementStorage::fromEntity() gathers a condition's settings.
 *   Of what is gathered, the chosen variant's keys and the secrets the
 *   other variants keep (Variants::keptSecrets()) are read under the key;
 *   the rest was written for a variant not chosen, and is left out of
 *   what is read: the pipeline reads a value holding another variant's
 *   key as written for that variant, and would start the chosen one from
 *   its defaults (Variants::held()).
 * - keepOnWrite(): at the top level, what fromStorage() left out is kept:
 *   a variant not chosen keeps its keys in storage. A switch back writes
 *   that variant whole, from its defaults beneath what is sent, as the
 *   pipeline writes variants at a key once the deciding key moves on.
 * - storedSchema(): each flat key's path, StoredSchemaInterface::SPREAD:
 *   the check skips it, and the config schema generator writes the
 *   variants' keys at the level.
 *
 * The walk reaches a flat key at any depth the sealed surface holds: in an
 * attached structure, an item of a collection, an entry of a map of keys
 * from the site, a variant at a key and a structure another module mounts.
 * A variant the site lists is walked once a door has settled it; one not
 * settled yet is not, as ReferenceStorageLayout::children() does not walk
 * one.
 *
 * @see \Drupal\surface\Variants::STORED_AS
 * @see \Drupal\surface\ResourceBuild\StorageAdapter
 */
final class FlatVariantsLayout implements StoredSchemaInterface {

  /**
   * Constructs a FlatVariantsLayout.
   *
   * @param list<array{steps: list<array{key: string, each: bool, variants: \Drupal\surface\Variants|null, variant: string|null}>, key: string, own: string[], variants: \Drupal\surface\Variants}> $marks
   *   Each flat key, outermost first: the steps from the top to its level,
   *   its name, the owner's own keys at that level, and its variants.
   * @param array<string, array> $schema
   *   StoredSchemaInterface::SPREAD, by each flat key's dotted path.
   */
  protected function __construct(
    protected readonly array $marks,
    protected readonly array $schema,
  ) {}

  /**
   * Gives the layout for a resource, when it stores a key flat.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The resource, sealed.
   * @param string[] $skip
   *   Top-level keys left alone: those a child with storage of its own
   *   holds, which its own storage lays out.
   *
   * @return self|null
   *   The layout, or NULL when no key's variants are stored flat.
   */
  public static function forSurface(SurfaceInterface $surface, array $skip = []): ?self {
    $marks = [];
    $schema = [];
    static::walk($surface->getDefinitions(), [], '', $skip, $marks, $schema);
    return $marks === [] ? NULL : new self($marks, $schema);
  }

  /**
   * Gives the stored schema of each key a resource stores flat.
   *
   * What the config schema check and the generator read
   * (ConfigObjectStorage::translatedKeys()).
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The resource.
   *
   * @return array<string, array>
   *   StoredSchemaInterface::SPREAD, by each flat key's dotted path: a
   *   collection's item key and
   *   a variant's key under the key holding them, with no delta and no
   *   variant; an entry of a map of keys from the site as `*`.
   */
  public static function storedSchemaOf(SurfaceInterface $surface): array {
    return static::forSurface($surface)?->storedSchema() ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function storedSchema(): array {
    return $this->schema;
  }

  /**
   * {@inheritdoc}
   *
   * The innermost flat key first, so a flat key inside a variant stored
   * flat is laid into that variant's keys before they are laid out.
   */
  public function toStorage(array $values): array {
    foreach (array_reverse($this->marks) as $mark) {
      $values = static::atLevel($values, $mark['steps'], static fn (array $level): array => static::spread($level, $mark['key']));
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   *
   * The outermost flat key first, so what a variant stored flat holds is
   * gathered before a flat key of its own gathers from it.
   */
  public function fromStorage(array $settings): array {
    foreach ($this->marks as $mark) {
      $settings = static::atLevel($settings, $mark['steps'], static fn (array $level): array => static::gather($level, $mark));
    }
    return $settings;
  }

  /**
   * Keeps what a write leaves out at a level stored flat.
   *
   * Where ReferenceStorageLayout::keepUnresolved() keeps a stored value
   * the write replaces with nothing, this keeps a stored key the write
   * does not name at all: the keys of a variant not chosen, which
   * fromStorage() left out of what was read, so they stay in storage
   * while their variant is not chosen. A key at the flat key's own name is
   * a value stored before the key was flat, and is not kept.
   *
   * At the top level alone, where the stored schema holds every variant's
   * keys, each nullable (ConfigSchemaGenerator). On a config object it
   * changes nothing: commit() sets the keys prepared and keeps every other
   * key the object holds (ConfigObjectStorage::commit()). It holds the
   * rule for a storage that writes the object whole, and for a member its
   * access omitted, which a write does not name either; StorageAdapter
   * restores one lower down by its stored path (storedPath()). Lower down
   * the schema is core's own-key form, which holds the chosen variant's
   * keys and the secrets the pipeline keeps for the others
   * (Variants::keptSecrets()), so a variant not chosen keeps no other key
   * there, as variants under a key keep none; and inside a collection an
   * item's position may hold another item.
   *
   * @param array $values
   *   What is being written, after toStorage().
   * @param array $stored
   *   What the storage holds, before fromStorage().
   *
   * @return array
   *   The values, each such key kept.
   */
  public function keepOnWrite(array $values, array $stored): array {
    foreach ($this->marks as $mark) {
      if ($mark['steps'] !== []) {
        continue;
      }
      $own = array_flip([...$mark['own'], $mark['key']]);
      foreach (array_diff_key($stored, $own, $values) as $key => $value) {
        $values[$key] = $value;
      }
    }
    return $values;
  }

  /**
   * Gives where a key asked for at a path is stored.
   *
   * Each flat key's step is dropped from the path, since what the key
   * holds is stored at its level: `pitches.0.gear.straps` is stored at
   * `pitches.0.straps`. A path through no flat key is its own.
   *
   * The innermost flat key first, as toStorage() lays them out, so a flat
   * key inside a variant stored flat is dropped before the outer one moves
   * the path. Which variant a path goes into is not in the path: a flat key
   * is matched by its name and the keys of the steps to it, once however
   * many variants hold it. A path to the flat key itself is its own.
   *
   * @param string $path
   *   The key's dotted path as asked for, a collection's item by its
   *   delta.
   *
   * @return string
   *   Its dotted path as stored.
   */
  public function storedPath(string $path): string {
    $parents = explode('.', $path);
    $dropped = [];
    foreach (array_reverse($this->marks) as $mark) {
      $at = 0;
      foreach ($mark['steps'] as $step) {
        if (($parents[$at] ?? NULL) !== $step['key']) {
          continue 2;
        }
        // A collection's step is its key and then an item's delta.
        $at += $step['each'] ? 2 : 1;
      }
      if (($parents[$at] ?? NULL) === $mark['key'] && isset($parents[$at + 1]) && !isset($dropped[$at])) {
        array_splice($parents, $at, 1);
        $dropped[$at] = TRUE;
      }
    }
    return implode('.', $parents);
  }

  /**
   * Lists the flat keys of one map, and walks the maps it holds.
   *
   * @param \Drupal\surface\DefinitionMap $map
   *   The keys of one level.
   * @param list<array{key: string, each: bool, variants: \Drupal\surface\Variants|null, variant: string|null}> $steps
   *   The steps from the top to the level.
   * @param string $path
   *   The level's dotted path and a dot, as storedSchema() names it; empty
   *   at the top.
   * @param string[] $skip
   *   Top-level keys left alone.
   * @param array $marks
   *   The flat keys found, added to.
   * @param array<string, array> $schema
   *   Their stored schema, added to.
   */
  protected static function walk(DefinitionMap $map, array $steps, string $path, array $skip, array &$marks, array &$schema): void {
    $names = $map->names();
    foreach ($map->entries() as $name => $entry) {
      $name = (string) $name;
      if ($steps === [] && in_array($name, $skip, TRUE)) {
        continue;
      }
      $at = $path . $name;
      if ($entry->list !== NULL) {
        static::walk($entry->list->child()->getDefinitions(), [...$steps, static::step($name, each: TRUE)], $at . '.', [], $marks, $schema);
        continue;
      }
      if ($entry->attachment !== NULL) {
        $each = DefinitionMetadata::eachOf($entry->definition);
        if ($each === NULL) {
          static::walk($entry->attachment->child->getDefinitions(), [...$steps, static::step($name)], $at . '.', [], $marks, $schema);
        }
        elseif (is_string($each)) {
          // A structure per thing the site has: every entry the same,
          // named `*` in the schema, which entries there are being the
          // site's.
          foreach ($entry->attachment->child->getDefinitions()->entries() as $id => $one) {
            if ($one->attachment !== NULL) {
              $into = [...$steps, static::step($name), static::step((string) $id)];
              static::walk($one->attachment->child->getDefinitions(), $into, $at . '.*.', [], $marks, $schema);
            }
          }
        }
        continue;
      }
      if ($entry->variants !== NULL) {
        if ($entry->variants->storedFlat()) {
          $marks[] = [
            'steps' => $steps,
            'key' => $name,
            'own' => array_values(array_diff($names, [$name])),
            'variants' => $entry->variants,
          ];
          $schema[$at] = StoredSchemaInterface::SPREAD;
        }
        foreach ($entry->variants->variants as $id => $variant) {
          // One the site lists is walked once a door settled it.
          $child = $variant->settledChild();
          if ($child !== NULL) {
            $into = [...$steps, static::step($name, variants: $entry->variants, variant: (string) $id)];
            static::walk($child->getDefinitions(), $into, $at . '.', [], $marks, $schema);
          }
        }
        continue;
      }
      foreach ($entry->mounted as $module => $mounted) {
        static::walk($mounted->child->getDefinitions(), [...$steps, static::step($name), static::step((string) $module)], $at . '.' . $module . '.', [], $marks, $schema);
      }
    }
  }

  /**
   * Builds one step from a level to a level it holds.
   *
   * @param string $key
   *   The key the inner level is at.
   * @param bool $each
   *   TRUE for a collection, whose every item is the inner level.
   * @param \Drupal\surface\Variants|null $variants
   *   The variants at the key, for a step into one of them.
   * @param string|null $variant
   *   The variant the step goes into: only where its deciding key chose it.
   *
   * @return array{key: string, each: bool, variants: \Drupal\surface\Variants|null, variant: string|null}
   *   The step.
   */
  protected static function step(string $key, bool $each = FALSE, ?Variants $variants = NULL, ?string $variant = NULL): array {
    return ['key' => $key, 'each' => $each, 'variants' => $variants, 'variant' => $variant];
  }

  /**
   * Applies a change at the level some steps lead to.
   *
   * @param array $values
   *   The values of the level the steps start from.
   * @param list<array{key: string, each: bool, variants: \Drupal\surface\Variants|null, variant: string|null}> $steps
   *   The steps.
   * @param \Closure $change
   *   What to do with the level, handed its values and answering them.
   *
   * @return array
   *   The values, the level changed wherever the steps reach one.
   */
  protected static function atLevel(array $values, array $steps, \Closure $change): array {
    if ($steps === []) {
      return $change($values);
    }
    $step = array_shift($steps);
    $key = $step['key'];
    if (!is_array($values[$key] ?? NULL)) {
      return $values;
    }
    if ($step['variants'] !== NULL && $step['variants']->chosen($values[$step['variants']->by] ?? NULL) !== $step['variant']) {
      // Another variant's keys, or none chosen: nothing of this one here.
      return $values;
    }
    if ($step['each']) {
      foreach ($values[$key] as $delta => $item) {
        if (is_array($item)) {
          $values[$key][$delta] = static::atLevel($item, $steps, $change);
        }
      }
      return $values;
    }
    $values[$key] = static::atLevel($values[$key], $steps, $change);
    return $values;
  }

  /**
   * Lays the map under a flat key into its level, where the key stands.
   *
   * @param array $level
   *   The level's values, as asked for.
   * @param string $key
   *   The flat key.
   *
   * @return array
   *   The level as stored: the key gone, its keys in its place.
   */
  protected static function spread(array $level, string $key): array {
    if (!array_key_exists($key, $level)) {
      return $level;
    }
    $chosen = is_array($level[$key]) ? $level[$key] : [];
    $stored = [];
    foreach ($level as $name => $value) {
      if ((string) $name !== $key) {
        $stored[$name] = $value;
        continue;
      }
      // The build refuses a variant's key the owner declares at the
      // level, so none replaces the owner's.
      foreach ($chosen as $inner => $held) {
        if (!array_key_exists($inner, $level)) {
          $stored[$inner] = $held;
        }
      }
    }
    return $stored;
  }

  /**
   * Gathers a level's keys that are not the owner's under its flat key.
   *
   * @param array $level
   *   The level's values, as stored.
   * @param array{steps: array, key: string, own: string[], variants: \Drupal\surface\Variants} $mark
   *   The flat key.
   *
   * @return array
   *   The level as asked for: the owner's keys, and the chosen variant's
   *   under the key.
   */
  protected static function gather(array $level, array $mark): array {
    $gathered = array_diff_key($level, array_flip($mark['own']));
    if ($gathered === []) {
      return $level;
    }
    $variants = $mark['variants'];
    $chosen = $variants->chosen($level[$variants->by] ?? NULL);
    $child = $chosen === NULL ? NULL : $variants->variant($chosen)->settledChild();
    // What another variant wrote is left out, but for the secrets the
    // pipeline keeps beside the chosen keys.
    $held = $child === NULL ? $gathered : array_intersect_key($gathered, $child->getDefinitions()->toArray() + $variants->keptSecrets((string) $chosen, $gathered));
    // The key stands where the first key gathered under it stood.
    $asked = [];
    foreach ($level as $name => $value) {
      if (!array_key_exists($name, $gathered)) {
        $asked[$name] = $value;
      }
      elseif ($held !== [] && !array_key_exists($mark['key'], $asked)) {
        $asked[$mark['key']] = $held;
      }
    }
    return $asked;
  }

}
