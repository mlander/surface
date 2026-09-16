<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Component\Plugin\Definition\PluginDefinitionInterface;
use Drupal\data_surface\Attribute\DataSurfaceAware;

/**
 * Answers surface questions about classes without instantiating them.
 *
 * Detection needs no attribute: implementing the provider interface is
 * visible from any plugin definition's class name. The attribute adds
 * the statically declared refinement map on top, so a consumer can know
 * not just that a plugin is configurable through a surface, but which of
 * its values depend on which others, before a single plugin is booted.
 *
 * That is what makes a catalogue possible — a deriver, a documentation
 * page, or an agent listing the configurable things on a site reads the
 * whole answer from plugin definitions, which are already cached.
 */
final class DataSurfaceAwareness {

  /**
   * Determines whether a class exposes a surface.
   *
   * @param string $class
   *   The fully qualified class name.
   *
   * @return bool
   *   TRUE when the class provides a surface, by interface or attribute.
   */
  public function isSurfaceAware(string $class): bool {
    return is_subclass_of($class, DataSurfaceProviderInterface::class)
      || DataSurfaceAware::fromClass($class) !== NULL;
  }

  /**
   * Reads a class's statically declared refinement map.
   *
   * @param string $class
   *   The fully qualified class name.
   *
   * @return array<string, string[]>
   *   The refinement map; empty when the class declares none statically.
   */
  public function declaredRefinements(string $class): array {
    return DataSurfaceAware::refinementsOf($class);
  }

  /**
   * Filters a set of plugin definitions to the surface-aware ones.
   *
   * Works on the raw definitions of any plugin manager; nothing is
   * instantiated.
   *
   * @param array $definitions
   *   Plugin definitions (arrays with a 'class' key, or definition
   *   objects), keyed by plugin ID.
   *
   * @return array<string, \Drupal\data_surface\DataSurfaceAwarenessRecord>
   *   The surface-aware subset: plugin IDs mapped to what can be known
   *   about each one without booting it.
   */
  public function filterDefinitions(array $definitions): array {
    $aware = [];
    foreach ($definitions as $plugin_id => $definition) {
      $class = match (TRUE) {
        $definition instanceof PluginDefinitionInterface => $definition->getClass(),
        is_array($definition) => $definition['class'] ?? NULL,
        default => NULL,
      };
      if ($class !== NULL && $this->isSurfaceAware($class)) {
        $aware[$plugin_id] = new DataSurfaceAwarenessRecord($class, $this->declaredRefinements($class));
      }
    }
    return $aware;
  }

}
