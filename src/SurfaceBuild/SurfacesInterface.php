<?php

declare(strict_types=1);

namespace Drupal\data_surface\SurfaceBuild;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;
use Drupal\data_surface\Surface\SurfaceContext;

/**
 * The build step: a surface class and a context in, a sealed surface out.
 *
 * What docs/how-it-fits.md calls the framework. It runs the
 * shape methods over the engine's builder, applies the discovered
 * alters, applies the context, binds the #[RefinesInput] methods as the
 * engine's refiners, builds each subsurface the same way in its own
 * frame, and seals. It is the only way a surface is built.
 *
 * The answer is the engine's DataSurfaceInterface, which the pipeline,
 * the generated form, the widgets and the tool bridge all read.
 *
 * @see \Drupal\data_surface\SurfaceBuild\Surfaces
 *   For the order of the steps and what each refuses.
 */
interface SurfacesInterface {

  /**
   * Builds a surface in a context.
   *
   * @param string $surface
   *   The surface class, or its #[Surface] id.
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   Where it is being asked for. A generic caller passes an empty one.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The sealed surface.
   *
   * @throws \InvalidArgumentException
   *   When no such surface was discovered.
   * @throws \LogicException
   *   When the surface fails a seal-time check, naming the offender.
   */
  public function build(string $surface, SurfaceContext $context): DataSurfaceInterface;

  /**
   * Builds the context one situation describes.
   *
   * @param string $surface
   *   The surface class, or its #[Surface] id.
   * @param string $situation
   *   The situation id.
   * @param array $arguments
   *   The situation method's arguments, positional or by parameter name.
   *
   * @return \Drupal\data_surface\Surface\SurfaceContext
   *   The context, whose operation is the situation id.
   *
   * @throws \InvalidArgumentException
   *   When the surface has no such situation.
   * @throws \LogicException
   *   When two providers declare the situation, or it returns something
   *   other than a context for its own id.
   */
  public function situation(string $surface, string $situation, array $arguments = []): SurfaceContext;

  /**
   * Builds a surface in one of its situations.
   *
   * @param string $surface
   *   The surface class, or its #[Surface] id.
   * @param string $situation
   *   The situation id.
   * @param array $arguments
   *   The situation method's arguments, positional or by parameter name.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The sealed surface.
   */
  public function buildSituation(string $surface, string $situation, array $arguments = []): DataSurfaceInterface;

  /**
   * Answers whether an account may submit a surface in a context.
   *
   * The situation's permission first, its `%key` placeholders filled from
   * the identity the context knows; then, only if that allows, the
   * surface's access class. A context whose operation is no declared
   * situation (a plugin host's `configure`, a generic tool's empty
   * context) has no permission to check, so the access class is the
   * whole answer, and a surface with neither answers neutral.
   *
   * Then its subsurfaces: each child the context resolves — an attached
   * one, and a slot's variant when its deciding key is known — whose
   * class names an access class of its own is asked in the context the
   * parent's hands it, and may refuse. A child never allows on its
   * parent's behalf.
   *
   * @param string $surface
   *   The surface class, or its #[Surface] id.
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   Where it is being asked for.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account, or NULL for the current user.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access answer.
   */
  public function access(string $surface, SurfaceContext $context, ?AccountInterface $account = NULL): AccessResultInterface;

  /**
   * Reads the defaults a surface's own shape declares, and nothing else.
   *
   * For the static half of a plugin host's protocol, a formatter's
   * defaultSettings() or a field type's defaultFieldSettings(), which is
   * asked of a class with no instance and no context. Only the owner's
   * defineInputs() runs: no alter, no context, no refiner, so what an
   * alter mounts is not here, and a subsurface
   * key holds an empty map.
   *
   * @param string $surface
   *   The surface class, or its #[Surface] id.
   *
   * @return array
   *   The declared defaults, keyed by surface key.
   */
  public function defaults(string $surface): array;

  /**
   * Gets the pipeline target for a surface's #[Surface(target:)].
   *
   * @param string $surface
   *   The surface class, or its #[Surface] id.
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   The context the target loads and commits by.
   * @param \Drupal\data_surface\DataSurfaceInterface|null $built
   *   The surface already built in that context, which saves building
   *   it again to find its subsurfaces; NULL builds it.
   *
   * @return \Drupal\data_surface\Pipeline\DataSurfaceTargetInterface
   *   The target, for the pipeline's submit(). Composed along the
   *   subsurface tree: a child whose class names a target of its own
   *   has its values routed there, in the context its parent's hands
   *   it; a child without one is stored by its parent under its key.
   *
   * @throws \LogicException
   *   When the surface names no target: its host supplies one.
   */
  public function target(string $surface, SurfaceContext $context, ?DataSurfaceInterface $built = NULL): DataSurfaceTargetInterface;

}
