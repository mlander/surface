<?php

declare(strict_types=1);

namespace Drupal\data_surface\Surface\Attribute;

/**
 * On a plugin class: its configuration is this surface.
 *
 * Two spellings. Naming a class, #[UsesSurface(DemoBlockSurface::class)],
 * points at a surface in src/Surface like any other, so it reads the same
 * and alters find it the same way. With no argument, #[UsesSurface], the
 * plugin class is its own surface: it implements SurfaceInterface, with
 * its static defineInputs() and its static #[RefinesInput] methods beside
 * the plugin's own code, and the host builds it from the plugin class.
 * Such a surface is found through the plugin definitions rather than a
 * directory; its id is `<host type>:<plugin id>` unless the class also
 * carries #[Surface], and an alter targets it by the plugin class.
 *
 * Either way the plugin host (block, formatter, condition, ...) supplies
 * the situation and the target, because only it holds the plugin
 * instance.
 *
 * Being on the plugin's own attributes, it is in the plugin definition,
 * so a tool can list which plugins have a surface without instantiating
 * any. Core's plugin discovery reads only the plugin type's own
 * attribute, so this one is copied into the definition, under
 * DEFINITION_KEY, by the plugin type's definition alter hook: the surface
 * class it names, or the plugin class itself when it names none.
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
   * @param class-string<\Drupal\data_surface\Surface\SurfaceInterface>|null $surface
   *   The surface the plugin's configuration is, or NULL when the plugin
   *   class is its own surface.
   */
  public function __construct(public readonly ?string $surface = NULL) {}

}
