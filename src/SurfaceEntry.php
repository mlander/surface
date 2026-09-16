<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Core\TypedData\DataDefinitionInterface;

/**
 * One key of a surface, and everything the surface knows about it.
 *
 * Before this existed the surface held five arrays keyed by the same
 * names — the definitions, the refinement map, the refiner chains, the
 * locked keys and the contributed values — and every one of them could
 * be out of step with the others. An entry is the single answer to
 * "what does this surface say about this key", validated once when the
 * map around it is built.
 *
 * The refiner chains and the contributed values live here rather than
 * beside the map because refinement needs all three of them for one key
 * at a time: the advertised definition to clone, the contributions to
 * divide its option space by, and the chains to run over each slice.
 * Policy filters and the surface's own cacheability are not per key and
 * stay on the surface.
 *
 * @see \Drupal\data_surface\DefinitionMap
 *   The ordered collection of entries a surface is sealed with.
 */
final class SurfaceEntry {

  /**
   * Constructs a SurfaceEntry.
   *
   * @param string $name
   *   The surface key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   What the key accepts.
   * @param string $contributor
   *   Who introduced the key: the owner for everything a provider
   *   declares itself, and a module name for a mounted key.
   * @param bool $locked
   *   Whether the key's value is fixed to what storage holds, or to the
   *   declared default when storage holds nothing.
   * @param string[] $dependencies
   *   The sibling keys this key's refinement reads, in declaration
   *   order. Empty for a key that never refines.
   * @param array<string, array<\Drupal\data_surface\DataSurfaceRefinerInterface|\Drupal\data_surface\DataSurfaceOutputRefinerInterface>> $refiners
   *   Refiner chains keyed by contributor, the owner's own under
   *   DataSurfaceInterface::OWNER. Input refiners on an entry of a
   *   surface's definitions, output refiners on an entry of its
   *   outputs.
   * @param array<string, array> $contributions
   *   The values contributed to this key at build time, keyed by the
   *   provider that added them. What is not here is the owner's.
   */
  public function __construct(
    public readonly string $name,
    public readonly DataDefinitionInterface $definition,
    public readonly string $contributor = DataSurfaceInterface::OWNER,
    public readonly bool $locked = FALSE,
    public readonly array $dependencies = [],
    public readonly array $refiners = [],
    public readonly array $contributions = [],
  ) {
  }

  /**
   * Returns the same entry with another definition.
   *
   * The one mutation refinement makes: everything else about a key —
   * who introduced it, whether it is locked, what it refines against,
   * whose values it carries — is settled at build time and survives
   * narrowing untouched.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The narrowed definition.
   *
   * @return static
   *   The new entry.
   */
  public function withDefinition(DataDefinitionInterface $definition): static {
    return new static(
      $this->name,
      $definition,
      $this->contributor,
      $this->locked,
      $this->dependencies,
      $this->refiners,
      $this->contributions,
    );
  }

  /**
   * Returns whether a provider had a hand in this key.
   *
   * Introducing the key, contributing values to it, or registering a
   * refiner for it all count: each of them is a reason the key would
   * mean something different if that provider went away.
   *
   * @param string $contributor
   *   The provider id, or DataSurfaceInterface::OWNER.
   *
   * @return bool
   *   TRUE when the provider touched this key.
   */
  public function touchedBy(string $contributor): bool {
    return $this->contributor === $contributor
      || isset($this->contributions[$contributor])
      || isset($this->refiners[$contributor]);
  }

}
