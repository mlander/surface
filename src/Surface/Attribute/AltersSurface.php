<?php

declare(strict_types=1);

namespace Drupal\data_surface\Surface\Attribute;

/**
 * On a class in src/SurfaceAlter: which surface it alters.
 *
 * Static, like #[SurfaceVariant(of:)] and #[Situation(of:)], so a
 * catalogue can say who alters a surface without running anything. The
 * class is registered as an autowired service, the way a hook class is,
 * so it may hold services.
 *
 * A surface with a class is named by its class. A derived surface has
 * none, so it is named by its id, `entity.content:node.page`, or every
 * derivative of one deriver at once by the deriver's base id,
 * `entity.content`.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AltersSurface {

  /**
   * Constructs an AltersSurface attribute.
   *
   * @param string $surface
   *   The surface this class alters: its class, or for a derived surface
   *   its id, or a deriver's base id for every surface it derives.
   * @param string[] $situations
   *   Apply only in these situations. Empty means all.
   */
  public function __construct(
    public readonly string $surface,
    public readonly array $situations = [],
  ) {}

}
