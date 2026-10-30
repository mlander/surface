<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\data_surface\DataSurfaceBuilderInterface;

/**
 * The defaults a site sets on a resource's keys, over the declared ones.
 *
 * Read from the `data_surface.defaults` config object: a list of entries,
 * each naming a resource (or a plugin's structure, by the id its host
 * builds it under), a key path from it, and a value written as text, as a
 * form posts it: `monthly`, `250`, `true`, a JSON list `["a","b"]` or a
 * JSON object. A config key may not hold a dot, so the resource id and
 * the path are values, not keys. The build reads the entries of what it
 * builds at the top, reads each value through the coercion table a form's
 * and a caller's values go through, holds it to the key's definition, and
 * writes it as the key's default, so the declared default stays the
 * module's and the site's is the site's. An entry that names no key, or a
 * value the key does not accept, refuses the build, naming the key. Every
 * build carries the config object's cache tag.
 *
 * Decision: see docs/decisions.md#a-site-sets-a-default-in-configuration.
 *
 * @internal
 */
final class SiteDefaults {

  /**
   * The config object the site's defaults are stored in.
   */
  public const CONFIG = 'data_surface.defaults';

  /**
   * Constructs the site's defaults.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The typed data manager, which holds a value to its key's definition.
   */
  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly TypedDataManagerInterface $typedDataManager,
  ) {}

  /**
   * Gets what every build depends on: the config object.
   *
   * @return \Drupal\Core\Cache\CacheableDependencyInterface
   *   The config object, whose tag is `config:data_surface.defaults`.
   */
  public function cacheability(): CacheableDependencyInterface {
    return $this->configFactory->get(self::CONFIG);
  }

  /**
   * Gets the defaults the site sets on one resource, by key path.
   *
   * @param string $resource
   *   The resource's or structure's id.
   *
   * @return array{resource: string, at: string, values: array<string, mixed>}
   *   What write() takes: the resource, the path its keys sit under (none
   *   at the top), and each value as stored, by key path.
   */
  public function of(string $resource): array {
    $values = [];
    $entries = $this->configFactory->get(self::CONFIG)->get('defaults');
    foreach (is_array($entries) ? $entries : [] as $entry) {
      if (is_array($entry) && ($entry['resource'] ?? NULL) === $resource && is_string($entry['key'] ?? NULL) && $entry['key'] !== '') {
        $values[$entry['key']] = $entry['value'] ?? NULL;
      }
    }
    return ['resource' => $resource, 'at' => '', 'values' => $values];
  }

  /**
   * Writes one frame's site defaults onto its keys.
   *
   * A path is a key of the frame, a key of a map it declares (`a.b`), a
   * key an alter mounted (`third_party_settings.<module>.<key>`), or a key
   * of a structure attached at a fixed key, which is handed back for that
   * child's build to write in its own frame (KeyPaths).
   *
   * @param \Drupal\data_surface\DataSurfaceBuilderInterface $builder
   *   The frame's builder, its keys and its alters' declared.
   * @param array{resource: string, at: string, values: array<string, mixed>} $defaults
   *   What of() answered, or what a parent's write() handed this frame.
   * @param array<string, string> $kinds
   *   The frame's keys that hold no plain value (KeyPaths::kinds()).
   *
   * @return array<string, array{resource: string, at: string, values: array<string, mixed>}>
   *   The defaults for each attached child, by its key.
   *
   * @throws \LogicException
   *   When a path names no key a site default reaches, or a value does
   *   not fit its key.
   */
  public function write(DataSurfaceBuilderInterface $builder, array $defaults, array $kinds): array {
    $where = [
      'resource' => $defaults['resource'],
      'at' => $defaults['at'],
      'source' => self::CONFIG,
      'noun' => 'site default',
      'verb' => 'sets',
    ];
    $children = [];
    foreach ((new KeyPaths($this->typedDataManager))->writeDefaults($builder, $where, $defaults['values'], $kinds) as $key => $values) {
      $children[$key] = [
        'resource' => $defaults['resource'],
        'at' => $defaults['at'] . $key . '.',
        'values' => $values,
      ];
    }
    return $children;
  }

}
