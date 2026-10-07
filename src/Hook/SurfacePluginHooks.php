<?php

declare(strict_types=1);

namespace Drupal\data_surface\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\data_surface\Surface\Attribute\UsesSurface;

/**
 * Copies #[UsesSurface] into the definitions of the plugin types it serves.
 *
 * Core's attribute discovery reads only the plugin type's own attribute,
 * #[Block] for a block, so a second attribute on the class would be
 * invisible to anything that reads definitions. Copying it in here puts
 * the surface class in the cached definition, under
 * UsesSurface::DEFINITION_KEY, where a host reads it and a tool can list
 * the surfaced plugins without instantiating one.
 *
 * Blocks only, for now: the other plugin hosts move to #[UsesSurface] in
 * step 4 of the rework, and each brings its own alter hook here.
 */
final class SurfacePluginHooks {

  /**
   * Implements hook_block_alter().
   *
   * @param array $definitions
   *   The block plugin definitions, keyed by plugin id.
   */
  #[Hook('block_alter')]
  public function blockAlter(array &$definitions): void {
    self::recordUsedSurfaces($definitions);
  }

  /**
   * Records each definition's #[UsesSurface], when its class carries one.
   *
   * @param array $definitions
   *   Plugin definitions, as arrays naming their class.
   */
  public static function recordUsedSurfaces(array &$definitions): void {
    foreach ($definitions as &$definition) {
      $class = is_array($definition) ? ($definition['class'] ?? NULL) : NULL;
      if (!is_string($class) || !class_exists($class)) {
        continue;
      }
      // phpcs:ignore Drupal.Files.LineLength.TooLong
      // SKETCH GAP: the sketch says #[UsesSurface] "is in the plugin definition" but core discovery reads only the type's own attribute; a definition alter copies it under UsesSurface::DEFINITION_KEY.
      foreach ((new \ReflectionClass($class))->getAttributes(UsesSurface::class) as $attribute) {
        $definition[UsesSurface::DEFINITION_KEY] = $attribute->newInstance()->surface;
      }
    }
  }

}
