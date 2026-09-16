<?php

declare(strict_types=1);

namespace Drupal\data_surface\Target;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\ToConfig;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;
use Drupal\data_surface\Pipeline\PreparedValues;

/**
 * Stores a surface's values in one simple config object.
 *
 * The target for settings forms and for anything else whose state is a
 * config object rather than an entity. The map from surface keys to
 * config property paths, with optional callables in each direction,
 * mirrors core's ConfigTarget, so a form already carrying
 * '#config_target' metadata describes the same thing this target does
 * and the two can be ported into each other.
 *
 * Preparing works on a copy of the config object, and that copy is the
 * artifact. A Config handed to this target is nearly always the one the
 * config factory has cached and the rest of the request is reading, so
 * setting values on it would make a dry run and a schema refusal visible
 * site-wide, and any later unrelated save of that same object would
 * persist a submission nobody accepted. Cloning is enough because a
 * Config's data is a plain array: the copy shares the storage, the
 * dispatcher and the typed config manager it was built with, so the
 * write it performs at commit is the same write in the same place — the
 * factory even refreshes its cached objects from the save event, so what
 * the caller holds is up to date afterwards.
 *
 * On a real dry run: a Config holds its own storage, given to it at
 * construction, so a config built against a MemoryStorage is a genuine
 * per-object storage swap. Build one with
 * `new Config($name, new MemoryStorage(), new EventDispatcher(),
 * $typed_config)`, seed it with `initWithData($active->read($name))`,
 * and commit writes for real into a bin nobody else reads while the
 * active storage never hears about it — the private dispatcher is the
 * part that keeps the rehearsal's save event away from the listeners the
 * live config factory keeps. This is the one place in the whole config
 * system where redirecting a single write costs nothing.
 *
 * Both callables must be serializable if this target is ever cached with
 * a form: a static or service method, never a closure. Nothing in this
 * module puts a target on a form array any more — the form traits carry
 * identifiers and rebuild — but the rule stands for adopters that do.
 *
 * @see \Drupal\Core\Form\ConfigTarget
 * @see \Drupal\Core\Config\MemoryStorage
 * @see docs/targets.md
 */
final class ConfigObjectTarget implements DataSurfaceTargetInterface {

  use DependencySerializationTrait;

  /**
   * Surface key => config property path.
   *
   * @var array<string, string>
   */
  protected readonly array $paths;

  /**
   * The config the surface describes, replaced by what commit() saved.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected Config $config;

  /**
   * Constructs a ConfigObjectTarget.
   *
   * @param \Drupal\Core\Config\Config $config
   *   The config object the surface describes. Build it against a
   *   MemoryStorage to get a dry run that commits for real into a bin.
   *   It is read from and copied, never written to.
   * @param array $map
   *   Surface key => config property path, dotted. A plain list entry
   *   names a key whose path is its own name, and a surface key the map
   *   leaves out is written at its own name too. Only mapped keys are
   *   read back by load(), so list every key the surface owns.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfig
   *   The typed configuration manager, which validates the prepared data
   *   against the config schema. Only violations under a path this
   *   target writes are reported; a pre-existing violation elsewhere in
   *   the config object does not block an unrelated write.
   * @param array<string, callable> $toConfig
   *   Surface key => callable turning the surface value into the value
   *   config stores. A callable may return ToConfig::DeleteKey to clear
   *   the path instead, or ToConfig::NoOp to leave it alone.
   * @param array<string, callable> $fromConfig
   *   Surface key => callable turning the stored value into the value
   *   the surface describes.
   */
  public function __construct(
    Config $config,
    array $map,
    protected readonly TypedConfigManagerInterface $typedConfig,
    protected readonly array $toConfig = [],
    protected readonly array $fromConfig = [],
  ) {
    $this->config = $config;
    $paths = [];
    foreach ($map as $key => $path) {
      if (is_int($key)) {
        $paths[$path] = $path;
        continue;
      }
      $paths[$key] = $path;
    }
    $this->paths = $paths;
  }

  /**
   * {@inheritdoc}
   */
  public function load(DataSurfaceInterface $surface): array {
    $values = [];
    foreach ($this->paths as $key => $path) {
      $value = $this->config->get($path);
      if (isset($this->fromConfig[$key])) {
        $value = ($this->fromConfig[$key])($value);
      }
      $values[$key] = $value;
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(DataSurfaceInterface $surface, array $values): PreparedValues {
    // The caller's config object is read, never written: everything
    // below happens to a copy, which is what a dry run hands back and
    // what a commit saves.
    $config = clone $this->config;
    $paths = $this->surfacePaths($surface);
    foreach ($paths as $key => $path) {
      if (!array_key_exists($key, $values)) {
        continue;
      }
      $value = $values[$key];
      if (isset($this->toConfig[$key])) {
        $value = ($this->toConfig[$key])($value);
      }
      if ($value === ToConfig::NoOp) {
        continue;
      }
      if ($value === ToConfig::DeleteKey) {
        $config->clear($path);
        continue;
      }
      $config->set($path, $value);
    }
    SchemaViolations::check(
      $this->typedConfig,
      $config->getName(),
      $config->getRawData(),
      array_flip($paths),
    );
    return new PreparedValues($values, $config);
  }

  /**
   * {@inheritdoc}
   */
  public function commit(PreparedValues $prepared): void {
    $config = $prepared->artifact;
    if (!$config instanceof Config) {
      throw new \InvalidArgumentException('The prepared values did not come from a config object target.');
    }
    $config->save();
    // What was saved is now what this target describes, so a load()
    // after a commit reads the values that were written.
    $this->config = $config;
  }

  /**
   * Resolves every surface key to the config path it is written at.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface being prepared.
   *
   * @return array<string, string>
   *   Surface key => config property path.
   */
  protected function surfacePaths(DataSurfaceInterface $surface): array {
    $paths = $this->paths;
    foreach ($surface->getDefinitions()->names() as $key) {
      $paths[$key] ??= $key;
    }
    return $paths;
  }

}
