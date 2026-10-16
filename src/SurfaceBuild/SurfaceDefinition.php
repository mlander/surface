<?php

declare(strict_types=1);

namespace Drupal\data_surface\SurfaceBuild;

use Drupal\data_surface\Surface\HasOutputsInterface;
use Drupal\data_surface\Surface\SurfaceCallable;

/**
 * One surface and everything discovery attached to it.
 *
 * Most surfaces are a class: one carrying #[Surface] in a module's
 * src/Surface, or a plugin that is its own surface, whose id its plugin
 * definition gives unless it carries #[Surface] as well. A derived
 * surface has no class: a deriver made it from facts known only at run
 * time, so it carries the methods that fill its shape instead, and the
 * base id it was derived under. Everything else is the same for both,
 * and every caller asks for either by name() (its class, or a derived
 * surface's id), which is what the registry is keyed by.
 *
 * Situations are kept as a list rather than by id, so that two
 * providers of one id survive discovery and are refused, naming both,
 * when the surface is asked for: discovery itself refuses nothing that
 * belongs to one surface.
 *
 * Decision: see docs/decisions.md#a-derived-surface-has-no-class.
 *
 * @internal
 */
final class SurfaceDefinition {

  /**
   * Constructs a SurfaceDefinition.
   *
   * @param class-string|null $class
   *   The surface class, or NULL for a derived surface.
   * @param string $id
   *   The machine name from #[Surface], `<host type>:<plugin id>` for a
   *   plugin that is its own surface and carries none, or
   *   `<base>:<derivative>` for a derived surface.
   * @param string $module
   *   The module it is in, or its deriver is in.
   * @param string[] $identity
   *   The identity keys.
   * @param class-string|null $target
   *   The target class, or NULL when the host or a parent stores it.
   * @param class-string|null $access
   *   The access class, or NULL.
   * @param \Drupal\data_surface\SurfaceBuild\RefinerDefinition[] $refiners
   *   The surface's own #[RefinesInput] methods.
   * @param \Drupal\data_surface\SurfaceBuild\SituationDefinition[] $situations
   *   Every situation of this surface, from every provider.
   * @param \Drupal\data_surface\SurfaceBuild\AlterDefinition[] $alters
   *   Every alter of this surface, in discovery order.
   * @param array<string, array<string, class-string>> $variants
   *   Surfaces marked #[SurfaceVariant] for a slot here, keyed by slot
   *   key, then by the deciding value.
   * @param \Drupal\data_surface\Surface\SurfaceCallable|null $inputs
   *   For a derived surface, what fills its input shape.
   * @param \Drupal\data_surface\Surface\SurfaceCallable|null $outputs
   *   For a derived surface, what fills its output shape, if it has one.
   * @param string|null $base
   *   For a derived surface, the base id it was derived under.
   * @param class-string|null $deriver
   *   For a derived surface, the deriver that made it.
   * @param string|\Stringable|null $label
   *   For a derived surface, what it is.
   * @param string|\Stringable|null $description
   *   For a derived surface, what it covers and leaves out.
   * @param array<string, string|\Stringable> $notes
   *   For a derived surface, what its derivation could not carry, keyed
   *   by what it is about.
   * @param string[] $cacheTags
   *   For a derived surface, the tags of the site state it was derived
   *   from, which discovery's cache entry carries.
   */
  public function __construct(
    public readonly ?string $class,
    public readonly string $id,
    public readonly string $module,
    public readonly array $identity,
    public readonly ?string $target,
    public readonly ?string $access,
    public readonly array $refiners,
    public readonly array $situations = [],
    public readonly array $alters = [],
    public readonly array $variants = [],
    public readonly ?SurfaceCallable $inputs = NULL,
    public readonly ?SurfaceCallable $outputs = NULL,
    public readonly ?string $base = NULL,
    public readonly ?string $deriver = NULL,
    public readonly string|\Stringable|null $label = NULL,
    public readonly string|\Stringable|null $description = NULL,
    public readonly array $notes = [],
    public readonly array $cacheTags = [],
  ) {}

  /**
   * Gets the name the surface is asked for by.
   *
   * @return string
   *   Its class, or a derived surface's id: what the registry is keyed
   *   by, and what every caller hands the build step.
   */
  public function name(): string {
    return $this->class ?? $this->id;
  }

  /**
   * Answers whether a deriver made the surface.
   *
   * @return bool
   *   TRUE for a derived surface, which has no class.
   */
  public function isDerived(): bool {
    return $this->class === NULL;
  }

  /**
   * Answers whether the surface declares outputs.
   *
   * @return bool
   *   TRUE for a class implementing HasOutputsInterface, or a derived
   *   surface whose deriver names an output shape.
   */
  public function hasOutputs(): bool {
    return $this->class === NULL ? $this->outputs !== NULL : is_subclass_of($this->class, HasOutputsInterface::class);
  }

  /**
   * Returns a copy with discovery's other findings attached.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SituationDefinition[] $situations
   *   The situations.
   * @param \Drupal\data_surface\SurfaceBuild\AlterDefinition[] $alters
   *   The alters.
   * @param array<string, array<string, class-string>> $variants
   *   The variants.
   *
   * @return self
   *   The completed definition.
   */
  public function with(array $situations, array $alters, array $variants): self {
    return new self(
      class: $this->class,
      id: $this->id,
      module: $this->module,
      identity: $this->identity,
      target: $this->target,
      access: $this->access,
      refiners: $this->refiners,
      situations: $situations,
      alters: $alters,
      variants: $variants,
      inputs: $this->inputs,
      outputs: $this->outputs,
      base: $this->base,
      deriver: $this->deriver,
      label: $this->label,
      description: $this->description,
      notes: $this->notes,
      cacheTags: $this->cacheTags,
    );
  }

}
