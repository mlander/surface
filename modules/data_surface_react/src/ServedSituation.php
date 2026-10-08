<?php

declare(strict_types=1);

namespace Drupal\data_surface_react;

use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\SurfaceBuild\SituationDefinition;
use Drupal\data_surface\SurfaceBuild\SurfaceDefinition;

/**
 * One situation of one surface, as a request named it, built and bound.
 *
 * What the page and the three endpoints all start from: the surface and
 * the situation the path named, the context the situation built from
 * the parameters the request carried, the surface built in that context
 * and the target composed for it. Asked afresh on every request, as the
 * situation form asks afresh at every stage.
 */
final class ServedSituation {

  /**
   * Constructs a ServedSituation.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $definition
   *   What discovery found about the surface.
   * @param \Drupal\data_surface\SurfaceBuild\SituationDefinition $situation
   *   The situation.
   * @param array $parameters
   *   The situation's parameters as the request gave them, by name: raw
   *   ids and scalars, never an upcast entity.
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   The context the situation built from them.
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface, built in that context.
   * @param \Drupal\data_surface\Pipeline\DataSurfaceTargetInterface|null $target
   *   The target composed for it, or NULL when the surface names none.
   */
  public function __construct(
    public readonly SurfaceDefinition $definition,
    public readonly SituationDefinition $situation,
    public readonly array $parameters,
    public readonly SurfaceContext $context,
    public readonly DataSurfaceInterface $surface,
    public readonly ?DataSurfaceTargetInterface $target,
  ) {}

}
