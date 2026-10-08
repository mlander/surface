<?php

declare(strict_types=1);

namespace Drupal\data_surface\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\data_surface\Surface\Attribute\UsesSurface;

/**
 * Copies #[UsesSurface] into the definitions of the plugin types it serves.
 *
 * Core's attribute discovery reads only the plugin type's own attribute,
 * #[Block] for a block, so a second attribute on the class would be
 * invisible to anything that reads definitions. Copying it in here puts
 * the surface class in the cached definition, under
 * UsesSurface::DEFINITION_KEY, where a host reads it and a tool or the
 * catalogue can list the surfaced plugins without instantiating one.
 *
 * One alter per plugin host this module serves: blocks, formatters,
 * conditions, actions and field types. Each runs last, so a class
 * another module swapped in with its own alter of the same definitions
 * (SurfaceAddressItem replacing AddressItem) is the class read.
 */
final class SurfacePluginHooks {

  /**
   * The plugin hosts that read #[UsesSurface], and their managers.
   *
   * Keyed by host type, the prefix the catalogue lists a plugin under
   * (`block:<plugin id>`).
   */
  public const HOSTS = [
    'block' => 'plugin.manager.block',
    'field_formatter' => 'plugin.manager.field.formatter',
    'condition' => 'plugin.manager.condition',
    'action' => 'plugin.manager.action',
    'field_type' => 'plugin.manager.field.field_type',
  ];

  /**
   * Implements hook_block_alter().
   *
   * @param array $definitions
   *   The block plugin definitions, keyed by plugin id.
   */
  #[Hook('block_alter', order: Order::Last)]
  public function blockAlter(array &$definitions): void {
    self::recordUsedSurfaces($definitions);
  }

  /**
   * Implements hook_field_formatter_info_alter().
   *
   * @param array $definitions
   *   The field formatter plugin definitions, keyed by plugin id.
   */
  #[Hook('field_formatter_info_alter', order: Order::Last)]
  public function fieldFormatterInfoAlter(array &$definitions): void {
    self::recordUsedSurfaces($definitions);
  }

  /**
   * Implements hook_condition_info_alter().
   *
   * @param array $definitions
   *   The condition plugin definitions, keyed by plugin id.
   */
  #[Hook('condition_info_alter', order: Order::Last)]
  public function conditionInfoAlter(array &$definitions): void {
    self::recordUsedSurfaces($definitions);
  }

  /**
   * Implements hook_action_info_alter().
   *
   * @param array $definitions
   *   The action plugin definitions, keyed by plugin id.
   */
  #[Hook('action_info_alter', order: Order::Last)]
  public function actionInfoAlter(array &$definitions): void {
    self::recordUsedSurfaces($definitions);
  }

  /**
   * Implements hook_field_info_alter().
   *
   * Last, so the class read is the one a module swapped in: the address
   * field type's is SurfaceAddressItem, which carries the attribute, not
   * AddressItem, which does not. A field item reaches its definition as
   * a typed data definition, which core derives from this one, key and
   * all.
   *
   * @param array $definitions
   *   The field type plugin definitions, keyed by plugin id.
   */
  #[Hook('field_info_alter', order: Order::Last)]
  public function fieldInfoAlter(array &$definitions): void {
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
      if (!is_string($class)) {
        continue;
      }
      // phpcs:ignore Drupal.Files.LineLength.TooLong
      // SKETCH GAP: the sketch says #[UsesSurface] "is in the plugin definition" but core discovery reads only the type's own attribute; a definition alter per plugin host copies it under UsesSurface::DEFINITION_KEY.
      $surface = self::usedSurfaceOf($class);
      if ($surface !== NULL) {
        $definition[UsesSurface::DEFINITION_KEY] = $surface;
      }
      else {
        // A class swapped for one that names no surface names none.
        unset($definition[UsesSurface::DEFINITION_KEY]);
      }
    }
  }

  /**
   * Reads the surface a plugin class names with #[UsesSurface].
   *
   * For the static half of a host's protocol — a formatter's
   * defaultSettings(), a field type's defaultFieldSettings() — which is
   * asked of the class with no definition in hand. Everything with an
   * instance reads the definition instead.
   *
   * @param string $class
   *   The plugin class.
   *
   * @return class-string|null
   *   The surface class, or NULL when the class names none, inherited
   *   from a parent class or not.
   */
  public static function usedSurfaceOf(string $class): ?string {
    if (!class_exists($class)) {
      return NULL;
    }
    // A subclass of a surfaced plugin is that plugin, unless it names a
    // surface of its own: the attribute is not inherited by PHP, so the
    // ancestry is walked here.
    // phpcs:ignore Drupal.Files.LineLength.TooLong
    // SKETCH GAP: the sketch puts #[UsesSurface] on the plugin class without saying whether a subclass inherits it; it does, the nearest class naming one wins, as a subclassed block keeps its parent's form.
    for ($reflection = new \ReflectionClass($class); $reflection !== FALSE; $reflection = $reflection->getParentClass()) {
      foreach ($reflection->getAttributes(UsesSurface::class) as $attribute) {
        return $attribute->newInstance()->surface;
      }
    }
    return NULL;
  }

}
