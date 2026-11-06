<?php

declare(strict_types=1);

namespace Drupal\surface\Variants;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\surface\Reader\ConfigSchemaReader;
use Drupal\surface\Resource\BoundSiteListInterface;
use Drupal\surface\Resource\SiteList;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Variants from a config schema type named through another key.
 *
 * Bound to a schema type and a dotted path into it, the value whose type
 * is written `prefix.[%parent.<key>]` (or `prefix.[<key>]`), and the key
 * that decides it; or, for `prefix.[<key>]`, to the expression itself
 * with no path, which names it wherever it is used:
 *
 * @code
 * $inputs->attachVariants('settings', by: 'type', from: ConfigSchemaTypes::class, arguments: ['field.storage.*.*', 'settings', 'type']);
 * $inputs->mixInVariants('plugin_id', from: ConfigSchemaTypes::class, arguments: ['views.field.[plugin_id]', '', 'plugin_id']);
 * @endcode
 *
 * It resolves the expression as core's TypeResolver does, each value's
 * type the name it fills in, or that name's `*` fallback, and lists one
 * variant per value the deciding key may hold, each the structure its
 * type is (ConfigSchemaReader::structure()), so no JSON Schema is read or
 * written. The values, in order of preference:
 *
 * 1. a PluginExists constraint on the deciding key: that manager's
 *    definition ids and labels, its cacheability (no plugin instantiated);
 * 2. else a Choice constraint's choices;
 * 3. else every schema type named with the expression's prefix and suffix,
 *    less the `*` fallbacks.
 *
 * For `[<key>]`, a type chosen by a key inside the value, each variant is
 * the type less the keys of its fallback, which the frame declares:
 * ConfigSchemaReader mixes them in flat beside those keys.
 *
 * A third argument restates the deciding key, since listFor() is handed
 * the arguments alone: the list is refused when the expression found is
 * decided by another key.
 *
 * Decision: see
 * docs/decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter.
 */
final class ConfigSchemaTypes implements BoundSiteListInterface {

  /**
   * Constructs the provider.
   *
   * @param \Drupal\surface\Reader\ConfigSchemaReader $reader
   *   The reader, which resolves the expression and registers each variant.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfig
   *   The typed config manager.
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container, which holds the plugin manager a PluginExists names.
   */
  public function __construct(
    protected readonly ConfigSchemaReader $reader,
    protected readonly TypedConfigManagerInterface $typedConfig,
    protected readonly ContainerInterface $container,
  ) {}

  /**
   * {@inheritdoc}
   *
   * Bound to nothing it lists nothing.
   */
  public function list(): SiteList {
    return (new SiteList())->addCacheTags(['config:core.extension']);
  }

  /**
   * {@inheritdoc}
   *
   * @param list<string|int> $arguments
   *   The schema type, the dotted path of the value in it, and the key
   *   that decides it; or an own-key expression as written, no path, and
   *   the key.
   *
   * @throws \LogicException
   *   When the path names no value typed through another key, or one that
   *   key is not the deciding key named.
   */
  public function listFor(array $arguments): SiteList {
    [$type, $path, $by] = array_map('strval', $arguments) + ['', '', ''];
    $resolved = $this->resolve($type, $path);
    if ($by !== '' && $resolved['by'] !== $by) {
      throw new \LogicException(sprintf('ConfigSchemaTypes lists the variants of %s at %s for "%s", and the schema types it by "%s" (%s): the variants are chosen by the key the schema names.', $type, $path, $by, $resolved['by'], $resolved['expression']));
    }
    $list = (new SiteList())->addCacheableDependency($resolved['cacheability']);
    foreach ($resolved['candidates'] as $id => ['label' => $label, 'type' => $chosen]) {
      if ($chosen === NULL) {
        continue;
      }
      $list->add((string) $id, $label, structure: $this->reader->structure($chosen, '', $resolved['less']));
    }
    return $list;
  }

  /**
   * Resolves the value at a path: its form, its deciding key, its values.
   *
   * @param string $type
   *   The schema type; or an own-key expression as written, with no path.
   * @param string $path
   *   The dotted path of the value typed through another key; empty for
   *   an expression.
   *
   * @return array{expression: string, form: string, by: string, source: string, less: string, candidates: array<string, array{label: string|\Stringable, type: string|null}>, cacheability: \Drupal\Core\Cache\CacheableMetadata}
   *   The type as written; its form; the deciding key; where the values
   *   came from; the type whose keys each variant leaves out (for
   *   `[<key>]`); each value's label and the type it resolves to (NULL
   *   for none, which typed config reads as undefined); and what was read.
   *
   * @throws \LogicException
   *   When the path names no such value, or one of a form no variants
   *   state.
   */
  public function resolve(string $type, string $path): array {
    // An expression with no path is bound to itself: the structure read
    // for a type chosen by a key inside its own value, wherever it is.
    $found = $path === '' && str_contains($type, '[') ? [['type' => $type], []] : $this->reader->propertyAt($type, $path);
    $named = $found === NULL ? '' : (string) ($found[0]['type'] ?? '');
    if ($found === NULL || !str_contains($named, '[')) {
      throw new \LogicException(sprintf('ConfigSchemaTypes is bound to %s at %s, which %s.', $type, $path, $found === NULL ? 'the schema does not describe' : 'is not typed through another key'));
    }
    $expression = ConfigSchemaReader::expression($named, str_ends_with($path, '*'));
    $by = (string) $expression['by'];
    $less = '';
    $deciding = NULL;
    if ($expression['form'] === 'parent') {
      $deciding = $found[1]['mapping'][$by] ?? NULL;
    }
    elseif ($expression['form'] === 'own key') {
      $less = (string) $this->reader->fallbackOf($expression);
      $deciding = $less === '' ? NULL : ($this->reader->merged(['type' => $less])['mapping'][$by] ?? NULL);
    }
    else {
      throw new \LogicException(sprintf('ConfigSchemaTypes is bound to %s at %s, typed %s: chosen by a key outside the frame or by the entry\'s own key, which no variants state.', $type, $path, $named));
    }
    $cacheability = (new CacheableMetadata())->addCacheTags(['config:core.extension']);
    $constraints = is_array($deciding) ? (array) ($this->reader->merged($deciding)['constraints'] ?? []) : [];
    $values = [];
    $source = 'schema names';
    $manager_id = $constraints['PluginExists']['manager'] ?? NULL;
    if (is_string($manager_id) && $this->container->has($manager_id)) {
      $manager = $this->container->get($manager_id);
      $source = 'PluginExists ' . $manager_id;
      if ($manager instanceof CacheableDependencyInterface) {
        $cacheability->addCacheableDependency($manager);
      }
      foreach ($manager->getDefinitions() as $id => $definition) {
        $label = is_array($definition) ? ($definition['label'] ?? $definition['admin_label'] ?? NULL) : (method_exists($definition, 'getLabel') ? $definition->getLabel() : NULL);
        $values[(string) $id] = $label === NULL || (string) $label === '' ? (string) $id : $label;
      }
    }
    elseif (is_array($constraints['Choice']['choices'] ?? NULL)) {
      $source = 'Choice';
      foreach ($constraints['Choice']['choices'] as $choice) {
        $values[(string) $choice] = (string) $choice;
      }
    }
    else {
      foreach ($this->typedConfig->getDefinitions() as $name => $definition) {
        $name = (string) $name;
        if (!str_starts_with($name, $expression['prefix']) || !str_ends_with($name, $expression['suffix'])) {
          continue;
        }
        $id = substr($name, strlen($expression['prefix']), strlen($name) - strlen($expression['prefix']) - strlen($expression['suffix']));
        if ($id === '' || str_contains($id, '*') || str_contains($id, '.')) {
          continue;
        }
        $values[$id] = isset($definition['label']) && (string) $definition['label'] !== '' ? (string) $definition['label'] : $id;
      }
    }
    ksort($values);
    $candidates = [];
    foreach ($values as $id => $label) {
      $candidates[(string) $id] = [
        'label' => $label,
        'type' => $this->reader->determine($expression['prefix'] . $id . $expression['suffix']),
      ];
    }
    return [
      'expression' => $named,
      'form' => $expression['form'],
      'by' => $by,
      'source' => $source,
      'less' => $less,
      'candidates' => $candidates,
      'cacheability' => $cacheability,
    ];
  }

}
