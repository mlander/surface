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
 * The surface's own cacheability is not per key and stays on the
 * surface.
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
   *   Who introduced the key: the owner for everything the surface
   *   declares itself, and a module name for a mounted key.
   * @param bool $locked
   *   Whether the key's value is fixed to what storage holds, or to the
   *   declared default when storage holds nothing.
   * @param string[] $dependencies
   *   The sibling keys this key's refinement reads, in declaration
   *   order. Empty for a key that never refines. A key an alter mounted
   *   is named by its dotted path, `third_party_settings.<module>.<key>`.
   * @param array<string, array<\Drupal\data_surface\DataSurfaceRefinerInterface>> $refiners
   *   Refiner chains keyed by contributor, the owner's own under
   *   DataSurfaceInterface::OWNER. Always empty on an output's entry:
   *   outputs are never refined.
   * @param array<string, array> $contributions
   *   The values contributed to this key at build time, keyed by the
   *   provider that added them. What is not here is the owner's.
   * @param \Drupal\data_surface\SurfaceAttachment|null $attachment
   *   The child surface fixed at this key, when the key is a subsurface:
   *   its definition is then the child's definitions as a map.
   * @param \Drupal\data_surface\SurfaceSlot|null $slot
   *   The variant table, when the key is a slot whose shape a sibling
   *   chooses: its definition is then the `any` placeholder until the
   *   sibling holds a value, and that variant's map afterwards.
   * @param array<string, string[]> $paths
   *   For a key refined one property at a time — the mount, one property
   *   per key an alter refines there — what each property refines
   *   against, keyed by its dotted path below this key. $dependencies is
   *   their union, which is what gates the key; these are what a form
   *   rebuilds by.
   */
  public function __construct(
    public readonly string $name,
    public readonly DataDefinitionInterface $definition,
    public readonly string $contributor = DataSurfaceInterface::OWNER,
    public readonly bool $locked = FALSE,
    public readonly array $dependencies = [],
    public readonly array $refiners = [],
    public readonly array $contributions = [],
    public readonly ?SurfaceAttachment $attachment = NULL,
    public readonly ?SurfaceSlot $slot = NULL,
    public readonly array $paths = [],
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
      $this->attachment,
      $this->slot,
      $this->paths,
    );
  }

  /**
   * Gets the child surface answering for this key's value, if any.
   *
   * An attached child always; a slot's only once the deciding key, read
   * from the given values, names a variant. Everything that hands a
   * nested value to the surface describing it — accept, validate,
   * refine, the form — asks this one question.
   *
   * @param array $values
   *   The values of the surface this key belongs to, which is where a
   *   slot's deciding key is read.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface|null
   *   The child, or NULL for a plain key and for an unresolved slot.
   */
  public function childFor(array $values): ?DataSurfaceInterface {
    if ($this->attachment !== NULL) {
      return $this->attachment->child;
    }
    if ($this->slot === NULL) {
      return NULL;
    }
    $chosen = $this->slot->chosen($values[$this->slot->by] ?? NULL);
    return $chosen === NULL ? NULL : $this->slot->variant($chosen)->child;
  }

  /**
   * Returns whether this key holds a subsurface: attached or a slot.
   *
   * @return bool
   *   TRUE for an attached child or a slot.
   */
  public function isNested(): bool {
    return $this->attachment !== NULL || $this->slot !== NULL;
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
