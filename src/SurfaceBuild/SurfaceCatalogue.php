<?php

declare(strict_types=1);

namespace Drupal\data_surface\SurfaceBuild;

/**
 * Every discovered surface, as a reader of the static layer sees it.
 *
 * The pattern's discovery document, kept small: for each surface its id,
 * class, identity keys, target and access class, the plugins whose
 * configuration it is, its situations with what each needs, whether it
 * creates, the permission it asks for and whether it can be asked on its
 * own, the alters that apply to it, and the variants that fill its
 * slots.
 * Nothing here builds a surface; everything is read from what discovery
 * found, which is the point of the attributes being static.
 *
 * One thing on a situation is not static: whether it creates is on the
 * context it returns. A situation that needs nothing is asked; one that
 * needs a subject is not, and says so.
 *
 * @see \Drupal\data_surface\SurfaceBuild\SurfaceRegistry
 */
final class SurfaceCatalogue {

  /**
   * Constructs a SurfaceCatalogue.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceRegistry $registry
   *   What discovery found.
   * @param \Drupal\data_surface\SurfaceBuild\SurfacesInterface $surfaces
   *   The build step, which invokes a situation that needs nothing.
   * @param \Drupal\data_surface\SurfaceBuild\SurfacePlugins $plugins
   *   Which plugins name which surface with #[UsesSurface].
   * @param \Drupal\data_surface\SurfaceBuild\DerivedVariants $derivedVariants
   *   What fills an open slot for the values no declared variant fills.
   */
  public function __construct(
    protected readonly SurfaceRegistry $registry,
    protected readonly SurfacesInterface $surfaces,
    protected readonly SurfacePlugins $plugins,
    protected readonly DerivedVariants $derivedVariants,
  ) {}

  /**
   * Describes every discovered surface.
   *
   * @return array<string, array{id: string, class: class-string, module: string, identity: string[], target: class-string|null, access: class-string|null, plugins: list<string>, situations: list<array{id: string, label: string, provider: string, module: string, parameters: string[], creates: bool|null, permission: string|null, unresolvable: string[], standalone: bool}>, alters: list<array{class: class-string, module: string, situations: string[]}>, variants: array<string, array<string, class-string>>, derived: array<string, string>}>
   *   The surfaces, keyed and sorted by id. `plugins` lists the plugins
   *   whose configuration the surface is, as `<host type>:<plugin id>`.
   *   A situation's `creates` is NULL when it needs a subject to say;
   *   `unresolvable` lists its permission's placeholders no parameter can
   *   supply; `standalone` says whether it can be asked on its own, as a
   *   route or a tool. Situations are a list in discovery order, so two
   *   providers of one id are both listed, as they are both refused when
   *   the surface is built. `variants` are the declared ones, each
   *   value's #[SurfaceVariant] class keyed by slot; `derived` says, per
   *   slot, where the variants of every other value come from.
   */
  public function describe(): array {
    $catalogue = [];
    foreach ($this->registry->getDefinitions() as $definition) {
      $plugins = $this->plugins->usedBy($definition->class);
      $catalogue[$definition->id] = [
        'id' => $definition->id,
        'class' => $definition->class,
        'module' => $definition->module,
        'identity' => $definition->identity,
        'target' => $definition->target,
        'access' => $definition->access,
        'plugins' => $plugins,
        'situations' => array_map(fn (SituationDefinition $situation): array => [
          'id' => $situation->id,
          'label' => (string) $situation->label,
          'provider' => $situation->class . '::' . $situation->method . '()',
          'module' => $situation->module,
          'parameters' => array_map(static fn (SituationParameter $parameter): string => $parameter->describe(), $situation->parameters),
          'creates' => $this->creates($definition, $situation),
          'permission' => $situation->permission,
          'unresolvable' => $situation->unresolvablePlaceholders(),
          'standalone' => static::standalone($definition, $situation, $plugins),
        ], $definition->situations),
        'alters' => array_map(static fn (AlterDefinition $alter): array => [
          'class' => $alter->class,
          'module' => $alter->module,
          'situations' => $alter->situations,
        ], $definition->alters),
        'variants' => $definition->variants,
        'derived' => $this->derivedVariants->sources($definition->class),
      ];
    }
    ksort($catalogue);
    return $catalogue;
  }

  /**
   * Answers whether a situation can be asked on its own.
   *
   * The rule a generated route or tool follows: its surface names a
   * target of its own, no plugin's configuration is the surface, and
   * every placeholder in its permission can be supplied by one of its
   * parameters. A plugin's surface is configured through the plugin's
   * host, which holds the instance and supplies the target; a situation
   * whose permission could never be named could never be allowed.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $surface
   *   The surface.
   * @param \Drupal\data_surface\SurfaceBuild\SituationDefinition $situation
   *   The situation.
   * @param list<string> $plugins
   *   The plugins whose configuration the surface is.
   *
   * @return bool
   *   TRUE when it can be asked on its own.
   */
  public static function standalone(SurfaceDefinition $surface, SituationDefinition $situation, array $plugins): bool {
    return $surface->target !== NULL && $plugins === [] && $situation->unresolvablePlaceholders() === [];
  }

  /**
   * Answers whether a situation creates, when it can be asked unaided.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $surface
   *   The surface.
   * @param \Drupal\data_surface\SurfaceBuild\SituationDefinition $situation
   *   The situation.
   *
   * @return bool|null
   *   Whether its context creates, or NULL when it needs a subject first,
   *   or cannot be asked (a clash, a context for another operation),
   *   which building the surface reports.
   */
  protected function creates(SurfaceDefinition $surface, SituationDefinition $situation): ?bool {
    if (!$situation->needsNothing()) {
      return NULL;
    }
    try {
      return $this->surfaces->situation($surface->class, $situation->id)->creates;
    }
    catch (\LogicException | \InvalidArgumentException) {
      return NULL;
    }
  }

}
