<?php

declare(strict_types=1);

namespace Drupal\data_surface\SurfaceBuild;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;
use Drupal\data_surface\Pipeline\PreparedValues;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceTargetInterface;

/**
 * A new-spelling target, as the pipeline's target.
 *
 * The sketch's target has two verbs, load and commit, and is handed the
 * context; the pipeline's has three, load, prepare and commit, and is
 * handed the surface. The context is bound here, once, so the pipeline's
 * submit() path reaches the target unchanged: load narrows what the
 * target returns to the surface's own keys, prepare is the identity —
 * the sketch's target writes canonical values and has no storage shape
 * of its own to translate to — and commit hands the accepted values to
 * the target with the context it loads by.
 *
 * A storage shape for third-party settings, which the engine's own
 * targets apply in prepare, is not applied here: no surface in the new
 * spelling has a target yet that stores them.
 *
 * @internal
 */
final class SurfaceTargetAdapter implements DataSurfaceTargetInterface {

  use DependencySerializationTrait;

  /**
   * Constructs a SurfaceTargetAdapter.
   *
   * @param \Drupal\data_surface\Surface\SurfaceTargetInterface $target
   *   The surface's target.
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   The context it loads and commits by.
   */
  public function __construct(
    protected SurfaceTargetInterface $target,
    protected SurfaceContext $context,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function load(DataSurfaceInterface $surface): array {
    return array_intersect_key($this->target->load($this->context), $surface->getDefinitions()->toArray());
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(DataSurfaceInterface $surface, array $values): PreparedValues {
    // phpcs:ignore Drupal.Files.LineLength.TooLong
    // SKETCH GAP: the sketch's target has no prepare step and no third-party storage shape; prepare is the identity and mounted settings shapes are not applied.
    return new PreparedValues($values, $values);
  }

  /**
   * {@inheritdoc}
   */
  public function commit(PreparedValues $prepared): void {
    $this->target->commit($this->context, $prepared->values);
  }

}
