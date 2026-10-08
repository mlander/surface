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
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AltersSurface {

  /**
   * Constructs an AltersSurface attribute.
   *
   * @param class-string<\Drupal\data_surface\Surface\SurfaceInterface> $surface
   *   The surface this class alters.
   * @param string[] $situations
   *   Apply only in these situations. Empty means all.
   */
  public function __construct(
    public readonly string $surface,
    public readonly array $situations = [],
  ) {}

}
