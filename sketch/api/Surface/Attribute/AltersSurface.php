<?php

declare(strict_types=1);

namespace Drupal\surface_sketch\Surface\Attribute;

/**
 * On a class in src/SurfaceAlter: which surface it alters.
 *
 * Static, like #[SurfaceVariant(of:)] and #[Situation(of:)], so a
 * catalogue can say who alters a surface without running anything.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AltersSurface {

  /**
   * @param class-string<\Drupal\surface_sketch\Surface\SurfaceInterface> $surface
   * @param string[] $situations
   *   Apply only in these situations. Empty means all.
   */
  public function __construct(
    public readonly string $surface,
    public readonly array $situations = [],
  ) {}

}
