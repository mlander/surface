<?php

declare(strict_types=1);

namespace Drupal\data_surface\SurfaceBuild;

/**
 * Every discovered surface, as a reader of the static layer sees it.
 *
 * The sketch's discovery document, kept small: for each surface its id,
 * class, identity keys, target and access class, its situations with
 * what each needs, whether it creates and the permission it asks for,
 * the alters that apply to it, and the variants that fill its slots.
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
   */
  public function __construct(
    protected readonly SurfaceRegistry $registry,
    protected readonly SurfacesInterface $surfaces,
  ) {}

  /**
   * Describes every discovered surface.
   *
   * @return array<string, array{id: string, class: class-string, module: string, identity: string[], target: class-string|null, access: class-string|null, situations: list<array{id: string, label: string, provider: string, module: string, parameters: string[], creates: bool|null, permission: string|null}>, alters: list<array{class: class-string, module: string, situations: string[]}>, variants: array<string, array<string, class-string>>}>
   *   The surfaces, keyed and sorted by id. A situation's `creates` is
   *   NULL when it needs a subject to say. Situations are a list in
   *   discovery order, so two providers of one id are both listed, as
   *   they are both refused when the surface is built.
   */
  public function describe(): array {
    $catalogue = [];
    foreach ($this->registry->getDefinitions() as $definition) {
      $catalogue[$definition->id] = [
        'id' => $definition->id,
        'class' => $definition->class,
        'module' => $definition->module,
        'identity' => $definition->identity,
        'target' => $definition->target,
        'access' => $definition->access,
        'situations' => array_map(fn (SituationDefinition $situation): array => [
          'id' => $situation->id,
          'label' => (string) $situation->label,
          'provider' => $situation->class . '::' . $situation->method . '()',
          'module' => $situation->module,
          'parameters' => array_map(static fn (SituationParameter $parameter): string => $parameter->describe(), $situation->parameters),
          'creates' => $this->creates($definition, $situation),
          'permission' => $situation->permission,
        ], $definition->situations),
        'alters' => array_map(static fn (AlterDefinition $alter): array => [
          'class' => $alter->class,
          'module' => $alter->module,
          'situations' => $alter->situations,
        ], $definition->alters),
        'variants' => $definition->variants,
      ];
    }
    ksort($catalogue);
    return $catalogue;
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
    // phpcs:ignore Drupal.Files.LineLength.TooLong
    // SKETCH GAP: the sketch puts creates on the context a situation returns, not on #[Situation], so a catalogue can say it only for a situation that needs nothing; one that needs a subject is listed as not knowing until it has one.
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
