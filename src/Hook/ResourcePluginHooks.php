<?php

declare(strict_types=1);

namespace Drupal\data_surface\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\data_surface\Resource\Attribute\UsesStructure;

/**
 * Copies #[UsesStructure] into the definitions of the plugin types it serves.
 *
 * Core's attribute discovery reads only the plugin type's own attribute,
 * #[Block] for a block, so a second attribute on the class would be
 * invisible to anything that reads definitions. Copying it in here puts
 * the structure class in the cached definition, under
 * UsesStructure::DEFINITION_KEY, where a host reads it and a tool or the
 * catalog can list the plugins that use a structure without
 * instantiating one. A plugin that is its own structure records its own
 * class there, which is how discovery finds that structure.
 *
 * One alter per plugin host this module serves: blocks, formatters,
 * conditions, actions and field types. Each runs last, so a class
 * another module swapped in with its own alter of the same definitions
 * (SurfaceAddressItem replacing AddressItem) is the class read.
 */
final class ResourcePluginHooks {

  /**
   * The plugin hosts that read #[UsesStructure], and their managers.
   *
   * Keyed by host type, the prefix the catalog lists a plugin under
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
    self::recordUsedStructures($definitions);
  }

  /**
   * Implements hook_field_formatter_info_alter().
   *
   * @param array $definitions
   *   The field formatter plugin definitions, keyed by plugin id.
   */
  #[Hook('field_formatter_info_alter', order: Order::Last)]
  public function fieldFormatterInfoAlter(array &$definitions): void {
    self::recordUsedStructures($definitions);
  }

  /**
   * Implements hook_condition_info_alter().
   *
   * @param array $definitions
   *   The condition plugin definitions, keyed by plugin id.
   */
  #[Hook('condition_info_alter', order: Order::Last)]
  public function conditionInfoAlter(array &$definitions): void {
    self::recordUsedStructures($definitions);
  }

  /**
   * Implements hook_action_info_alter().
   *
   * @param array $definitions
   *   The action plugin definitions, keyed by plugin id.
   */
  #[Hook('action_info_alter', order: Order::Last)]
  public function actionInfoAlter(array &$definitions): void {
    self::recordUsedStructures($definitions);
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
    self::recordUsedStructures($definitions);
  }

  /**
   * Records each definition's #[UsesStructure], when its class carries one.
   *
   * @param array $definitions
   *   Plugin definitions, as arrays naming their class.
   */
  public static function recordUsedStructures(array &$definitions): void {
    foreach ($definitions as &$definition) {
      $class = is_array($definition) ? ($definition['class'] ?? NULL) : NULL;
      if (!is_string($class)) {
        continue;
      }
      $surface = self::usedStructureOf($class);
      if ($surface !== NULL) {
        $definition[UsesStructure::DEFINITION_KEY] = $surface;
      }
      else {
        // A class swapped for one that names no structure names none.
        unset($definition[UsesStructure::DEFINITION_KEY]);
      }
    }
  }

  /**
   * Reads the structure a plugin class names with #[UsesStructure].
   *
   * For the static half of a host's protocol (a formatter's
   * defaultSettings(), a field type's defaultFieldSettings()), which is
   * asked of the class with no definition in hand. Everything with an
   * instance reads the definition instead.
   *
   * @param string $class
   *   The plugin class.
   *
   * @return class-string|null
   *   The structure class, or NULL when the class names none, inherited
   *   from a parent class or not. A #[UsesStructure] with no argument
   *   answers the class asked about: that plugin is its own structure.
   */
  public static function usedStructureOf(string $class): ?string {
    if (!class_exists($class)) {
      return NULL;
    }
    // A subclass of a plugin that uses a structure is that plugin, unless
    // it names a structure of its own: the attribute is not inherited by
    // PHP, so the ancestry is walked here. A subclass of a plugin that is
    // its own structure is its own structure in turn, inheriting the
    // static defineInputs().
    // Decision: see docs/decisions.md#a-plugin-that-is-its-own-structure.
    for ($reflection = new \ReflectionClass($class); $reflection !== FALSE; $reflection = $reflection->getParentClass()) {
      foreach ($reflection->getAttributes(UsesStructure::class) as $attribute) {
        return $attribute->newInstance()->structure ?? $class;
      }
    }
    return NULL;
  }

  /**
   * Answers whether a plugin class is its own structure.
   *
   * @param string $class
   *   The plugin class.
   *
   * @return bool
   *   TRUE when its #[UsesStructure], its own or inherited, names no other
   *   class.
   */
  public static function isOwnStructure(string $class): bool {
    return self::usedStructureOf($class) === $class;
  }

}
