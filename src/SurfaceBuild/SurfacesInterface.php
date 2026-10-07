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
 * What api/HOW-IT-FITS.md in the sketch calls the framework. It runs the
 * shape methods over the engine's builder, applies the discovered
 * alters, applies the context, binds the #[RefinesInput] methods as the
 * engine's refiners, and seals through the engine's factory — so the
 * build event still fires and a subscriber written in the old spelling
 * extends a surface written in the new one. That is how the two
 * spellings coexist until step 5 of the rework deletes the old.
 *
 * The answer is the engine's own DataSurfaceInterface, so the pipeline,
 * the generated form, the widgets and the tool bridge read a surface
 * built here exactly as they read any other.
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
   * @param class-string|null $host_class
   *   The class the build event names as the host, so a subscriber that
   *   matches a host by class keeps matching it. NULL names the surface
   *   class.
   * @param string|null $host_id
   *   The namespaced host id the build event carries, `<host type>:<id>`.
   *   NULL means `surface:<surface id>`.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The sealed surface.
   *
   * @throws \InvalidArgumentException
   *   When no such surface was discovered.
   * @throws \LogicException
   *   When the surface fails a seal-time check, naming the offender.
   */
  public function build(string $surface, SurfaceContext $context, ?string $host_class = NULL, ?string $host_id = NULL): DataSurfaceInterface;

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
   * Gets the pipeline target for a surface's #[Surface(target:)].
   *
   * @param string $surface
   *   The surface class, or its #[Surface] id.
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   The context the target loads and commits by.
   *
   * @return \Drupal\data_surface\Pipeline\DataSurfaceTargetInterface
   *   The target, for the pipeline's submit().
   *
   * @throws \LogicException
   *   When the surface names no target: its host supplies one.
   */
  public function target(string $surface, SurfaceContext $context): DataSurfaceTargetInterface;

}
