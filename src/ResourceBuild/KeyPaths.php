<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\surface\Computed;
use Drupal\surface\SurfaceBuilderInterface;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\DefinitionTypedData;
use Drupal\surface\Pipeline\Coercion;
use Drupal\surface\Pipeline\SurfacePipeline;
use Drupal\surface\Pipeline\ValueState;

/**
 * Finds the key a dotted path names in a frame, and holds a value to it.
 *
 * What a site default (`surface.defaults`), a context's default
 * (OperationContext::withDefaults()) and a context's fixed value (a
 * one-value Choice, OperationContext::withConstraint()) share: the same
 * reach and the same holding. A path is a key of the frame, a key of a map
 * it declares (`a.b`), a key an alter mounted
 * (`third_party_settings.<module>.<key>`), or a key of a structure
 * attached at a fixed key, which its own build reaches. It is never inside
 * variants, a collection's item or a map of keys from the site, never a
 * whole attached structure, a secret or a computed key. A value is read
 * through the coercion table, so text as a form posts it (`40`, `true`, a
 * JSON list or object) and a value already typed (from YAML or PHP) both
 * arrive in the key's type, and held to the key's definition with typed
 * data, its constraints included.
 *
 * Each refusal names where the value came from, the resource, the key and
 * why: `$where` carries `resource` and `at` (the path its keys sit under
 * in the resource, with its trailing dot), `source` (who says it), `noun`
 * (what it is called) and `verb` (`sets`, `fixes`, `starts` for a value a
 * door starts a key from, or `narrows` for any other constraint).
 *
 * A door's own value, one a link or the code building a form starts a key
 * from, is found on the sealed resource instead (reachedOn(), heldOn()),
 * with the same reach, a map with keys of its own named key by key, and
 * the same holding: it never enters a build.
 *
 * Decision: see docs/decisions.md#a-site-sets-a-default-in-configuration.
 * Decision: see
 * docs/decisions.md#a-route-may-fill-or-fix-what-an-operation-asks-for.
 *
 * @internal
 */
final class KeyPaths {

  /**
   * Constructs the key paths.
   *
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The typed data manager, which holds a value to its key's definition.
   */
  public function __construct(
    protected readonly TypedDataManagerInterface $typedDataManager,
  ) {}

  /**
   * Lists a frame's keys that hold no plain value, by key.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $inputs
   *   The frame's blueprint.
   *
   * @return array<string, string>
   *   `child` for a structure attached at a fixed key, `variants`,
   *   `collection`, or `site` for keys from the site.
   */
  public static function kinds(ResourceBlueprint $inputs): array {
    return array_fill_keys(array_map('strval', array_keys($inputs->attachments())), 'child')
      + array_fill_keys(array_map('strval', array_keys($inputs->variants())), 'variants')
      + array_fill_keys(array_map('strval', array_keys($inputs->lists())), 'collection')
      + array_fill_keys(array_map('strval', array_keys($inputs->fromSite())), 'site');
  }

  /**
   * Writes one frame's defaults onto its keys.
   *
   * @param \Drupal\surface\SurfaceBuilderInterface $builder
   *   The frame's builder, its keys and its alters' declared.
   * @param array{resource: string, at: string, source: string, noun: string, verb: string} $where
   *   Where the values come from, for the refusals.
   * @param array<string, mixed> $values
   *   Each value, by key path.
   * @param array<string, string> $kinds
   *   The frame's keys that hold no plain value (kinds()).
   *
   * @return array<string, array<string, mixed>>
   *   The values for each child attached at a fixed key, by its key, each
   *   by its path in the child.
   *
   * @throws \LogicException
   *   When a path names no key a default reaches, or a value does not fit
   *   its key.
   */
  public function writeDefaults(SurfaceBuilderInterface $builder, array $where, array $values, array $kinds): array {
    $children = [];
    foreach ($values as $path => $raw) {
      $path = (string) $path;
      $segments = explode('.', $path);
      $first = $segments[0];
      if (($kinds[$first] ?? NULL) === 'child') {
        if (count($segments) === 1) {
          throw $this->refusal('%s: "%s" on %s holds a structure: set each of its keys, as "%s.<key>".', $where['source'], $where['at'] . $path, $where['resource'], $where['at'] . $path);
        }
        $children[$first][implode('.', array_slice($segments, 1))] = $raw;
        continue;
      }
      $this->assertReached($where, $path, $kinds);
      [$definition, $ancestors] = $this->resolve($builder, $segments, $where, $path);
      $this->setDefault($definition, $ancestors, $this->held($definition, $raw, $where, $path));
    }
    return $children;
  }

  /**
   * Refuses a path inside a key a value at a path does not reach.
   *
   * @param array{resource: string, at: string, source: string, noun: string, verb: string} $where
   *   Where the value comes from.
   * @param string $path
   *   The path.
   * @param array<string, string> $kinds
   *   The frame's keys that hold no plain value (kinds()).
   *
   * @throws \LogicException
   *   When the path's first key holds variants, a collection or keys from
   *   the site.
   */
  public function assertReached(array $where, string $path, array $kinds): void {
    $first = explode('.', $path)[0];
    $kind = $kinds[$first] ?? NULL;
    if ($kind === NULL || $kind === 'child') {
      return;
    }
    throw $this->refusal(
      '%s: "%s" on %s is inside "%s", %s, so a %s does not reach it.',
      $where['source'],
      $where['at'] . $path,
      $where['resource'],
      $where['at'] . $first,
      match ($kind) {
        'variants' => 'a key with variants, each starting from its own defaults',
        'collection' => 'a collection, each item starting from its structure\'s defaults',
        default => 'a map of keys from the site, which its provider starts',
      },
      $where['noun'],
    );
  }

  /**
   * Finds the definition a path names in a frame.
   *
   * @param \Drupal\surface\SurfaceBuilderInterface $builder
   *   The frame's builder.
   * @param string[] $segments
   *   The path, split.
   * @param array{resource: string, at: string, source: string, noun: string, verb: string} $where
   *   Where the value comes from, for the refusal.
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
  public function resolve(SurfaceBuilderInterface $builder, array $segments, array $where, string $path): array {
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
        throw $where['verb'] === 'sets'
          ? $this->refusal('%s: "%s" on %s is inside a list, whose default is set whole: name the list and write it as JSON, ["a","b"].', $where['source'], $where['at'] . $path, $where['resource'])
          : $this->refusal('%s: "%s" on %s is inside a list, which a %s does not reach.', $where['source'], $where['at'] . $path, $where['resource'], $where['noun']);
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
      throw $this->noKey($where, $path);
    }
    return [$definition, $ancestors];
  }

  /**
   * Makes the refusal of a path that names no key.
   *
   * @param array{resource: string, at: string, source: string, noun: string, verb: string} $where
   *   Where the value comes from.
   * @param string $path
   *   The path.
   *
   * @return \LogicException
   *   The refusal.
   */
  public function noKey(array $where, string $path): \LogicException {
    return $this->refusal('%s: %s has no key "%s". A %s names a key the resource declares, a key inside a map it declares or a structure it attaches (contact.phone), or a key an alter mounts (third_party_settings.<module>.<key>).', $where['source'], $where['resource'], $where['at'] . $path, $where['noun']);
  }

  /**
   * Writes a key's default, and into each map above it that holds one.
   *
   * A map that declares a default holding the key would mask the key's
   * own, so the value is written there too.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition.
   * @param list<array{0: \Drupal\Core\TypedData\DataDefinitionInterface, 1: string}> $ancestors
   *   Each map above it, with the name it holds the next one under
   *   (resolve()).
   * @param mixed $value
   *   The value, in the key's type.
   */
  public function setDefault(DataDefinitionInterface $definition, array $ancestors, mixed $value): void {
    DefinitionMetadata::setDefaultValue($definition, $value);
    $below = [];
    foreach (array_reverse($ancestors) as [$ancestor, $name]) {
      array_unshift($below, $name);
      if (DefinitionMetadata::hasDefaultValue($ancestor) && is_array($held = DefinitionMetadata::getDefaultValue($ancestor)) && NestedArray::keyExists($held, $below)) {
        NestedArray::setValue($held, $below, $value);
        DefinitionMetadata::setDefaultValue($ancestor, $held);
      }
    }
  }

  /**
   * Reads a value through the coercion table and holds it to its key.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition.
   * @param mixed $raw
   *   The value as given: text, as a form posts it, or already typed.
   * @param array{resource: string, at: string, source: string, noun: string, verb: string} $where
   *   Where the value comes from, for the refusal.
   * @param string $path
   *   The path, for the refusal.
   *
   * @return mixed
   *   The value, in the key's type.
   *
   * @throws \LogicException
   *   When the key takes no such value: a secret, a computed key, or a
   *   list or a map fixed.
   * @throws \Drupal\surface\ResourceBuild\ValueNotAcceptedException
   *   When the value does not fit the key.
   */
  public function held(DataDefinitionInterface $definition, mixed $raw, array $where, string $path): mixed {
    $fixing = $where['verb'] === 'fixes';
    if (DefinitionMetadata::isSecret($definition)) {
      throw $fixing
        ? $this->refusal('%s: "%s" on %s is a secret: both documents state a fixed value as `const`, and a secret is never read back, so it cannot be fixed.', $where['source'], $where['at'] . $path, $where['resource'])
        : $this->refusal('%s: "%s" on %s is a secret: both documents state a default, and a secret is never read back, so it takes none.', $where['source'], $where['at'] . $path, $where['resource']);
    }
    $computed = Computed::of($definition);
    if ($computed !== NULL) {
      throw $this->refusal('%s: "%s" on %s is computed from %s, so it takes no %s.', $where['source'], $where['at'] . $path, $where['resource'], implode(', ', $computed->from), $fixing ? 'fixed value' : 'default');
    }
    if ($fixing && ($definition instanceof ListDataDefinitionInterface || $definition instanceof ComplexDataDefinitionInterface)) {
      throw $this->refusal('%s: "%s" on %s holds %s, and a fixed value is one value of a key that holds one: fix each key %s.', $where['source'], $where['at'] . $path, $where['resource'], $definition instanceof ListDataDefinitionInterface ? 'a list' : 'a map', $definition instanceof ListDataDefinitionInterface ? 'of a key that holds one' : 'inside it by its path');
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
      // The value is wrong, and where it was given is not.
      throw new ValueNotAcceptedException(sprintf(
        '%s %s "%s" on %s to %s, which the key does not accept: %s',
        $where['source'],
        $where['verb'],
        $where['at'] . $path,
        $where['resource'],
        (string) json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        $problem,
      ));
    }
    return $value;
  }

  /**
   * Finds the key a path names on a built resource, for a door's own value.
   *
   * What a form starts a key from that no build was handed: a value a link
   * starts (a route's `_surface_query`), or one the code building the
   * form hands it. The reach is a default's (assertReached()), read off the
   * sealed resource rather than its builder: a key the resource declares, a
   * key inside a map it declares, a key an alter mounts
   * (`third_party_settings.<module>.<key>`), and a key of a structure
   * attached at a fixed key or at an alter's mount, found in that
   * structure's own frame; never inside variants, a collection's item or a
   * map of keys from the site, never a secret or a computed key, and never
   * an identity key the context knows, which is the operation's to say. A
   * value is one key's: a structure, or a map with keys of its own, is
   * named key by key, since a value laid over it whole would replace what
   * the form is drawn with for each key inside it that the value leaves
   * out, a stored one among them.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The built resource.
   * @param string $path
   *   The key's path.
   * @param array{resource: string, at: string, source: string, noun: string, verb: string} $where
   *   Where the value comes from, for the refusals.
   * @param string[] $identity
   *   The resource's identity keys its context knows.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The key's definition; NULL when the build fixes or locks the key, or
   *   a frame holding it, so what the build says stands and nothing
   *   starts it.
   *
   * @throws \LogicException
   *   When the path names no key such a value reaches, naming where the
   *   value came from and the key.
   */
  public function reachedOn(SurfaceInterface $surface, string $path, array $where, array $identity = []): ?DataDefinitionInterface {
    $segments = explode('.', $path);
    $frame = $surface;
    $depth = 0;
    $settled = FALSE;
    $definition = NULL;
    while ($definition === NULL) {
      $settled = $settled || $frame->lockedWhole() !== NULL;
      $first = $segments[$depth];
      $entry = $frame->getDefinitions()->entry($first);
      if ($entry === NULL) {
        throw $this->noKey($where, $path);
      }
      $kind = match (TRUE) {
        $entry->variants !== NULL => 'variants',
        $entry->list !== NULL => 'collection',
        DefinitionMetadata::eachOf($entry->definition) !== NULL => 'site',
        $entry->attachment !== NULL => 'child',
        default => NULL,
      };
      if ($kind !== NULL && $kind !== 'child') {
        // Named by its whole path, from the frame it sits in.
        $within = $where;
        $within['at'] .= $depth === 0 ? '' : implode('.', array_slice($segments, 0, $depth)) . '.';
        $this->assertReached($within, implode('.', array_slice($segments, $depth)), [$first => $kind]);
      }
      $settled = $settled || $entry->locked;
      if ($entry->attachment !== NULL) {
        if (count($segments) === $depth + 1) {
          throw $this->refusal('%s: "%s" on %s holds a structure: name each of its keys, as "%s.<key>".', $where['source'], $where['at'] . $path, $where['resource'], $where['at'] . $path);
        }
        // The structure's own frame, which may fix or lock its keys.
        $frame = $entry->attachment->child;
        $depth++;
        continue;
      }
      if ($depth === 0 && in_array($first, $identity, TRUE)) {
        throw $this->refusal('%s: "%s" on %s is an identity key the context knows: identity is the operation\'s to say, from its parameters, so no %s starts it.', $where['source'], $where['at'] . $path, $where['resource'], $where['noun']);
      }
      if ($first === 'third_party_settings') {
        if (count($segments) < $depth + 3) {
          // The mount, and each module's map in it, only group the keys
          // alters add: a value names one by its module and its name, as a
          // default does (resolve()).
          throw $this->noKey($where, $path);
        }
        $mounted = $entry->mounted[$segments[$depth + 1]] ?? NULL;
        if ($mounted !== NULL) {
          // The structure an alter attached at its mount, in its own
          // frame, which may fix or lock its keys.
          $frame = $mounted->child;
          $depth += 2;
          continue;
        }
      }
      $at = $entry->definition;
      foreach (array_slice($segments, $depth + 1) as $segment) {
        if ($at instanceof ListDataDefinitionInterface) {
          throw $this->refusal('%s: "%s" on %s is inside a list, which a %s does not reach.', $where['source'], $where['at'] . $path, $where['resource'], $where['noun']);
        }
        $at = $at instanceof ComplexDataDefinitionInterface ? $at->getPropertyDefinition($segment) : NULL;
        if ($at === NULL) {
          throw $this->noKey($where, $path);
        }
      }
      $definition = $at;
    }
    if (DefinitionMetadata::isSecret($definition)) {
      throw $this->refusal('%s: "%s" on %s is a secret, which no form draws, so no %s starts it.', $where['source'], $where['at'] . $path, $where['resource'], $where['noun']);
    }
    $computed = Computed::of($definition);
    if ($computed !== NULL) {
      throw $this->refusal('%s: "%s" on %s is computed from %s, so no %s starts it.', $where['source'], $where['at'] . $path, $where['resource'], implode(', ', $computed->from), $where['noun']);
    }
    if ($definition instanceof ComplexDataDefinitionInterface && !$definition instanceof ListDataDefinitionInterface && $definition->getPropertyDefinitions() !== []) {
      throw $this->refusal('%s: "%s" on %s holds a map: name each of its keys, as "%s.<key>".', $where['source'], $where['at'] . $path, $where['resource'], $where['at'] . $path);
    }
    return $settled || DefinitionMetadata::isFixed($definition) ? NULL : $definition;
  }

  /**
   * Holds the values a door starts keys from to a built resource, by path.
   *
   * Every path is found first (reachedOn()), whether a value came for it or
   * not, so one written wrong is refused on every request; then each value
   * is read through the coercion table and held to its key (held()). What
   * the build fixes or locks, a value that is nothing (NULL or the empty
   * string), and a value its key refuses are left out, so the key starts
   * from what is stored or declared, and nothing is said of it.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The built resource.
   * @param array<string, mixed> $values
   *   Each value as given, NULL where none came, by key path.
   * @param array{resource: string, at: string, source: string, noun: string, verb: string} $where
   *   Where the values come from, for the refusals.
   * @param string[] $identity
   *   The resource's identity keys its context knows.
   *
   * @return array<string, mixed>
   *   Each value its key takes, in the key's type, by key path.
   *
   * @throws \LogicException
   *   When a path names no key such a value reaches.
   */
  public function heldOn(SurfaceInterface $surface, array $values, array $where, array $identity = []): array {
    $held = [];
    foreach ($values as $path => $raw) {
      $path = (string) $path;
      $definition = $this->reachedOn($surface, $path, $where, $identity);
      if ($definition === NULL || !ValueState::isConfigured($raw)) {
        continue;
      }
      try {
        $held[$path] = $this->held($definition, $raw, $where, $path);
      }
      catch (ValueNotAcceptedException) {
        // A value its key refuses starts nothing.
      }
    }
    return $held;
  }

  /**
   * Names the key path of each value a values array holds for a resource.
   *
   * The values in the resource's own shape, nested as a door reads and
   * writes them: a structure attached at a fixed key and a map with keys
   * of its own (the mount of alters' keys among them) hold a value per key
   * inside, each named by its path; any other key holds its value whole. A
   * key nothing declares, and a structure or a map given anything but an
   * array, is named as it is given, for reachedOn() to refuse.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The built resource, or a structure attached in it.
   * @param array $values
   *   The values.
   * @param string $prefix
   *   The path the frame's keys sit under, with its trailing dot.
   *
   * @return array<string, mixed>
   *   Each value, by key path.
   */
  public static function pathsOf(SurfaceInterface $surface, array $values, string $prefix = ''): array {
    $paths = [];
    foreach ($values as $key => $value) {
      $key = (string) $key;
      $entry = $surface->getDefinitions()->entry($key);
      $child = $entry !== NULL && $entry->variants === NULL && $entry->list === NULL && DefinitionMetadata::eachOf($entry->definition) === NULL ? $entry->attachment : NULL;
      $paths += match (TRUE) {
        $child !== NULL && is_array($value) => static::pathsOf($child->child, $value, $prefix . $key . '.'),
        $entry !== NULL && $entry->attachment === NULL && $entry->variants === NULL && $entry->list === NULL => static::mapPaths($entry->definition, $value, $prefix . $key),
        default => [$prefix . $key => $value],
      };
    }
    return $paths;
  }

  /**
   * Names the key path of each value a map's value holds, by its keys.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition.
   * @param mixed $value
   *   Its value.
   * @param string $path
   *   Its path.
   *
   * @return array<string, mixed>
   *   The value whole, by the path, for a key with no keys of its own or a
   *   value that is no array; else each value inside, by its path.
   */
  protected static function mapPaths(DataDefinitionInterface $definition, mixed $value, string $path): array {
    $properties = $definition instanceof ComplexDataDefinitionInterface && !$definition instanceof ListDataDefinitionInterface ? $definition->getPropertyDefinitions() : [];
    if ($properties === [] || !is_array($value)) {
      return [$path => $value];
    }
    $paths = [];
    foreach ($value as $name => $inner) {
      $property = $properties[(string) $name] ?? NULL;
      $paths += $property === NULL ? [$path . '.' . $name => $inner] : static::mapPaths($property, $inner, $path . '.' . $name);
    }
    return $paths;
  }

  /**
   * Reads a value in its key's shape, by the coercion table.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition.
   * @param mixed $raw
   *   The value as given.
   *
   * @return mixed
   *   The value, coerced where the table reads it unambiguously.
   *
   * @throws \InvalidArgumentException
   *   When a map is not an object, or names a key it does not declare, or
   *   a list or a map is given for a key that holds one value.
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
      if (is_array($raw) && in_array($definition->getDataType(), SurfacePipeline::SCALAR_TYPES, TRUE)) {
        // Refused before any constraint reads it, as the pipeline refuses
        // it (Accepting::coerce()): a validator that reads one value
        // throws for an array rather than saying it does not fit.
        throw new \InvalidArgumentException(sprintf('it holds one %s, not a list or a map.', $definition->getDataType()));
      }
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
   * Makes the refusal of one value.
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
