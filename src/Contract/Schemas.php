<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use PortableSchema\Builder\Keywords;
use PortableSchema\Builder\Schema;

/**
 * What the emitter and the reader need of a Schema the builder lacks.
 *
 * The emitter builds each document as `Schema` values, and the schema
 * reader walks one, which never change once made: an edit is a new
 * value. at() finds a subschema by JSON pointer, decoded() writes a
 * schema as JSON decodes it, for a reader of arrays. Keyword order is
 * insertion order (`with()` on a keyword the schema has keeps its place,
 * a new one goes last), so where a keyword must lead or follow, it is
 * placed with first() or defaults(). map() walks the subschemas of a
 * schema, and only those: it never enters a keyword that holds data.
 *
 * Decision: see docs/decisions.md#the-emitter-builds-through-the-builder
 * and docs/decisions.md#the-reader-reads-a-schema.
 *
 * @internal
 */
final class Schemas {

  /**
   * The emitter's transient keywords that hold a schema, by its member.
   *
   * A key declared with a shape carries the shape's def under
   * `x-surface-shape.def` until PropertySchema::named() files it, so a walk
   * reaches that def as it reaches any subschema.
   */
  public const TRANSIENT_SCHEMAS = [PropertySchema::SHAPED => 'def'];

  /**
   * Writes a schema in the array form a served document carries.
   *
   * What the schema's document is, with each JSON object an associative
   * array or a \stdClass by the shape of the keyword that holds it, so
   * json_encode() of the result is json_encode() of the schema:
   * - A schema is an associative array, and a \stdClass when it is empty.
   * - A map of schemas (`properties`, `$defs`, `patternProperties`,
   *   `dependentSchemas`) is an associative array, and a \stdClass when it
   *   is empty or its names are exactly the indexes 0 to n - 1, which a PHP
   *   array would encode as a list. So a map with one member named "0"
   *   (or "0" and "1") is a \stdClass, and a reader that checks is_array()
   *   skips it.
   * - A data object (the value of any other keyword, such as `x-surface`,
   *   `x-portable-ui`, `default`, `const`, `examples` or `portable-check`,
   *   and every object inside one) is a \stdClass when it is empty or every
   *   name is a numeric string, and an associative array otherwise. So
   *   `x-surface.variants` named by numeric ids stays an object and
   *   array_merge() never renumbers it.
   * - A list stays a list.
   *
   * @param \PortableSchema\Builder\Schema|\stdClass|bool $schema
   *   The schema, or what toDocument() wrote.
   *
   * @return array|\stdClass|bool
   *   The array form; a boolean schema is its boolean.
   */
  public static function arrayed(Schema|\stdClass|bool $schema): array|\stdClass|bool {
    $written = self::schemaPlain($schema instanceof Schema ? $schema->toDocument() : $schema);
    assert(is_array($written) || $written instanceof \stdClass || is_bool($written));
    return $written;
  }

  /**
   * Places keywords first on a schema: a prepend.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   * @param array<string, mixed> $keywords
   *   The keywords, in order; each replaces the schema's own and moves to
   *   the front.
   *
   * @return \PortableSchema\Builder\Schema
   *   The keywords given, then the schema's others in their order; a
   *   keyword outside the vocabulary the schema carries stays, as
   *   Schema::from() keeps it.
   *
   * @throws \InvalidArgumentException
   *   For a boolean schema, which holds no keywords.
   */
  public static function first(Schema $schema, array $keywords): Schema {
    self::assertObject($schema, 'first');
    if ($keywords === []) {
      return $schema;
    }
    return Schema::from($keywords + $schema->all());
  }

  /**
   * Adds keywords a schema does not state: a left-wins union.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   * @param array<string, mixed> $keywords
   *   The keywords, in order.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema, each keyword it did not state added last, in order; one
   *   it states keeps its value and place, and a keyword outside the
   *   vocabulary stays, as Schema::from() keeps it.
   *
   * @throws \InvalidArgumentException
   *   For a boolean schema, which holds no keywords.
   */
  public static function defaults(Schema $schema, array $keywords): Schema {
    self::assertObject($schema, 'defaults');
    if (array_diff_key($keywords, $schema->all()) === []) {
      return $schema;
    }
    return Schema::from($schema->all() + $keywords);
  }

  /**
   * Refuses a boolean schema to a helper that places keywords.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   * @param string $helper
   *   The helper's name.
   *
   * @throws \InvalidArgumentException
   *   When the schema is boolean.
   */
  private static function assertObject(Schema $schema, string $helper): void {
    if ($schema->isBoolean()) {
      throw new \InvalidArgumentException(sprintf('Schemas::%s() places keywords on an object schema, not a boolean one.', $helper));
    }
  }

  /**
   * How same() encodes: the flags the fixtures are written with.
   *
   * JSON_PRESERVE_ZERO_FRACTION writes 1.0 as `1.0`, so 1 and 1.0, which
   * differ on the wire, compare as different.
   */
  private const SAME_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

  /**
   * Answers whether two schemas, or two keyword values, say the same.
   *
   * By their JSON, encoded with SAME_FLAGS: the same keywords, in the same
   * order, with the same values. A value may hold schemas at any depth.
   * Two empty objects are the same, whatever their PHP form; `1` and `1.0`
   * are not.
   *
   * @param mixed $a
   *   One.
   * @param mixed $b
   *   The other.
   *
   * @return bool
   *   TRUE when they encode the same.
   *
   * @throws \JsonException
   *   When either cannot be encoded (NAN, INF, invalid UTF-8): such a value
   *   is never the same as another.
   */
  public static function same(mixed $a, mixed $b): bool {
    return json_encode($a, self::SAME_FLAGS) === json_encode($b, self::SAME_FLAGS);
  }

  /**
   * Answers whether a value is a schema that holds keywords.
   *
   * An object schema, even an empty one; not a boolean schema, and not a
   * value a keyword holds that is no schema.
   *
   * @param mixed $value
   *   The value.
   *
   * @return bool
   *   TRUE for a Schema that is no boolean schema.
   *
   * @phpstan-assert-if-true \PortableSchema\Builder\Schema $value
   */
  public static function isObjectSchema(mixed $value): bool {
    return $value instanceof Schema && !$value->isBoolean();
  }

  /**
   * Reads the data a keyword holds as an array, in either form.
   *
   * A Schema holds a JSON object of data (the value of `x-surface`,
   * `x-portable-ui`, a check's options, and each object inside one) as
   * an array where it was read from arrays, and as a \stdClass where it
   * was read from objects, as json_decode() without its flag answers.
   * One level is read: a member that is an object is read through this
   * again where it is read.
   *
   * @param mixed $value
   *   The value.
   *
   * @return array|null
   *   Its members, by name; NULL for a value that is no object or list.
   */
  public static function data(mixed $value): ?array {
    return match (TRUE) {
      is_array($value) => $value,
      $value instanceof \stdClass => (array) $value,
      default => NULL,
    };
  }

  /**
   * Writes data read through data() back in the array form.
   *
   * As arrayed() writes a data object: an associative array, and a
   * \stdClass when it is empty or every name is a number, which an array
   * would write as a list.
   *
   * @param array $members
   *   The object's members, by name.
   *
   * @return array|\stdClass
   *   The object in the array form.
   */
  public static function dataObject(array $members): array|\stdClass {
    return array_filter(array_keys($members), 'is_string') === [] ? (object) $members : $members;
  }

  /**
   * Reads the boolean a boolean schema is.
   *
   * @param mixed $value
   *   The value.
   *
   * @return bool|null
   *   TRUE for the schema `true`, FALSE for `false`, NULL for anything
   *   else, an object schema among them.
   */
  public static function boolean(mixed $value): ?bool {
    if (!$value instanceof Schema || !$value->isBoolean()) {
      return NULL;
    }
    $allows = $value->toDocument();
    assert(is_bool($allows));
    return $allows;
  }

  /**
   * Finds what a JSON Pointer names inside a schema.
   *
   * Each step is read by the shape of the keyword it stands in
   * (Keywords::shape()): the step after a keyword holding a map of
   * schemas is a name, after one holding a list of them an index, and
   * either leads to a schema again; a step into any other keyword's value
   * leads into that value, a JSON value, and every step after it reads
   * that value's members. `~1` is `/` and `~0` is `~` in a step.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema the pointer starts from.
   * @param string $pointer
   *   The pointer: '' for the schema itself, else steps each led by `/`.
   *
   * @return mixed
   *   A Schema where the pointer names a schema, the value where it names
   *   a value or a member of one; NULL where nothing is there (and where a
   *   JSON null is).
   */
  public static function at(Schema $schema, string $pointer): mixed {
    if ($pointer === '') {
      return $schema;
    }
    if (!str_starts_with($pointer, '/')) {
      return NULL;
    }
    $steps = array_map(static fn (string $step): string => str_replace(['~1', '~0'], ['/', '~'], $step), explode('/', substr($pointer, 1)));
    $node = $schema;
    // What the node is: a schema, a map or a list of schemas, or a value.
    $holds = Keywords::SCHEMA;
    foreach ($steps as $step) {
      if ($holds === Keywords::SCHEMA) {
        if (!$node instanceof Schema || !$node->has($step)) {
          return NULL;
        }
        $holds = Keywords::shape($step) ?? Keywords::VALUE;
        $node = $node->get($step);
        if (($holds === Keywords::SCHEMA_MAP || $holds === Keywords::SCHEMA_LIST) && !is_array($node)) {
          $holds = Keywords::VALUE;
        }
        continue;
      }
      if ($holds === Keywords::SCHEMA_MAP || $holds === Keywords::SCHEMA_LIST) {
        if ($holds === Keywords::SCHEMA_LIST && !ctype_digit($step)) {
          return NULL;
        }
        if (!is_array($node) || !array_key_exists($step, $node)) {
          return NULL;
        }
        $node = $node[$step];
        $holds = $node instanceof Schema ? Keywords::SCHEMA : Keywords::VALUE;
        continue;
      }
      if ($node instanceof \stdClass && property_exists($node, $step)) {
        $node = $node->{$step};
      }
      elseif (is_array($node) && array_key_exists($step, $node)) {
        $node = $node[$step];
      }
      else {
        return NULL;
      }
    }
    return $node;
  }

  /**
   * Writes a schema as JSON decodes it, every object an array.
   *
   * What json_decode(json_encode($schema), TRUE) answers, without the
   * round trip: an empty object is an empty array, and a map whose names
   * are 0 to n - 1 a list. For a reader of arrays, not for the wire:
   * json_encode() of the result writes `{}` as `[]`.
   *
   * @param \PortableSchema\Builder\Schema|\stdClass|array|bool $schema
   *   The schema, or what toDocument() wrote.
   *
   * @return array|bool
   *   The schema decoded; a boolean schema is its boolean.
   */
  public static function decoded(Schema|\stdClass|array|bool $schema): array|bool {
    $decoded = self::decodedValue($schema instanceof Schema ? $schema->toDocument() : $schema);
    assert(is_array($decoded) || is_bool($decoded));
    return $decoded;
  }

  /**
   * Writes a written value as JSON decodes it, as decoded() says.
   *
   * @param mixed $value
   *   The value.
   *
   * @return mixed
   *   The same, every object an array.
   */
  private static function decodedValue(mixed $value): mixed {
    if ($value instanceof Schema) {
      $value = $value->toDocument();
    }
    if ($value instanceof \Stringable) {
      return (string) $value;
    }
    if ($value instanceof \stdClass) {
      $value = (array) $value;
    }
    if (!is_array($value)) {
      return $value;
    }
    foreach ($value as $name => $member) {
      if (is_object($member) || is_array($member)) {
        $value[$name] = self::decodedValue($member);
      }
    }
    return $value;
  }

  /**
   * Rewrites every subschema of a schema, and the schema itself.
   *
   * It descends only into the keywords whose value is a schema, a map of
   * schemas or a list of them (Keywords::shape()), and the transient
   * schemas of TRANSIENT_SCHEMAS; never into a keyword that holds data
   * (`default`, `const`, `examples`, `portable-check`'s options,
   * `x-portable-messages`, `x-portable-ui`, `x-surface`). A boolean schema
   * is left as it is.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   * @param callable(\PortableSchema\Builder\Schema): \PortableSchema\Builder\Schema $fn
   *   What each schema becomes.
   * @param bool $bottomUp
   *   TRUE to rewrite a schema after its subschemas, FALSE before them,
   *   descending into what $fn answered.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema, rewritten.
   */
  public static function map(Schema $schema, callable $fn, bool $bottomUp = TRUE): Schema {
    if ($schema->isBoolean()) {
      return $schema;
    }
    if (!$bottomUp) {
      $schema = $fn($schema);
      if ($schema->isBoolean()) {
        return $schema;
      }
    }
    $schema = self::inside($schema, static fn (Schema $child): Schema => self::map($child, $fn, $bottomUp));
    return $bottomUp ? $fn($schema) : $schema;
  }

  /**
   * Rewrites the subschemas one step inside a schema.
   *
   * The same keywords map() descends into. A keyword whose subschemas all
   * stay the same values keeps its value.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   * @param callable(\PortableSchema\Builder\Schema): \PortableSchema\Builder\Schema $fn
   *   What each subschema becomes.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema, each keyword in its place.
   */
  private static function inside(Schema $schema, callable $fn): Schema {
    $rewritten = $schema;
    foreach ($schema->all() as $keyword => $value) {
      $keyword = (string) $keyword;
      $shape = Keywords::shape($keyword);
      $member = self::TRANSIENT_SCHEMAS[$keyword] ?? NULL;
      $new = match (TRUE) {
        $shape === Keywords::SCHEMA && $value instanceof Schema => $fn($value),
        ($shape === Keywords::SCHEMA_MAP || $shape === Keywords::SCHEMA_LIST) && is_array($value) => array_map(static fn (mixed $child): mixed => $child instanceof Schema ? $fn($child) : $child, $value),
        $member !== NULL && is_array($value) && ($value[$member] ?? NULL) instanceof Schema => array_replace($value, [$member => $fn($value[$member])]),
        default => $value,
      };
      if ($new !== $value) {
        $rewritten = $rewritten->with($keyword, $new);
      }
    }
    return $rewritten;
  }

  /**
   * Converts a written schema to the array form, as arrayed() says.
   *
   * @param mixed $value
   *   A written schema, or a value a schema keyword holds that is not one.
   *
   * @return mixed
   *   The array form.
   */
  private static function schemaPlain(mixed $value): mixed {
    if (!$value instanceof \stdClass) {
      return $value;
    }
    $members = [];
    foreach ((array) $value as $name => $member) {
      $keyword = (string) $name;
      $transient = self::TRANSIENT_SCHEMAS[$keyword] ?? NULL;
      $members[$name] = match (Keywords::shape($keyword)) {
        Keywords::SCHEMA => self::schemaPlain($member),
        Keywords::SCHEMA_MAP => $member instanceof \stdClass ? self::mapPlain($member) : $member,
        Keywords::SCHEMA_LIST => is_array($member) ? array_map(self::schemaPlain(...), $member) : $member,
        default => $transient !== NULL && $member instanceof \stdClass && isset($member->{$transient})
          ? self::dataPlain($member, $transient)
          : self::dataPlain($member),
      };
    }
    return $members === [] || array_is_list($members) ? (object) $members : $members;
  }

  /**
   * Converts a written map of schemas to the array form.
   *
   * @param \stdClass $map
   *   The map.
   *
   * @return array|\stdClass
   *   An associative array; a \stdClass when empty or named 0 to n - 1.
   */
  private static function mapPlain(\stdClass $map): array|\stdClass {
    $members = array_map(self::schemaPlain(...), (array) $map);
    return $members === [] || array_is_list($members) ? (object) $members : $members;
  }

  /**
   * Converts a written data value to the array form.
   *
   * @param mixed $value
   *   The value.
   * @param string|null $schemaMember
   *   The one member that holds a schema (a transient's), or NULL.
   *
   * @return mixed
   *   The array form; an object is a \stdClass when empty or named only by
   *   numeric strings.
   */
  private static function dataPlain(mixed $value, ?string $schemaMember = NULL): mixed {
    if (is_array($value)) {
      return array_map(static fn (mixed $item): mixed => self::dataPlain($item), $value);
    }
    if (!$value instanceof \stdClass) {
      return $value;
    }
    $members = [];
    foreach ((array) $value as $name => $member) {
      $members[$name] = (string) $name === $schemaMember ? self::schemaPlain($member) : self::dataPlain($member);
    }
    $numeric = array_filter(array_keys($members), 'is_string') === [];
    return $numeric ? (object) $members : $members;
  }

}
