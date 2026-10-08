<?php

declare(strict_types=1);

namespace Drupal\data_surface\SurfaceBuild;

use Drupal\data_surface\Surface\Attribute\UsesSurface;

/**
 * Which plugins name which surface with #[UsesSurface].
 *
 * Read from the plugin definitions of every host that reads the
 * attribute, where the host's definition alter recorded it, so nothing
 * is instantiated. A surface some plugin uses is that plugin's
 * configuration: its host supplies the context and the target, so it is
 * configured through the host, and the catalogue and the tool deriver
 * both ask here.
 *
 * @see \Drupal\data_surface\Hook\SurfacePluginHooks
 */
final class SurfacePlugins {

  /**
   * The plugins naming each surface, once read.
   *
   * @var array<class-string, list<string>>|null
   */
  protected ?array $used = NULL;

  /**
   * Constructs a SurfacePlugins.
   *
   * @param array<string, \Drupal\Component\Plugin\PluginManagerInterface> $managers
   *   The managers of the plugin hosts that read #[UsesSurface], keyed by
   *   host type, as SurfacePluginHooks::HOSTS lists them.
   */
  public function __construct(
    protected readonly array $managers,
  ) {}

  /**
   * Lists the plugins whose configuration is a surface.
   *
   * @param string $surface
   *   The surface class.
   *
   * @return list<string>
   *   Each plugin as `<host type>:<plugin id>`, the host id its build
   *   event sees, sorted; empty when no plugin names the surface.
   */
  public function usedBy(string $surface): array {
    return $this->used()[$surface] ?? [];
  }

  /**
   * Reads every host's definitions once.
   *
   * @return array<class-string, list<string>>
   *   The plugins, keyed by the surface they name.
   */
  protected function used(): array {
    if ($this->used !== NULL) {
      return $this->used;
    }
    $this->used = [];
    foreach ($this->managers as $host => $manager) {
      foreach ($manager->getDefinitions() as $id => $definition) {
        $surface = is_array($definition) ? ($definition[UsesSurface::DEFINITION_KEY] ?? NULL) : NULL;
        if (is_string($surface)) {
          $this->used[$surface][] = $host . ':' . $id;
        }
      }
    }
    foreach ($this->used as &$plugins) {
      sort($plugins);
    }
    unset($plugins);
    return $this->used;
  }

}
