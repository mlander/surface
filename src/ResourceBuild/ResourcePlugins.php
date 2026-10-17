<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\data_surface\Resource\Attribute\UsesStructure;

/**
 * Which plugins name which structure with #[UsesStructure].
 *
 * Read from the plugin definitions of every host that reads the
 * attribute, where the host's definition alter recorded it, so nothing
 * is instantiated. A structure some plugin uses is that plugin's
 * configuration: its host supplies the context and the storage, so it
 * is configured through the host, and the catalogue and the tool
 * deriver both ask here.
 *
 * It is also where a plugin that is its own structure is found: one
 * whose definition records its own class as its structure. Discovery
 * reads those from here rather than from a directory, because a plugin
 * lives where its plugin type says, not in src/Structure.
 *
 * @see \Drupal\data_surface\Hook\ResourcePluginHooks
 */
final class ResourcePlugins {

  /**
   * The plugins naming each structure, once read.
   *
   * @var array<class-string, list<string>>|null
   */
  protected ?array $used = NULL;

  /**
   * The plugins that are their own structure, once read.
   *
   * @var array<class-string, array{module: string, id: string}>|null
   */
  protected ?array $own = NULL;

  /**
   * Constructs a ResourcePlugins.
   *
   * @param array<string, \Drupal\Component\Plugin\PluginManagerInterface> $managers
   *   The managers of the plugin hosts that read #[UsesStructure], keyed by
   *   host type, as ResourcePluginHooks::HOSTS lists them.
   */
  public function __construct(
    protected readonly array $managers,
  ) {}

  /**
   * Lists the plugins whose configuration is a structure.
   *
   * @param string $resource
   *   The structure class, or any other.
   *
   * @return list<string>
   *   Each plugin as `<host type>:<plugin id>`, sorted; empty when no
   *   plugin names the class.
   */
  public function usedBy(string $resource): array {
    $this->read();
    return $this->used[$resource] ?? [];
  }

  /**
   * Lists the plugin classes that are their own structure.
   *
   * A class several plugins share (a deriver's derivatives, or one class
   * under two plugin ids) is one structure. Its id is the first of its
   * plugins as `<host type>:<base plugin id>`, in sorted order, so it is
   * the same on every machine.
   *
   * @return array<class-string, array{module: string, id: string}>
   *   The module that provides each plugin and the id derived from it,
   *   keyed by plugin class, sorted by class.
   */
  public function ownStructures(): array {
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
        $surface = is_array($definition) ? ($definition[UsesStructure::DEFINITION_KEY] ?? NULL) : NULL;
        if (!is_string($surface)) {
          continue;
        }
        $this->used[$surface][] = $host . ':' . $id;
        // Decision: see docs/decisions.md#a-plugin-that-is-its-own-structure.
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
