<?php

declare(strict_types=1);

namespace Drupal\surface_sketch\Surface\Attribute;

/**
 * On a plugin class: its configuration is this surface.
 *
 * The surface lives in src/Surface like any other, so it reads the same
 * and alters find it the same way. The plugin host (block, formatter,
 * condition, ...) supplies the situation and the target, because only
 * it holds the plugin instance.
 *
 * Being on the plugin's own attributes, it is in the plugin definition,
 * so a tool can list which plugins have a surface without instantiating
 * any.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class UsesSurface {

  /**
   * @param class-string<\Drupal\surface_sketch\Surface\SurfaceInterface> $surface
   */
  public function __construct(public readonly string $surface) {}

}
