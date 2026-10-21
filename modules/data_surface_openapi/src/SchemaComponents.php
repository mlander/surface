<?php

declare(strict_types=1);

namespace Drupal\data_surface_openapi;

use Drupal\data_surface\Contract\ContractEmitter;
use Drupal\data_surface\Contract\SchemaResolver;

/**
 * The `components/schemas` of an OpenAPI document, built from full schemas.
 *
 * Each operation's full schema is one document: a root and its `$defs`.
 * Here every def becomes a component of its own, named by its def id, and
 * the root becomes the operation's values component. A pointer to a def,
 * in a `$ref` or in `x-surface.variants`, is rewritten to point at its
 * component; nothing else in the schema changes, so every other
 * `x-surface` keyword stays where the emitter wrote it.
 *
 * Two operations that describe the same def the same way share one
 * component: a def is the same when its own content and every def it
 * points at are the same. One that differs keeps a component of its own,
 * its name suffixed, and its `title` still its def id. A component name
 * allows letters, digits, `.`, `-` and `_` alone, so any other character
 * of an id (the `:` of a derived resource's) is written `_`.
 *
 * An operation that changes what exists takes a patch: a key left out
 * keeps what is stored, so its request body requires nothing. patch()
 * writes that schema from the full one by dropping every `required` it
 * can drop without making anything stricter: never inside an `if`, a
 * `not`, a `oneOf` or a `contains`, where dropping one would narrow, and
 * never inside a list's `items`, since an item is sent whole.
 *
 * Decision: see docs/decisions.md#an-openapi-document-is-one-more-door.
 */
final class SchemaComponents {

  /**
   * The pointer prefix of a component.
   */
  public const POINTER = '#/components/schemas/';

  /**
   * The suffix of a component's patch.
   */
  public const PATCH = '.patch';

  /**
   * The keywords whose value is a map of subschemas a patch may loosen.
   */
  protected const PATCH_MAPS = ['properties', 'patternProperties', 'dependentSchemas'];

  /**
   * The keywords whose value is a list of subschemas a patch may loosen.
   */
  protected const PATCH_LISTS = ['allOf', 'anyOf'];

  /**
   * The keywords whose value is one subschema a patch may loosen.
   */
  protected const PATCH_ONE = ['then', 'else', 'additionalProperties', 'unevaluatedProperties'];

  /**
   * The schemas, by component name, in the order they were added.
   *
   * @var array<string, array>
   */
  protected array $schemas = [];

  /**
   * The component holding each def's identity.
   *
   * @var array<string, string>
   */
  protected array $identities = [];

  /**
   * Each component's patch, once written.
   *
   * @var array<string, string>
   */
  protected array $patches = [];

  /**
   * Adds one full schema: its defs, then its root.
   *
   * @param array $schema
   *   The full schema, as emitted (ContractEmitter::emitFull()).
   * @param string $name
   *   The name its root is given, before it is made a component name.
   *
   * @return string
   *   The root's component name.
   */
  public function add(array $schema, string $name): string {
    $defs = is_array($schema['$defs'] ?? NULL) ? $schema['$defs'] : [];
    unset($schema['$defs']);
    $identities = [];
    foreach (array_keys($defs) as $key) {
      $this->identity((string) $key, $defs, $identities);
    }
    $names = [];
    foreach (array_keys($defs) as $key) {
      $key = (string) $key;
      $identity = $identities[$key];
      if (!isset($this->identities[$identity])) {
        $this->identities[$identity] = $this->unique(static::name($key));
        // Reserved now, written below once every name is known.
        $this->schemas[$this->identities[$identity]] = [];
      }
      $names[$key] = $this->identities[$identity];
    }
    $root = $this->unique(static::name($name));
    foreach ($defs as $key => $def) {
      $component = $names[(string) $key];
      if ($this->schemas[$component] === []) {
        $this->schemas[$component] = $this->rewritten($def, $names, $root);
      }
    }
    $this->schemas[$root] = $this->rewritten($schema, $names, $root);
    return $root;
  }

  /**
   * Writes a component's patch, and the patches it points at.
   *
   * @param string $name
   *   The component.
   *
   * @return string
   *   The patch's component name.
   */
  public function patch(string $name): string {
    if (isset($this->patches[$name])) {
      return $this->patches[$name];
    }
    $patch = $this->unique($name . self::PATCH);
    $this->patches[$name] = $patch;
    $this->schemas[$patch] = [];
    $this->schemas[$patch] = $this->loosened($this->schemas[$name]);
    return $patch;
  }

  /**
   * Gets every component.
   *
   * @return array<string, array>
   *   The schemas, by component name, sorted by name.
   */
  public function all(): array {
    $schemas = $this->schemas;
    ksort($schemas);
    return $schemas;
  }

  /**
   * Gets one component.
   *
   * @param string $name
   *   The component name.
   *
   * @return array
   *   Its schema.
   */
  public function get(string $name): array {
    return $this->schemas[$name] ?? throw new \InvalidArgumentException(sprintf('No component is named %s.', $name));
  }

  /**
   * Makes an id a component name.
   *
   * @param string $id
   *   The id.
   *
   * @return string
   *   The id, every character a component name does not allow written `_`.
   */
  public static function name(string $id): string {
    return (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $id);
  }

  /**
   * Gives a name no component has yet.
   *
   * @param string $name
   *   The name wanted.
   *
   * @return string
   *   The name, or the name suffixed `-2`, `-3` and so on.
   */
  protected function unique(string $name): string {
    $candidate = $name;
    for ($n = 2; array_key_exists($candidate, $this->schemas); $n++) {
      $candidate = $name . '-' . $n;
    }
    return $candidate;
  }

  /**
   * Gives a def's identity: its content and every def it points at.
   *
   * @param string $key
   *   The def's key under `$defs`.
   * @param array $defs
   *   Every def of its document.
   * @param array<string, string> $identities
   *   The identities found so far, by key; changed in place.
   *
   * @return string
   *   The identity.
   */
  protected function identity(string $key, array $defs, array &$identities): string {
    if (isset($identities[$key])) {
      return $identities[$key];
    }
    $def = $defs[$key];
    $parts = [json_encode($def, JSON_THROW_ON_ERROR)];
    foreach (static::pointers($def) as $pointer) {
      $target = static::defOf($pointer);
      if ($target !== NULL && $target !== $key && isset($defs[$target])) {
        $parts[] = $target . '=' . $this->identity($target, $defs, $identities);
      }
    }
    return $identities[$key] = hash('xxh128', implode("\n", $parts));
  }

  /**
   * Lists every def pointer a schema carries.
   *
   * @param mixed $schema
   *   The schema, or a part of it.
   *
   * @return string[]
   *   Each `$ref`, and each value of an `x-surface.variants` map.
   */
  protected static function pointers(mixed $schema): array {
    if (!is_array($schema)) {
      return [];
    }
    $found = [];
    foreach ($schema as $key => $value) {
      if ($key === '$ref' && is_string($value)) {
        $found[] = $value;
      }
      elseif ($key === 'variants' && is_array($value)) {
        foreach ($value as $pointer) {
          if (is_string($pointer)) {
            $found[] = $pointer;
          }
        }
      }
      else {
        array_push($found, ...static::pointers($value));
      }
    }
    return $found;
  }

  /**
   * Reads the def a local pointer names.
   *
   * @param string $pointer
   *   The pointer.
   *
   * @return string|null
   *   The def's key, or NULL when the pointer names no def.
   */
  protected static function defOf(string $pointer): ?string {
    if (!str_starts_with($pointer, ContractEmitter::DEFS)) {
      return NULL;
    }
    $rest = substr($pointer, strlen(ContractEmitter::DEFS));
    $segment = explode('/', $rest, 2)[0];
    return SchemaResolver::defName(ContractEmitter::DEFS . $segment);
  }

  /**
   * Points every def pointer of a schema at its component.
   *
   * @param mixed $schema
   *   The schema, or a part of it.
   * @param array<string, string> $names
   *   Each def's component, by its key.
   * @param string $root
   *   The root's component, for a pointer into the root.
   *
   * @return mixed
   *   The schema, its pointers rewritten.
   */
  protected function rewritten(mixed $schema, array $names, string $root): mixed {
    if (!is_array($schema)) {
      return $schema;
    }
    foreach ($schema as $key => $value) {
      if ($key === '$ref' && is_string($value)) {
        $schema[$key] = static::moved($value, $names, $root);
      }
      elseif ($key === 'variants' && is_array($value)) {
        foreach ($value as $choice => $pointer) {
          $value[$choice] = is_string($pointer) ? static::moved($pointer, $names, $root) : $pointer;
        }
        $schema[$key] = $value;
      }
      else {
        $schema[$key] = $this->rewritten($value, $names, $root);
      }
    }
    return $schema;
  }

  /**
   * Rewrites one local pointer to point into the components.
   *
   * @param string $pointer
   *   The pointer, local to its document.
   * @param array<string, string> $names
   *   Each def's component, by its key.
   * @param string $root
   *   The root's component.
   *
   * @return string
   *   The pointer into the components; one that is not local, unchanged.
   */
  protected static function moved(string $pointer, array $names, string $root): string {
    $def = static::defOf($pointer);
    if ($def !== NULL && isset($names[$def])) {
      $rest = substr($pointer, strlen(ContractEmitter::DEFS));
      $tail = str_contains($rest, '/') ? substr($rest, strpos($rest, '/')) : '';
      return self::POINTER . $names[$def] . $tail;
    }
    if ($pointer === '#' || str_starts_with($pointer, '#/')) {
      return self::POINTER . $root . substr($pointer, 1);
    }
    return $pointer;
  }

  /**
   * Drops what a patch need not send from a schema.
   *
   * @param array $schema
   *   A schema in a place where dropping `required` only widens.
   *
   * @return array
   *   The schema, with no `required` there, and every component it points
   *   at from there pointed at as its patch.
   */
  protected function loosened(array $schema): array {
    if (isset($schema['required']) && is_array($schema['required']) && array_is_list($schema['required'])) {
      unset($schema['required']);
    }
    if (isset($schema['$ref']) && is_string($schema['$ref']) && str_starts_with($schema['$ref'], self::POINTER)) {
      $target = substr($schema['$ref'], strlen(self::POINTER));
      if (!str_contains($target, '/') && isset($this->schemas[$target])) {
        $schema['$ref'] = self::POINTER . $this->patch($target);
      }
    }
    foreach (self::PATCH_MAPS as $keyword) {
      if (isset($schema[$keyword]) && is_array($schema[$keyword])) {
        foreach ($schema[$keyword] as $key => $sub) {
          $schema[$keyword][$key] = is_array($sub) ? $this->loosenedPart($sub) : $sub;
        }
      }
    }
    foreach (self::PATCH_LISTS as $keyword) {
      if (isset($schema[$keyword]) && is_array($schema[$keyword])) {
        foreach ($schema[$keyword] as $index => $sub) {
          $schema[$keyword][$index] = is_array($sub) ? $this->loosenedPart($sub) : $sub;
        }
      }
    }
    foreach (self::PATCH_ONE as $keyword) {
      if (isset($schema[$keyword]) && is_array($schema[$keyword])) {
        $schema[$keyword] = $this->loosenedPart($schema[$keyword]);
      }
    }
    return $schema;
  }

  /**
   * Drops what a patch need not send from a schema inside another.
   *
   * @param array $schema
   *   The schema.
   *
   * @return array|\stdClass
   *   As loosened() answers; a schema left with nothing in it (a `then`
   *   that only required) is the empty object, which allows anything, so
   *   it is written `{}` and not `[]`.
   */
  protected function loosenedPart(array $schema): array|\stdClass {
    $loosened = $this->loosened($schema);
    return $loosened === [] ? new \stdClass() : $loosened;
  }

}
