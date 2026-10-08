<?php

declare(strict_types=1);

namespace Drupal\data_surface\SurfaceBuild;

/**
 * One surface class and everything discovery attached to it.
 *
 * The class carries #[Surface] in a module's src/Surface, or is a plugin
 * that is its own surface, whose id its plugin definition gives unless it
 * carries #[Surface] as well.
 *
 * Situations are kept as a list rather than by id, so that two
 * providers of one id survive discovery and are refused, naming both,
 * when the surface is asked for: discovery itself refuses nothing that
 * belongs to one surface.
 *
 * @internal
 */
final class SurfaceDefinition {

  /**
   * Constructs a SurfaceDefinition.
   *
   * @param class-string $class
   *   The surface class.
   * @param string $id
   *   The machine name from #[Surface], or `<host type>:<plugin id>` for
   *   a plugin that is its own surface and carries none.
   * @param string $module
   *   The module it is in.
   * @param string[] $identity
   *   The identity keys.
   * @param class-string|null $target
   *   The target class, or NULL when the host supplies one.
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
   */
  public function __construct(
    public readonly string $class,
    public readonly string $id,
    public readonly string $module,
    public readonly array $identity,
    public readonly ?string $target,
    public readonly ?string $access,
    public readonly array $refiners,
    public readonly array $situations = [],
    public readonly array $alters = [],
    public readonly array $variants = [],
  ) {}

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
    return new self($this->class, $this->id, $this->module, $this->identity, $this->target, $this->access, $this->refiners, $situations, $alters, $variants);
  }

}
