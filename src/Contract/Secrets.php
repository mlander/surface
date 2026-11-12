<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use PortableSchema\Builder\Keywords;
use PortableSchema\Builder\Schema;
use PortableSchema\Registry;

/**
 * Answers what is secret, from a schema node or a document.
 *
 * A value is secret where a schema that applies to it says
 * `writeOnly: true`. That is the one meaning of the keyword: a secret is
 * never handed back, never shown, and a refusal about it says nothing of
 * what it holds. This class reads schemas only; it holds no definition.
 *
 * What applies to a value: the schema at its place, and every schema that
 * schema brings in whole, through `$ref` (any JSON pointer into the same
 * document, such as `#/$defs/a/$defs/b`, or a type of the library for a
 * `portable:` id), `allOf`, `anyOf`, `oneOf`, `then`, `else` and each
 * of `dependentSchemas`. A schema that applies in any branch counts, so
 * a place is secret where any branch says so. A value inside is reached
 * through `properties`, `patternProperties` (each pattern the name
 * matches), `additionalProperties`, `items` and `prefixItems`. `if`, `not`
 * and `contains` are not entered: each tests a value, and none describes
 * the place. Nothing that holds data is entered: `default`, `examples`,
 * `const` and `enum` say no place.
 *
 * An answer leans to secret where the schema cannot be read: a `$ref` that
 * resolves to nothing (a pointer to no schema, an id the registry does
 * not hold) is taken as a secret at its place.
 *
 * A served document holds only what it states: a variant it does not
 * carry (deferred, or left out of a document narrowed to the chosen one)
 * is not in it, so code that needs every variant's secrets reads built
 * nodes, never a served document.
 *
 * Decision: see
 * docs/decisions.md#a-shape-is-a-schema-secrecy-is-read-from-a-node.
 */
final class Secrets {

  /**
   * The key of a secrets tree that holds what is secret inside each item.
   *
   * In the "@" namespace, which no definition is named in.
   */
  public const EACH = '@each';

  /**
   * How deep within() walks below a document's root.
   *
   * A def may hold itself, through a list of itself; past this depth a
   * place is taken to hold no secret.
   */
  protected const DEPTH = 64;

  /**
   * The keywords whose schemas apply to the whole value of their parent.
   */
  protected const WHOLE = ['allOf', 'anyOf', 'oneOf', 'then', 'else', 'dependentSchemas'];

  /**
   * A schema that says secret, taken where a `$ref` cannot be resolved.
   */
  private static ?Schema $unread = NULL;

  /**
   * The library's documents, read once each, by registry and id.
   *
   * @var \WeakMap<\PortableSchema\Registry, array<string, \PortableSchema\Builder\Schema>>|null
   */
  private static ?\WeakMap $types = NULL;

  /**
   * The type library, for a call with no container.
   */
  private static ?Registry $library = NULL;

  /**
   * Answers whether a node itself says its value is secret.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The node.
   *
   * @return bool
   *   TRUE where it says `writeOnly: true`.
   */
  public static function marked(Schema $node): bool {
    return !$node->isBoolean() && $node->get('writeOnly') === TRUE;
  }

  /**
   * Answers whether the value at a place in a document is secret.
   *
   * Secret where any schema that applies at the place is marked(), as the
   * class docblock says what applies, and where a `$ref` that applies
   * there resolves to nothing. A list is not secret because its items
   * are: each item is a place of its own.
   *
   * @param \PortableSchema\Builder\Schema $document
   *   The document.
   * @param string $pointer
   *   A JSON pointer to a value an instance of the document holds, such
   *   as `/credential/api_key` or `/rows/0/token`; `''` for the whole.
   * @param \PortableSchema\Registry|null $library
   *   The registry a `portable:` id is read from; NULL for the site's.
   *
   * @return bool
   *   TRUE where the place is secret.
   */
  public static function at(Schema $document, string $pointer, ?Registry $library = NULL): bool {
    $library ??= static::library();
    $applying = static::applying($document, $document, $library);
    foreach ($pointer === '' ? [] : explode('/', substr($pointer, 1)) as $segment) {
      $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
      $next = [];
      foreach (static::inside($applying, $segment) as [$child, $root]) {
        array_push($next, ...static::applying($child, $root, $library));
      }
      $applying = $next;
    }
    foreach ($applying as [$schema]) {
      if (static::marked($schema)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Finds every secret place below a document's root.
   *
   * The tree Outbound::secrets() builds from a data surface: keyed like
   * the values, TRUE where a value is secret, an array where one below it
   * is. A list whose items are secret is TRUE; a list whose items hold
   * secrets keeps them under EACH. A place the tree has no form for is
   * secret whole: a list whose `prefixItems` are or hold a secret is
   * TRUE, since the tree keys no position, and so is an object whose
   * `additionalProperties` or `patternProperties` schema is or holds one,
   * since the tree keys no name a schema does not state. A `$ref` that
   * resolves to nothing is secret where it stands.
   *
   * @param \PortableSchema\Builder\Schema $document
   *   The document.
   * @param \PortableSchema\Registry|null $library
   *   The registry a `portable:` id is read from; NULL for the site's.
   *
   * @return true|array<string, true|array>
   *   The tree; TRUE where the root itself is secret, empty where nothing
   *   is.
   */
  public static function within(Schema $document, ?Registry $library = NULL): true|array {
    $library ??= static::library();
    return static::tree(static::applying($document, $document, $library), $library, 0);
  }

  /**
   * Answers whether nothing read from a document can be secret.
   *
   * Read with a margin, for a door that only asks for secrets and need not
   * build what holds none: FALSE where any subschema at any depth says
   * `writeOnly: true`, where a `$ref` names a type of the library that
   * says it or holds one that does, and where a def, by its name or by a
   * `$ref` to it, is one $shape knows, since a shape's own definition says
   * what it says. Only the keywords that hold schemas are entered. A
   * `$ref` to a def the document does not hold, or to an id the registry
   * does not hold, adds nothing here, as SchemaReader::holdsNoSecret()
   * read it at 20ef965, where at() answers secret.
   *
   * @param \PortableSchema\Builder\Schema $document
   *   The document.
   * @param callable(string): bool $shape
   *   Whether the site has a shape of an id.
   * @param \PortableSchema\Registry|null $library
   *   The registry a `portable:` id is read from; NULL for the site's.
   *
   * @return bool
   *   TRUE when nothing read from it can be secret.
   */
  public static function absent(Schema $document, callable $shape, ?Registry $library = NULL): bool {
    $defs = [];
    $types = [];
    if (!static::writesNoSecret($document, $defs, $types) || ($types !== [] && static::librarySecret(array_keys($types), $library ?? static::library()))) {
      return FALSE;
    }
    $own = $document->isBoolean() ? NULL : $document->get('$defs');
    foreach (array_keys(is_array($own) ? $own : []) as $name) {
      $defs[(string) $name] = TRUE;
    }
    foreach (array_keys($defs) as $name) {
      if ($shape((string) $name)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Collects a schema and every schema it brings in whole.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema at a place.
   * @param \PortableSchema\Builder\Schema $root
   *   The document it is in, whose defs a `$ref` names.
   * @param \PortableSchema\Registry $library
   *   The registry a `portable:` id is read from.
   * @param array<int, true> $seen
   *   The schemas collected at this place, by object id.
   *
   * @return list<array{0: \PortableSchema\Builder\Schema, 1: \PortableSchema\Builder\Schema}>
   *   Each schema that applies, with the document it is in.
   */
  protected static function applying(Schema $schema, Schema $root, Registry $library, array &$seen = []): array {
    if ($schema->isBoolean() || isset($seen[spl_object_id($schema)])) {
      return [];
    }
    $seen[spl_object_id($schema)] = TRUE;
    $applying = [[$schema, $root]];
    $reference = $schema->get('$ref');
    if (is_string($reference)) {
      $resolved = static::resolved($reference, $root, $library);
      if ($resolved === NULL) {
        // A reference read as nothing leans to secret.
        $applying[] = [self::$unread ??= Schema::any()->with('writeOnly', TRUE), $root];
      }
      else {
        array_push($applying, ...static::applying($resolved[0], $resolved[1], $library, $seen));
      }
    }
    foreach (self::WHOLE as $keyword) {
      $held = $schema->get($keyword);
      foreach ($held instanceof Schema ? [$held] : (is_array($held) ? $held : []) as $member) {
        if ($member instanceof Schema) {
          array_push($applying, ...static::applying($member, $root, $library, $seen));
        }
      }
    }
    return $applying;
  }

  /**
   * Finds the schemas of a value inside, one segment below.
   *
   * @param list<array{0: \PortableSchema\Builder\Schema, 1: \PortableSchema\Builder\Schema}> $applying
   *   What applies at the place, as applying() collected it.
   * @param string $segment
   *   A member's name, or an item's position.
   *
   * @return list<array{0: \PortableSchema\Builder\Schema, 1: \PortableSchema\Builder\Schema}>
   *   The schema of the value inside, from each schema that says one, with
   *   its document; each still to be collected.
   */
  protected static function inside(array $applying, string $segment): array {
    $inside = [];
    foreach ($applying as [$schema, $root]) {
      $properties = $schema->get('properties');
      $named = is_array($properties) && ($properties[$segment] ?? NULL) instanceof Schema;
      if ($named) {
        $inside[] = [$properties[$segment], $root];
      }
      $patterns = $schema->get('patternProperties');
      foreach (is_array($patterns) ? $patterns : [] as $pattern => $member) {
        if ($member instanceof Schema && static::matches((string) $pattern, $segment)) {
          $inside[] = [$member, $root];
          $named = TRUE;
        }
      }
      if (!$named && ($additional = $schema->get('additionalProperties')) instanceof Schema) {
        $inside[] = [$additional, $root];
      }
      if (ctype_digit($segment)) {
        $prefix = $schema->get('prefixItems');
        if (is_array($prefix) && ($prefix[(int) $segment] ?? NULL) instanceof Schema) {
          $inside[] = [$prefix[(int) $segment], $root];
        }
        elseif (($items = $schema->get('items')) instanceof Schema) {
          $inside[] = [$items, $root];
        }
      }
    }
    return $inside;
  }

  /**
   * Builds the tree of secret places below what applies at one place.
   *
   * @param list<array{0: \PortableSchema\Builder\Schema, 1: \PortableSchema\Builder\Schema}> $applying
   *   What applies at the place.
   * @param \PortableSchema\Registry $library
   *   The registry a `portable:` id is read from.
   * @param int $depth
   *   How far below the root the place is.
   *
   * @return true|array<string, true|array>
   *   The tree, as within() answers it.
   */
  protected static function tree(array $applying, Registry $library, int $depth): true|array {
    if ($depth > self::DEPTH) {
      return [];
    }
    $names = [];
    $items = [];
    // What the tree has no form for: a position, a name no schema states.
    $unnamed = [];
    foreach ($applying as [$schema, $root]) {
      if (static::marked($schema)) {
        return TRUE;
      }
      $properties = $schema->get('properties');
      foreach (is_array($properties) ? $properties : [] as $name => $property) {
        if ($property instanceof Schema) {
          $names[(string) $name][] = [$property, $root];
        }
      }
      if (($item = $schema->get('items')) instanceof Schema) {
        $items[] = [$item, $root];
      }
      $held = [
        ...(is_array($schema->get('prefixItems')) ? $schema->get('prefixItems') : []),
        ...(is_array($schema->get('patternProperties')) ? array_values($schema->get('patternProperties')) : []),
        $schema->get('additionalProperties'),
      ];
      foreach ($held as $member) {
        if ($member instanceof Schema) {
          $unnamed[] = [$member, $root];
        }
      }
    }
    foreach ($unnamed as [$member, $root]) {
      $seen = [];
      if (static::tree(static::applying($member, $root, $library, $seen), $library, $depth + 1) !== []) {
        // Secret whole: the tree cannot say which position or which name.
        return TRUE;
      }
    }
    $tree = [];
    foreach ($names as $name => $schemas) {
      $collected = [];
      $seen = [];
      foreach ($schemas as [$property, $root]) {
        array_push($collected, ...static::applying($property, $root, $library, $seen));
      }
      $found = static::tree($collected, $library, $depth + 1);
      if ($found !== []) {
        $tree[$name] = $found;
      }
    }
    if ($items !== []) {
      $collected = [];
      $seen = [];
      foreach ($items as [$item, $root]) {
        array_push($collected, ...static::applying($item, $root, $library, $seen));
      }
      $each = static::tree($collected, $library, $depth + 1);
      if ($each === TRUE) {
        // A list of secrets is secret, as a list definition of them is.
        return TRUE;
      }
      if ($each !== []) {
        $tree[self::EACH] = $each;
      }
    }
    return $tree;
  }

  /**
   * Looks through a schema for `writeOnly: true`, noting what it refers to.
   *
   * Only the keywords that hold a schema, a map or a list of them, are
   * entered.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   * @param array<string, true> $defs
   *   Collects the defs a `$ref` names.
   * @param array<string, true> $types
   *   Collects the types of the library a `$ref` names.
   *
   * @return bool
   *   FALSE as soon as one says `writeOnly: true`.
   */
  protected static function writesNoSecret(Schema $schema, array &$defs, array &$types): bool {
    if ($schema->isBoolean()) {
      return TRUE;
    }
    if (static::marked($schema)) {
      return FALSE;
    }
    $reference = $schema->get('$ref');
    if (is_string($reference)) {
      $name = SchemaResolver::defName($reference);
      if ($name !== NULL) {
        $defs[$name] = TRUE;
      }
      elseif (static::isLibrary($reference)) {
        $types[$reference] = TRUE;
      }
    }
    foreach ($schema->all() as $keyword => $value) {
      $shape = Keywords::TABLE[$keyword] ?? NULL;
      if ($shape === Keywords::SCHEMA) {
        $value = [$value];
      }
      elseif ($shape !== Keywords::SCHEMA_MAP && $shape !== Keywords::SCHEMA_LIST) {
        continue;
      }
      foreach (is_array($value) ? $value : [] as $member) {
        if ($member instanceof Schema && !static::writesNoSecret($member, $defs, $types)) {
          return FALSE;
        }
      }
    }
    return TRUE;
  }

  /**
   * Answers whether a type of the library says a value is secret.
   *
   * Where the type, or a type it holds at any depth, says `writeOnly:
   * true`.
   *
   * @param string[] $ids
   *   The types' ids.
   * @param \PortableSchema\Registry $library
   *   The registry they are read from.
   * @param string[] $seen
   *   The ids read on the way here.
   *
   * @return bool
   *   TRUE when one says so; FALSE for an id the registry does not hold.
   */
  protected static function librarySecret(array $ids, Registry $library, array $seen = []): bool {
    foreach (array_diff($ids, $seen) as $id) {
      $seen[] = $id;
      $type = static::type($id, $library);
      if ($type === NULL) {
        continue;
      }
      $secret = FALSE;
      $inside = [];
      Schemas::map($type, static function (Schema $schema) use (&$secret, &$inside): Schema {
        $secret = $secret || static::marked($schema);
        if (static::isLibrary($schema->get('$ref'))) {
          $inside[] = (string) $schema->get('$ref');
        }
        return $schema;
      });
      if ($secret || static::librarySecret($inside, $library, $seen)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Finds the schema a `$ref` names.
   *
   * @param string $reference
   *   The `$ref`: `#` or a JSON pointer into the document (`#/$defs/a`,
   *   `#/$defs/a/$defs/b`), or an id of the registry.
   * @param \PortableSchema\Builder\Schema $root
   *   The document the `$ref` stands in.
   * @param \PortableSchema\Registry $library
   *   The registry an id is read from.
   *
   * @return array{0: \PortableSchema\Builder\Schema, 1: \PortableSchema\Builder\Schema}|null
   *   The schema, with the document it is in; NULL where the pointer
   *   names no schema or the registry holds no object schema of the id.
   */
  protected static function resolved(string $reference, Schema $root, Registry $library): ?array {
    if (!str_starts_with($reference, '#')) {
      $type = static::type($reference, $library);
      return $type === NULL ? NULL : [$type, $type];
    }
    $at = $root;
    $pointer = substr($reference, 1);
    if ($pointer !== '' && !str_starts_with($pointer, '/')) {
      return NULL;
    }
    foreach ($pointer === '' ? [] : explode('/', substr($pointer, 1)) as $segment) {
      $segment = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($segment));
      $at = match (TRUE) {
        $at instanceof Schema => $at->isBoolean() ? NULL : $at->get($segment),
        is_array($at) => $at[$segment] ?? NULL,
        default => NULL,
      };
    }
    return $at instanceof Schema ? [$at, $root] : NULL;
  }

  /**
   * Answers whether a `patternProperties` pattern matches a name.
   *
   * @param string $pattern
   *   The pattern, as JSON Schema writes it, unanchored.
   * @param string $name
   *   The name.
   *
   * @return bool
   *   TRUE where it matches, and for a pattern PCRE cannot read, which
   *   leans to secret.
   */
  protected static function matches(string $pattern, string $name): bool {
    $matched = @preg_match("\x01" . $pattern . "\x01u", $name);
    return $matched !== 0;
  }

  /**
   * Reads a type of the library, by its id.
   *
   * @param string $id
   *   The id, such as `portable:secret`, or a URI the registry holds.
   * @param \PortableSchema\Registry $library
   *   The registry.
   *
   * @return \PortableSchema\Builder\Schema|null
   *   Its document; NULL where the registry holds no object schema of
   *   that id.
   */
  protected static function type(string $id, Registry $library): ?Schema {
    if (!str_contains($id, ':') || str_starts_with($id, '#') || !$library->has($id)) {
      return NULL;
    }
    self::$types ??= new \WeakMap();
    $read = self::$types[$library] ?? [];
    $type = $read[$id] ??= Schema::from($library->get($id));
    self::$types[$library] = $read;
    return $type->isBoolean() ? NULL : $type;
  }

  /**
   * Answers whether a `$ref` names an id of the type library.
   *
   * @param mixed $reference
   *   The `$ref`.
   *
   * @return bool
   *   TRUE for a `portable:` id.
   */
  protected static function isLibrary(mixed $reference): bool {
    return is_string($reference) && str_starts_with($reference, Registry::TYPES_NAMESPACE . ':');
  }

  /**
   * Gets the registry a `portable:` id is read from, where none is given.
   *
   * @return \PortableSchema\Registry
   *   The site's (surface.portable_registry), or, with no container, the
   *   type library.
   */
  protected static function library(): Registry {
    if (\Drupal::hasContainer() && \Drupal::hasService('surface.portable_registry')) {
      $library = \Drupal::service('surface.portable_registry');
      assert($library instanceof Registry);
      return $library;
    }
    return self::$library ??= Registry::withTypes();
  }

}
