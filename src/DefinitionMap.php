<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Core\TypedData\DataDefinitionInterface;

/**
 * The ordered, immutable set of keys a surface advertises.
 *
 * One collection in place of the parallel arrays a surface used to hold.
 * Every key is a SurfaceEntry carrying its definition, who introduced
 * it, whether it is locked, what it refines against, whose values it
 * offers and whose refiners narrow it, so nothing can name a key one of
 * those arrays had never heard of.
 *
 * Order is load-bearing, not decoration. A generated form renders the
 * keys in the order they were declared, and the emitted tool schema is
 * compared against a committed document, so a map that reordered its
 * keys would move every field on every form and rewrite that document.
 * Insertion order is therefore preserved everywhere: iteration,
 * names(), and the entry a with() replaces keeps its place.
 *
 * Reading it is deliberately easy and writing it is impossible.
 * Iterating yields name => definition, which is the shape every call
 * site that used to read the definitions array expects, and array
 * access answers the same question; set and unset throw, because a
 * sealed surface has already been advertised.
 *
 * @see \Drupal\data_surface\SurfaceEntry
 * @see \Drupal\data_surface\DataSurfaceInterface::getDefinitions()
 *
 * @implements \IteratorAggregate<string, \Drupal\Core\TypedData\DataDefinitionInterface>
 * @implements \ArrayAccess<string, \Drupal\Core\TypedData\DataDefinitionInterface>
 */
final class DefinitionMap implements \IteratorAggregate, \ArrayAccess, \Countable {

  /**
   * The entries, keyed by surface key, in insertion order.
   *
   * Not readonly: with() clones the map and replaces one entry, and a
   * readonly property cannot be written even on a fresh clone. The
   * property is private and nothing else assigns to it, so the map is
   * immutable to everything outside this class.
   *
   * @var array<string, \Drupal\data_surface\SurfaceEntry>
   */
  private array $entries = [];

  /**
   * Constructs a DefinitionMap.
   *
   * This is where a surface's shape is checked, once: afterwards every
   * reader can assume a key is declared exactly once and that every
   * refinement edge names a key that exists.
   *
   * The refinement map is checked for cycles by
   * DataSurfaceBuilderInterface::seal(), which is the moment the map is
   * whole, and not again here.
   *
   * @param \Drupal\data_surface\SurfaceEntry[] $entries
   *   The entries, in the order the surface declares them.
   * @param string[]|null $dependency_names
   *   The names a refinement edge may point at, for a map whose edges
   *   leave it. NULL, the ordinary case, means an edge names a sibling
   *   in this same map. An output map passes the input map's names,
   *   because an output refines against the values the surface
   *   accepted and never against another output.
   *
   * @throws \InvalidArgumentException
   *   When two entries share a name, or a refinement edge names a key
   *   nothing declares.
   */
  public function __construct(array $entries, ?array $dependency_names = NULL) {
    foreach ($entries as $entry) {
      if (isset($this->entries[$entry->name])) {
        throw new \InvalidArgumentException(sprintf('The surface key "%s" is declared twice.', $entry->name));
      }
      $this->entries[$entry->name] = $entry;
    }
    $declared = $dependency_names === NULL
      ? $this->entries
      : array_fill_keys($dependency_names, TRUE);
    foreach ($this->entries as $name => $entry) {
      foreach ($entry->dependencies as $dependency) {
        if (!isset($declared[$dependency])) {
          throw new \InvalidArgumentException(sprintf('Refinement dependency "%s" of "%s" is not a surface definition.', $dependency, $name));
        }
      }
    }
  }

  /**
   * Builds a map from the plain arrays an author writes.
   *
   * The boundary the roadmap's rule names: contracts are objects,
   * payloads and authoring syntax are arrays. The attribute declares
   * definitions, a refinement map and a list of locked keys as plain
   * arrays, and this is where they become one collection, so nothing
   * about declaring a surface changes.
   *
   * @param array<string, \Drupal\Core\TypedData\DataDefinitionInterface> $definitions
   *   The definitions, keyed by surface key, in declaration order.
   * @param array<string, string[]> $refinements
   *   Refinement dependencies: target key => the sibling keys it
   *   refines against.
   * @param string[] $locked
   *   The keys whose value the surface fixes.
   * @param string $contributor
   *   Who introduced these keys.
   * @param array<string, array<string, array<\Drupal\data_surface\DataSurfaceRefinerInterface|\Drupal\data_surface\DataSurfaceOutputRefinerInterface>>> $refiners
   *   Refiner chains keyed by target key, then by contributor. Input
   *   refiners for a surface's definitions, output refiners for its
   *   outputs; one map type serves both, and each half only ever holds
   *   the kind its own builder put there.
   * @param array<string, array<string, array>> $contributions
   *   Contributed values keyed by surface key, then by provider.
   * @param string[]|null $dependency_names
   *   The names a refinement edge may point at, or NULL when an edge
   *   names a sibling of this same map.
   *
   * @return static
   *   The map.
   *
   * @throws \InvalidArgumentException
   *   When anything names a key the definitions do not declare.
   */
  public static function fromArrays(
    array $definitions,
    array $refinements = [],
    array $locked = [],
    string $contributor = DataSurfaceInterface::OWNER,
    array $refiners = [],
    array $contributions = [],
    ?array $dependency_names = NULL,
  ): static {
    foreach (array_merge(array_keys($refiners), array_keys($contributions), $locked) as $key) {
      if (!isset($definitions[$key])) {
        throw new \InvalidArgumentException(sprintf('"%s" is not a surface definition.', $key));
      }
    }
    foreach (array_keys($refinements) as $target) {
      if (!isset($definitions[$target])) {
        throw new \InvalidArgumentException(sprintf('Refinement target "%s" is not a surface definition.', $target));
      }
    }
    $entries = [];
    foreach ($definitions as $name => $definition) {
      $entries[] = new SurfaceEntry(
        name: (string) $name,
        definition: $definition,
        contributor: $contributor,
        locked: in_array($name, $locked, TRUE),
        dependencies: array_values(array_map('strval', $refinements[$name] ?? [])),
        refiners: $refiners[$name] ?? [],
        contributions: $contributions[$name] ?? [],
      );
    }
    return new static($entries, $dependency_names);
  }

  /**
   * Gets one definition.
   *
   * @param string $name
   *   The surface key.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The definition, or NULL when the map declares no such key.
   */
  public function get(string $name): ?DataDefinitionInterface {
    return $this->entries[$name]->definition ?? NULL;
  }

  /**
   * Gets everything the surface knows about one key.
   *
   * @param string $name
   *   The surface key.
   *
   * @return \Drupal\data_surface\SurfaceEntry|null
   *   The entry, or NULL when the map declares no such key.
   */
  public function entry(string $name): ?SurfaceEntry {
    return $this->entries[$name] ?? NULL;
  }

  /**
   * Gets every entry, keyed by surface key, in declaration order.
   *
   * @return array<string, \Drupal\data_surface\SurfaceEntry>
   *   The entries.
   */
  public function entries(): array {
    return $this->entries;
  }

  /**
   * Returns whether the map declares a key.
   *
   * @param string $name
   *   The surface key.
   *
   * @return bool
   *   TRUE when the key is declared.
   */
  public function has(string $name): bool {
    return isset($this->entries[$name]);
  }

  /**
   * Gets the surface keys, in declaration order.
   *
   * @return string[]
   *   The keys.
   */
  public function names(): array {
    return array_keys($this->entries);
  }

  /**
   * Returns whether a key's value is fixed by the surface.
   *
   * @param string $name
   *   The surface key.
   *
   * @return bool
   *   TRUE when the key is locked. A key the map does not declare is
   *   not locked, because it is not anything.
   */
  public function isLocked(string $name): bool {
    return $this->entries[$name]->locked ?? FALSE;
  }

  /**
   * Gets the sibling keys one key refines against.
   *
   * @param string $name
   *   The surface key.
   *
   * @return string[]
   *   The dependency names, empty for a key that never refines.
   */
  public function dependencies(string $name): array {
    return $this->entries[$name]->dependencies ?? [];
  }

  /**
   * Gets the refinement map: target key => the keys it refines against.
   *
   * @return array<string, string[]>
   *   The map, holding only the keys that refine at all.
   */
  public function refinements(): array {
    $refinements = [];
    foreach ($this->entries as $name => $entry) {
      if ($entry->dependencies !== []) {
        $refinements[$name] = $entry->dependencies;
      }
    }
    return $refinements;
  }

  /**
   * Gets the deduplicated names other keys refine against.
   *
   * These are the keys a generated form has to rebuild itself on: a
   * change to any of them may change what its dependents accept.
   *
   * @return string[]
   *   The dependency names.
   */
  public function refinementDependencies(): array {
    $names = [];
    foreach ($this->entries as $entry) {
      foreach ($entry->dependencies as $dependency) {
        $names[$dependency] = $dependency;
      }
    }
    return array_values($names);
  }

  /**
   * Gets the values contributed at build time, and by whom.
   *
   * What the owner declared is not here: the owner's contribution is
   * everything else. This is what refinement divides the option space
   * by and what a target reports as the providers a stored value
   * depends on.
   *
   * @return array<string, array<string, array>>
   *   Contributed values, keyed by surface key, then by provider.
   */
  public function contributions(): array {
    $contributions = [];
    foreach ($this->entries as $name => $entry) {
      if ($entry->contributions !== []) {
        $contributions[$name] = $entry->contributions;
      }
    }
    return $contributions;
  }

  /**
   * Gets the entries one provider had a hand in.
   *
   * @param string $contributor
   *   The provider id, or DataSurfaceInterface::OWNER.
   *
   * @return array<string, \Drupal\data_surface\SurfaceEntry>
   *   The entries that provider introduced, contributed values to, or
   *   registered a refiner for, keyed by surface key.
   */
  public function byContributor(string $contributor): array {
    return array_filter(
      $this->entries,
      static fn (SurfaceEntry $entry): bool => $entry->touchedBy($contributor),
    );
  }

  /**
   * Returns the map with one entry replaced, in the same position.
   *
   * Refinement's one writing move. Nothing is revalidated, because
   * nothing that was validated can have changed: the key was already
   * declared, and an entry carries the same name, the same dependency
   * edges and the same contributors whatever definition it holds.
   *
   * @param \Drupal\data_surface\SurfaceEntry $entry
   *   The replacement entry.
   *
   * @return static
   *   The new map.
   *
   * @throws \InvalidArgumentException
   *   When the map declares no key of that name. Adding a key is
   *   widening, and the build is over.
   */
  public function with(SurfaceEntry $entry): static {
    if (!isset($this->entries[$entry->name])) {
      throw new \InvalidArgumentException(sprintf('"%s" is not a surface definition.', $entry->name));
    }
    $map = clone $this;
    $map->entries[$entry->name] = $entry;
    return $map;
  }

  /**
   * Gets the definitions as a plain array, keyed by surface key.
   *
   * For the callers that hand the whole group to an array function —
   * array_intersect_key() against stored values, above all — where an
   * object would have to be unpacked anyway.
   *
   * @return array<string, \Drupal\Core\TypedData\DataDefinitionInterface>
   *   The definitions, in declaration order.
   */
  public function toArray(): array {
    return array_map(
      static fn (SurfaceEntry $entry): DataDefinitionInterface => $entry->definition,
      $this->entries,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getIterator(): \Traversable {
    foreach ($this->entries as $name => $entry) {
      yield $name => $entry->definition;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function count(): int {
    return count($this->entries);
  }

  /**
   * {@inheritdoc}
   */
  public function offsetExists(mixed $offset): bool {
    return isset($this->entries[$offset]);
  }

  /**
   * {@inheritdoc}
   */
  public function offsetGet(mixed $offset): ?DataDefinitionInterface {
    return $this->get((string) $offset);
  }

  /**
   * {@inheritdoc}
   *
   * @throws \LogicException
   *   Always: a sealed surface has been advertised and cannot change.
   */
  public function offsetSet(mixed $offset, mixed $value): void {
    throw new \LogicException('A definition map cannot be written to: the surface has been sealed and advertised, and widening is a build-time act.');
  }

  /**
   * {@inheritdoc}
   *
   * @throws \LogicException
   *   Always: a sealed surface has been advertised and cannot change.
   */
  public function offsetUnset(mixed $offset): void {
    throw new \LogicException('A definition map cannot be written to: the surface has been sealed and advertised, and removing a key would contradict what it advertised.');
  }

}
