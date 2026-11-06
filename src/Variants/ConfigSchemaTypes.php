<?php

declare(strict_types=1);

namespace Drupal\surface\Variants;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\surface\Reader\ConfigSchemaReader;
use Drupal\surface\Resource\Attribute\UsesStructure;
use Drupal\surface\Resource\BoundSiteListInterface;
use Drupal\surface\Resource\SiteList;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Variants from a config schema type named through another key.
 *
 * Bound by keyed arguments: `type`, a schema type, and `path`, a dotted
 * path into it, the value whose type is written `prefix.[%parent.<key>]`
 * (or `prefix.[<key>]`); or, for `prefix.[<key>]`, `type` the expression
 * itself and no path, which names it wherever it is used:
 *
 * @code
 * $inputs->attachVariants('settings', by: 'type', from: ConfigSchemaTypes::class, arguments: ['type' => 'field.storage.*.*', 'path' => 'settings', 'by' => 'type']);
 * $inputs->mixInVariants('plugin_id', from: ConfigSchemaTypes::class, arguments: ['type' => 'views.field.[plugin_id]']);
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
 * Where the values are a plugin manager's, a plugin whose class names its
 * settings with #[UsesStructure] is that structure, built as declared, and
 * its schema is not read: the plugin's definition carries the class
 * (UsesStructure::DEFINITION_KEY), so no plugin is instantiated. Any other
 * value is read from its schema. A #[Variant] declared for the owner wins
 * over both, as for any provider. Values from a Choice or from the schema
 * names have no plugin definition, and are read from the schema alone.
 *
 * A `by` argument, optional, restates the deciding key: listFor() is
 * handed the arguments alone, not the key they are bound at, so a binding
 * written by hand says which key it expects, and the list is refused when
 * the expression found is decided by another key. Without it nothing is
 * checked: the reader binds what the schema names, and the check would
 * compare the key with itself.
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
   * @param array $arguments
   *   By name, checked here since an owner may bind any array: `type`,
   *   the schema type, or an own-key expression as written; `path`, the
   *   dotted path of the value in it, absent or empty for an expression;
   *   `by`, the key that decides it, absent for no check.
   *
   * @throws \LogicException
   *   When the arguments hold a key by position or name no type, when
   *   they hold a key other than the three, when the path names no value
   *   typed through another key, or one that key is not the deciding key
   *   named.
   */
  public function listFor(array $arguments): SiteList {
    if (!isset($arguments['type']) || array_filter(array_keys($arguments), 'is_int') !== []) {
      throw new \LogicException(sprintf('ConfigSchemaTypes takes its arguments by name, arguments: [\'type\' => \'block.block.*\', \'path\' => \'settings\'] or [\'type\' => \'condition.plugin.[id]\'], with \'by\' => <key> to check the deciding key; given %s.', json_encode($arguments, JSON_UNESCAPED_SLASHES)));
    }
    $unknown = array_diff_key($arguments, array_flip(['type', 'path', 'by']));
    if ($unknown !== []) {
      throw new \LogicException(sprintf('ConfigSchemaTypes takes the arguments type, path and by; given %s.', implode(', ', array_keys($unknown))));
    }
    $type = (string) $arguments['type'];
    $path = (string) ($arguments['path'] ?? '');
    $by = (string) ($arguments['by'] ?? '');
    $resolved = $this->resolve($type, $path);
    if ($by !== '' && $resolved['by'] !== $by) {
      throw new \LogicException(sprintf('ConfigSchemaTypes lists the variants of %s for "%s", and the schema types it by "%s" (%s): the variants are chosen by the key the schema names.', $type . ($path === '' ? '' : ' at ' . $path), $by, $resolved['by'], $resolved['expression']));
    }
    $list = (new SiteList())->addCacheableDependency($resolved['cacheability']);
    foreach ($resolved['candidates'] as $id => ['label' => $label, 'type' => $chosen, 'structure' => $declared]) {
      if ($declared !== NULL) {
        $list->add((string) $id, $label, structure: $declared);
        continue;
      }
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
   * @return array{expression: string, form: string, by: string, source: string, less: string, candidates: array<string, array{label: string|\Stringable, type: string|null, structure: string|null}>, cacheability: \Drupal\Core\Cache\CacheableMetadata}
   *   The type as written; its form; the deciding key; where the values
   *   came from; the type whose keys each variant leaves out (for
   *   `[<key>]`); each value's label, the type it resolves to (NULL for
   *   none, which typed config reads as undefined) and the structure its
   *   plugin declares (NULL for none); and what was read.
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
    $declared = [];
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
        $structure = is_array($definition) ? ($definition[UsesStructure::DEFINITION_KEY] ?? NULL) : NULL;
        if (is_string($structure) && $structure !== '') {
          $declared[(string) $id] = $structure;
        }
      }
    }
    elseif (is_array($constraints['Choice']['choices'] ?? NULL)) {
      $source = 'Choice';
      foreach ($constraints['Choice']['choices'] as $choice) {
        $values[(string) $choice] = (string) $choice;
      }
    }
    else {
      $definitions = $this->typedConfig->getDefinitions();
      foreach ($this->reader->typesNamed($expression) as $id => $name) {
        $label = $definitions[$name]['label'] ?? NULL;
        $values[$id] = $label !== NULL && (string) $label !== '' ? (string) $label : (string) $id;
      }
    }
    ksort($values);
    $candidates = [];
    foreach ($values as $id => $label) {
      $candidates[(string) $id] = [
        'label' => $label,
        'type' => $this->reader->determine($expression['prefix'] . $id . $expression['suffix']),
        'structure' => $declared[(string) $id] ?? NULL,
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
