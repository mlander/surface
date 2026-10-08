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
 * It is also where a plugin that is its own surface is found: one whose
 * definition records its own class as its surface. Discovery reads those
 * from here rather than from a directory, because a plugin lives where
 * its plugin type says, not in src/Surface.
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
   * The plugins that are their own surface, once read.
   *
   * @var array<class-string, array{module: string, id: string}>|null
   */
  protected ?array $own = NULL;

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
   *   Each plugin as `<host type>:<plugin id>`, sorted; empty when no
   *   plugin names the surface.
   */
  public function usedBy(string $surface): array {
    $this->read();
    return $this->used[$surface] ?? [];
  }

  /**
   * Lists the plugin classes that are their own surface.
   *
   * A class several plugins share — a deriver's derivatives, or one class
   * under two plugin ids — is one surface. Its id is the first of its
   * plugins as `<host type>:<base plugin id>`, in sorted order, so it is
   * the same on every machine.
   *
   * @return array<class-string, array{module: string, id: string}>
   *   The module that provides each plugin and the id derived from it,
   *   keyed by plugin class, sorted by class.
   */
  public function ownSurfaces(): array {
    $this->read();
    return $this->own ?? [];
  }

  /**
   * Reads every host's definitions once.
   */
  protected function read(): void {
    if ($this->used !== NULL) {
      return;
    }
    $this->used = [];
    $own = [];
    foreach ($this->managers as $host => $manager) {
      foreach ($manager->getDefinitions() as $id => $definition) {
        $surface = is_array($definition) ? ($definition[UsesSurface::DEFINITION_KEY] ?? NULL) : NULL;
        if (!is_string($surface)) {
          continue;
        }
        $this->used[$surface][] = $host . ':' . $id;
        // Decision: see docs/decisions.md#a-plugin-that-is-its-own-surface.
        if ($surface === ($definition['class'] ?? NULL)) {
          $own[$surface][] = [
            'module' => (string) ($definition['provider'] ?? ''),
            'id' => $host . ':' . ($definition['id'] ?? $id),
          ];
        }
      }
    }
    foreach ($this->used as &$plugins) {
      sort($plugins);
    }
    unset($plugins);
    ksort($own);
    $this->own = [];
    foreach ($own as $class => $plugins) {
      usort($plugins, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
      $this->own[$class] = $plugins[0];
    }
  }

}
