<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Component\Plugin\Definition\PluginDefinitionInterface;
use Drupal\data_surface\Form\FieldSurfaceProviderInterface;

/**
 * Answers which classes expose a surface, without instantiating them.
 *
 * Detection is the whole of it, and it needs nothing declared for the
 * purpose: implementing a provider interface is visible from any plugin
 * definition's class name, which is cached. So a deriver, a
 * documentation page, or an agent listing the configurable things on a
 * site reads the list from plugin definitions alone and boots nothing.
 *
 * What a surface *holds* is not answered here. It used to be, in the
 * days when a class declared the flat part of its surface in an
 * attribute, and the answer was always a sketch: it could not carry a
 * map's properties, it knew nothing of what the build event adds, and a
 * host whose surface needed live state declared none of it. The honest
 * answer to "what does this surface hold" is the built surface, which
 * costs an instance; the honest cheap answer is "this class has one",
 * which is this service.
 */
final class DataSurfaceAwareness {

  /**
   * Determines whether a class exposes a surface.
   *
   * Both provider interfaces count. A field item answers for its
   * instance settings through the field type counterpart rather than
   * through the plugin one, and a catalogue that missed field types
   * would be missing a host family rather than a corner case.
   *
   * @param string $class
   *   The fully qualified class name.
   *
   * @return bool
   *   TRUE when the class provides a surface.
   */
  public function isSurfaceAware(string $class): bool {
    return is_subclass_of($class, DataSurfaceProviderInterface::class)
      || is_subclass_of($class, FieldSurfaceProviderInterface::class);
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
        $aware[$plugin_id] = new DataSurfaceAwarenessRecord($class);
      }
    }
    return $aware;
  }

}
