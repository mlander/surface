<?php

declare(strict_types=1);

namespace Drupal\data_surface\Surface\Attribute;

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
 * any. Core's plugin discovery reads only the plugin type's own
 * attribute, so this one is copied into the definition, under
 * DEFINITION_KEY, by the plugin type's definition alter hook.
 *
 * @see \Drupal\data_surface\Hook\SurfacePluginHooks
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class UsesSurface {

  /**
   * The plugin definition key the surface class is recorded under.
   */
  public const DEFINITION_KEY = 'data_surface';

  /**
   * Constructs a UsesSurface attribute.
   *
   * @param class-string<\Drupal\data_surface\Surface\SurfaceInterface> $surface
   *   The surface the plugin's configuration is.
   */
  public function __construct(public readonly string $surface) {}

}
