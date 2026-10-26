<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\data_surface\Computed;
use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\DefinitionTypedData;
use Drupal\data_surface\Pipeline\Coercion;
use Drupal\data_surface\Pipeline\ValueState;

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
   * child's build to write in its own frame.
   *
   * @param \Drupal\data_surface\DataSurfaceBuilderInterface $builder
   *   The frame's builder, its keys and its alters' declared.
   * @param array{resource: string, at: string, values: array<string, mixed>} $defaults
   *   What of() answered, or what a parent's write() handed this frame.
   * @param array<string, string> $kinds
   *   The frame's keys that hold no plain value, by key: `child` for a
   *   structure attached at a fixed key, `variants`, `collection` or
   *   `site` for keys from the site.
   *
   * @return array<string, array{resource: string, at: string, values: array<string, mixed>}>
   *   The defaults for each attached child, by its key.
   *
   * @throws \LogicException
   *   When a path names no key a site default reaches, or a value does
   *   not fit its key.
   */
  public function write(DataSurfaceBuilderInterface $builder, array $defaults, array $kinds): array {
    $children = [];
    foreach ($defaults['values'] as $path => $raw) {
      $path = (string) $path;
      $segments = explode('.', $path);
      $first = $segments[0];
      $kind = $kinds[$first] ?? NULL;
      if ($kind === 'child') {
        if (count($segments) === 1) {
          throw $this->refusal('%s: "%s" on %s holds a structure: set each of its keys, as "%s.<key>".', self::CONFIG, $defaults['at'] . $path, $defaults['resource'], $defaults['at'] . $path);
        }
        $children[$first] ??= [
          'resource' => $defaults['resource'],
          'at' => $defaults['at'] . $first . '.',
          'values' => [],
        ];
        $children[$first]['values'][implode('.', array_slice($segments, 1))] = $raw;
        continue;
      }
      if ($kind !== NULL) {
        throw $this->refusal(
          '%s: "%s" on %s is inside "%s", %s, so a site default does not reach it.',
          self::CONFIG,
          $defaults['at'] . $path,
          $defaults['resource'],
          $defaults['at'] . $first,
          match ($kind) {
            'variants' => 'a key with variants, each starting from its own defaults',
            'collection' => 'a collection, each item starting from its structure\'s defaults',
            default => 'a map of keys from the site, which its provider starts',
          },
        );
      }
      [$definition, $ancestors] = $this->resolve($builder, $segments, $defaults, $path);
      $value = $this->held($definition, $raw, $defaults, $path);
      DefinitionMetadata::setDefaultValue($definition, $value);
      // A map that declares a default holding the key would mask the
      // key's own: the site's is written there too.
      $below = [];
      foreach (array_reverse($ancestors) as [$ancestor, $name]) {
        array_unshift($below, $name);
        if (DefinitionMetadata::hasDefaultValue($ancestor) && is_array($held = DefinitionMetadata::getDefaultValue($ancestor)) && NestedArray::keyExists($held, $below)) {
          NestedArray::setValue($held, $below, $value);
          DefinitionMetadata::setDefaultValue($ancestor, $held);
        }
      }
    }
    return $children;
  }

  /**
   * Finds the definition a path names in a frame.
   *
   * @param \Drupal\data_surface\DataSurfaceBuilderInterface $builder
   *   The frame's builder.
   * @param string[] $segments
   *   The path, split.
   * @param array{resource: string, at: string, values: array<string, mixed>} $defaults
   *   The frame's defaults, for the refusal.
   * @param string $path
   *   The path, for the refusal.
   *
   * @return array{0: \Drupal\Core\TypedData\DataDefinitionInterface, 1: list<array{0: \Drupal\Core\TypedData\DataDefinitionInterface, 1: string}>}
   *   The definition, and each map above it with the name it holds the
   *   next one under.
   *
   * @throws \LogicException
   *   When the path names no key, or names an item of a list.
   */
  protected function resolve(DataSurfaceBuilderInterface $builder, array $segments, array $defaults, string $path): array {
    $definition = NULL;
    $rest = $segments;
    if ($segments[0] === 'third_party_settings' && count($segments) >= 3) {
      $definition = $builder->getThirdPartyDefinition($segments[1], $segments[2]);
      $rest = array_slice($segments, 3);
    }
    else {
      $definition = $builder->getDefinition($segments[0]);
      $rest = array_slice($segments, 1);
    }
    $ancestors = [];
    foreach ($rest as $segment) {
      if ($definition instanceof ListDataDefinitionInterface) {
        throw $this->refusal('%s: "%s" on %s is inside a list, whose default is set whole: name the list and write it as JSON, ["a","b"].', self::CONFIG, $defaults['at'] . $path, $defaults['resource']);
      }
      $property = $definition instanceof ComplexDataDefinitionInterface ? $definition->getPropertyDefinition($segment) : NULL;
      if ($property === NULL) {
        $definition = NULL;
        break;
      }
      $ancestors[] = [$definition, $segment];
      $definition = $property;
    }
    if ($definition === NULL) {
      throw $this->refusal('%s: %s has no key "%s". A site default names a key the resource declares, a key inside a map it declares or a structure it attaches (contact.phone), or a key an alter mounts (third_party_settings.<module>.<key>).', self::CONFIG, $defaults['resource'], $defaults['at'] . $path);
    }
    return [$definition, $ancestors];
  }

  /**
   * Reads a site's value through the coercion table and holds it to its key.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition.
   * @param mixed $raw
   *   The value as stored: text, as a form posts it.
   * @param array{resource: string, at: string, values: array<string, mixed>} $defaults
   *   The frame's defaults, for the refusal.
   * @param string $path
   *   The path, for the refusal.
   *
   * @return mixed
   *   The value, in the key's type.
   *
   * @throws \LogicException
   *   When the key takes no default, or the value does not fit it.
   */
  protected function held(DataDefinitionInterface $definition, mixed $raw, array $defaults, string $path): mixed {
    if (DefinitionMetadata::isSecret($definition)) {
      throw $this->refusal('%s: "%s" on %s is a secret: both documents state a default, and a secret is never read back, so it takes none.', self::CONFIG, $defaults['at'] . $path, $defaults['resource']);
    }
    $computed = Computed::of($definition);
    if ($computed !== NULL) {
      throw $this->refusal('%s: "%s" on %s is computed from %s, so it takes no default.', self::CONFIG, $defaults['at'] . $path, $defaults['resource'], implode(', ', $computed->from));
    }
    $value = NULL;
    $problem = NULL;
    try {
      $value = $this->accepted($definition, $raw);
      $problem = $this->violation($definition, $value);
    }
    catch (\InvalidArgumentException $e) {
      $problem = $e->getMessage();
    }
    if ($problem !== NULL) {
      throw $this->refusal(
        '%s sets "%s" on %s to %s, which the key does not accept: %s',
        self::CONFIG,
        $defaults['at'] . $path,
        $defaults['resource'],
        (string) json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        $problem,
      );
    }
    return $value;
  }

  /**
   * Reads a stored value in its key's shape, by the coercion table.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition.
   * @param mixed $raw
   *   The value as stored.
   *
   * @return mixed
   *   The value, coerced where the table reads it unambiguously.
   *
   * @throws \InvalidArgumentException
   *   When a map is not an object, or names a key it does not declare.
   */
  protected function accepted(DataDefinitionInterface $definition, mixed $raw): mixed {
    if (!ValueState::isConfigured($raw)) {
      return NULL;
    }
    if ($definition instanceof ListDataDefinitionInterface) {
      $items = Coercion::decoded($raw, TRUE);
      $values = [];
      foreach (is_array($items) ? $items : [$items] as $item) {
        if (ValueState::isConfigured($item)) {
          $values[] = $this->accepted($definition->getItemDefinition(), $item);
        }
      }
      return $values;
    }
    $properties = $definition instanceof ComplexDataDefinitionInterface ? $definition->getPropertyDefinitions() : [];
    if ($properties === []) {
      return is_array($raw) ? $raw : Coercion::scalar($definition, $raw);
    }
    $map = Coercion::decoded($raw, FALSE);
    if (!is_array($map)) {
      throw new \InvalidArgumentException('it holds a map, written as a JSON object.');
    }
    $unknown = array_diff(array_map('strval', array_keys($map)), array_map('strval', array_keys($properties)));
    if ($unknown !== []) {
      throw new \InvalidArgumentException(sprintf('it declares no key "%s".', reset($unknown)));
    }
    $values = [];
    foreach ($map as $name => $value) {
      $values[$name] = $this->accepted($properties[$name], $value);
    }
    return $values;
  }

  /**
   * Holds a value to its key's definition, as the pipeline would.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition.
   * @param mixed $value
   *   The value, coerced.
   *
   * @return string|null
   *   The first violation's message, or NULL when the value fits.
   */
  protected function violation(DataDefinitionInterface $definition, mixed $value): ?string {
    $properties = $definition instanceof ComplexDataDefinitionInterface && !$definition instanceof ListDataDefinitionInterface ? $definition->getPropertyDefinitions() : [];
    if ($properties !== [] && is_array($value)) {
      // A map is held key by key: what it leaves out starts from its
      // keys' own defaults.
      foreach ($value as $name => $held) {
        $problem = $this->violation($properties[$name], $held);
        if ($problem !== NULL) {
          return $problem;
        }
      }
      return NULL;
    }
    if (is_array($value) && $definition->isRequired() && method_exists($definition, 'setRequired')) {
      // Required means configured, and the empty list is, as the pipeline
      // reads it; core's NotNull reads it as null.
      // Decision: see docs/decisions.md#required-means-must-hold-a-value.
      $definition = (clone $definition)->setRequired(FALSE);
    }
    foreach (DefinitionTypedData::create($this->typedDataManager, $definition, $value)->validate() as $violation) {
      $at = (string) $violation->getPropertyPath();
      return ($at === '' ? '' : $at . ': ') . strip_tags((string) $violation->getMessage());
    }
    return NULL;
  }

  /**
   * Makes the refusal of one entry.
   *
   * @param string $format
   *   The message, its arguments filled by sprintf().
   * @param string ...$arguments
   *   The arguments.
   *
   * @return \LogicException
   *   The refusal.
   */
  protected function refusal(string $format, string ...$arguments): \LogicException {
    return new \LogicException(vsprintf($format, $arguments));
  }

}
