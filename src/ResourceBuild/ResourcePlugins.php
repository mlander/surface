<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

use Drupal\surface\Resource\Attribute\UsesStructure;

/**
 * Which plugins name which structure with #[UsesStructure].
 *
 * Read from the plugin definitions of every host that reads the
 * attribute, where the host's definition alter recorded it, so nothing
 * is instantiated. A structure some plugin uses is that plugin's
 * configuration: its host supplies the context and the storage, so it
 * is configured through the host, and the catalog and the tool
 * deriver both ask here.
 *
 * It is also where a plugin that is its own structure is found: one
 * whose definition records its own class as its structure. Discovery
 * reads those from here rather than from a directory, because a plugin
 * lives where its plugin type says, not in src/Structure.
 *
 * @see \Drupal\surface\Hook\ResourcePluginHooks
 */
final class ResourcePlugins {

  /**
   * The plugins naming each structure, once every host was read whole.
   *
   * @var array<class-string, list<string>>|null
   */
  protected ?array $used = NULL;

  /**
   * The plugins that are their own structure, once every host was read.
   *
   * @var array<class-string, array{module: string, id: string}>|null
   */
  protected ?array $own = NULL;

  /**
   * What the plugin calls itself, for each structure one plugin alone names.
   *
   * @var array<class-string, array{label: string|\Stringable|null, description: string|\Stringable|null}>|null
   */
  protected ?array $names = NULL;

  /**
   * The hosts whose definitions are being read now, keyed by host type.
   *
   * @var array<string, true>
   */
  protected array $reading = [];

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
    return $this->read()['used'][$resource] ?? [];
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
    return $this->read()['own'];
  }

  /**
   * Gets what the plugin that hosts a structure calls itself.
   *
   * Read from its plugin definition: a block's `admin_label`, any other
   * plugin's `label`, and its `description` where it has one, so the
   * structure need not say again what the plugin says. Only where one
   * plugin alone names the structure: two plugins sharing one would each
   * call it something else.
   *
   * Decision: see
   * docs/decisions.md#a-resource-and-a-structure-may-say-what-they-are-called-and-what-they-are-for.
   *
   * @param string $resource
   *   The structure class, or any other.
   *
   * @return array{label: string|\Stringable|null, description: string|\Stringable|null}
   *   The plugin's label and description; both NULL when no plugin, or
   *   more than one, names the class.
   */
  public function hostNames(string $resource): array {
    return $this->read()['names'][$resource] ?? ['label' => NULL, 'description' => NULL];
  }

  /**
   * Answers whether every host's definitions have been read whole.
   *
   * FALSE only while they are being read: a host's definitions may be
   * derived from something that asks here (eca_tool derives an action
   * per tool, and the tool deriver asks the registry, which asks for the
   * plugins that are their own structure), and that call is answered
   * from every host but the ones being read, which is not the whole
   * answer. Whoever keeps what it made from an answer asks this first,
   * so nothing incomplete is memoized or cached.
   *
   * @return bool
   *   TRUE once read whole.
   */
  public function isComplete(): bool {
    return $this->used !== NULL;
  }

  /**
   * Reads every host's definitions once.
   *
   * Re-entrant: a call made while a host is being read (from inside a
   * deriver that host runs) reads every other host and answers that,
   * keeping none of it, because asking a host being read would start
   * its discovery again and never end. What is kept is assigned only
   * once every host was read whole, so no call sees a half state.
   *
   * @return array{used: array<class-string, list<string>>, own: array<class-string, array{module: string, id: string}>, names: array<class-string, array{label: string|\Stringable|null, description: string|\Stringable|null}>}
   *   The plugins naming each structure, the plugins that are their own
   *   structure, and what the plugin calls itself where one alone names a
   *   structure.
   */
  protected function read(): array {
    if ($this->used !== NULL) {
      return ['used' => $this->used, 'own' => $this->own ?? [], 'names' => $this->names ?? []];
    }
    if ($this->reading !== []) {
      return $this->collect();
    }
    $read = $this->collect();
    $this->used = $read['used'];
    $this->own = $read['own'];
    $this->names = $read['names'];
    return $read;
  }

  /**
   * Collects what every host not being read now says.
   *
   * @return array{used: array<class-string, list<string>>, own: array<class-string, array{module: string, id: string}>, names: array<class-string, array{label: string|\Stringable|null, description: string|\Stringable|null}>}
   *   As read() answers.
   */
  protected function collect(): array {
    $used = [];
    $own = [];
    $names = [];
    foreach ($this->managers as $host => $manager) {
      if (isset($this->reading[$host])) {
        continue;
      }
      $this->reading[$host] = TRUE;
      try {
        $definitions = $manager->getDefinitions();
      }
      finally {
        unset($this->reading[$host]);
      }
      foreach ($definitions as $id => $definition) {
        $surface = is_array($definition) ? ($definition[UsesStructure::DEFINITION_KEY] ?? NULL) : NULL;
        if (!is_string($surface)) {
          continue;
        }
        $used[$surface][] = $host . ':' . $id;
        $names[$surface] = [
          'label' => self::text($definition[$host === 'block' ? 'admin_label' : 'label'] ?? NULL),
          'description' => self::text($definition['description'] ?? NULL),
        ];
        // Decision: see docs/decisions.md#a-plugin-that-is-its-own-structure.
        if ($surface === ($definition['class'] ?? NULL)) {
          $own[$surface][] = [
            'module' => (string) ($definition['provider'] ?? ''),
            'id' => $host . ':' . ($definition['id'] ?? $id),
          ];
        }
      }
    }
    foreach ($used as &$plugins) {
      sort($plugins);
    }
    unset($plugins);
    ksort($own);
    $first = [];
    foreach ($own as $class => $plugins) {
      usort($plugins, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
      $first[$class] = $plugins[0];
    }
    // Decision: see
    // docs/decisions.md#a-resource-and-a-structure-may-say-what-they-are-called-and-what-they-are-for.
    $names = array_filter($names, static fn (string $class): bool => count($used[$class]) === 1, ARRAY_FILTER_USE_KEY);
    return ['used' => $used, 'own' => $first, 'names' => $names];
  }

  /**
   * Keeps a plugin definition's label or description when it is text.
   *
   * @param mixed $value
   *   What the definition holds there.
   *
   * @return string|\Stringable|null
   *   The text, or NULL for anything else (a field type's description may
   *   be a list of lines) and for none.
   */
  private static function text(mixed $value): string|\Stringable|null {
    return (is_string($value) && $value !== '') || $value instanceof \Stringable ? $value : NULL;
  }

}
