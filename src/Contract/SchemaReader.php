<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Refinement\Condition;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceCallable;
use Drupal\surface\ResourceBuild\ResourceBlueprint;
use Drupal\surface\ResourceBuild\ResourceRegistry;

/**
 * Reads a JSON Schema document into a blueprint: the emitter run backwards.
 *
 * ContractEmitter writes a sealed data surface as JSON Schema 2020-12;
 * this reads such a document, or one written anywhere else, into the keys,
 * children and rules a blueprint declares, so it is built, validated,
 * drawn, served and called as a structure declared in code is. Each
 * keyword is read as what the emitter writes it from:
 *
 * - a property is a key: its `type` the data type (`string` with `format`
 *   `idn-email` or `email` an email, `uri` a URI, `date-time` a date and
 *   time; `integer`, `number` a float, `boolean`), `null` beside it a key
 *   that may be cleared; `title`, `description`, `default`, `examples`;
 * - `required` the keys that must hold a value; an optional key whose
 *   type refuses null and that has a default, a key its storage always
 *   holds;
 * - `oneOf` of `const` with `title`, or `enum` (`meta:enum` the labels),
 *   a list of allowed values; `minimum` and `maximum` (and an exclusive
 *   bound on an integer) a Range, `minLength` and `maxLength` a Length,
 *   `minItems` and `maxItems` a Count, `uniqueItems: true` on an array a
 *   UniqueItems, `pattern` a Regex through the
 *   subset both engines read alike, run backwards (PatternTranslation),
 *   and `not: {pattern}` one that must not match; `format` `date`, `time`
 *   and `uuid` the constraint that checks it, and `date` a key drawn as a
 *   date input; an `allOf` or `anyOf` of
 *   such parts a Sequentially or an AtLeastOneOf;
 * - `readOnly` a key locked at its `const`, `writeOnly` a secret;
 * - a nested object a map, or, where it says more than a map can (a
 *   reference, a rule, variants), a structure attached at the key, read
 *   as a frame of its own; a `$ref` to an object under `$defs` one
 *   structure wherever it is used, its id the def's key; to a scalar def,
 *   the shape of that id where the site has one;
 * - an array of scalars a list, an array of objects a collection;
 * - a property that is a `oneOf` or `anyOf` of objects, each chosen by a
 *   conditional naming a sibling's `const`, variants that sibling chooses;
 * - an `allOf` of a reference to an object def, beside the frame's own
 *   properties, a structure mixed in;
 * - a conditional whose `if` is a condition of the vocabulary and whose
 *   `then` only narrows, a declared rule; one stated where a child is
 *   attached, reading the keys its ports are wired to, the child's rule on
 *   those ports;
 * - our own `x-surface` keywords, read back where they say something a
 *   key holds: `ports`, `reads`, `by` and `variants`, `collection`,
 *   `patternMessage`, the widget a key asks for, and `checkedOnServer`
 *   entries of a JsonSchema constraint.
 *
 * What none of these can say (`not`, `patternProperties`, `contains`,
 * `dependentSchemas`, `multipleOf`, a type that is several types, a
 * conditional outside the vocabulary or one that widens, a reference to
 * another document) is kept, not refused: the part of the schema left
 * over is a JsonSchema constraint, on the key, or on the frame for what
 * is said of the object, checked by the pipeline against that part and
 * named under `checkedOnServer`. A keyword that only annotates (`$comment`,
 * `deprecated`, `contentMediaType`, another vendor's `x-` keyword) is
 * dropped, and so is what only the server knew (`refine`, `live`, a
 * constraint named under `checkedOnServer`) and a list left out for its
 * size (`deferred`); each is noted, by its JSON
 * pointer. A document the reader cannot read at all (no object at its
 * root or at a def, a key holding a dot, a reference to a def it does not
 * hold, a type JSON Schema does not have) is refused, naming the pointer.
 *
 * Draft-04, -06 and -07, which single-directory components and many
 * tools write, are converted first (upconvert()).
 *
 * The invariant: the full schema the emitter writes for a resource or
 * structure, read back, builds a structure whose own full schema is that
 * document again, byte for byte, but for what docs/reading-a-schema.md
 * lists (a coded refiner's conditionals read as declared rules, what an
 * alter mounts, what a context locks, keys from the site).
 *
 * Decision: see docs/decisions.md#a-schema-can-be-read-back-into-a-structure.
 *
 * @see docs/reading-a-schema.md
 */
final class SchemaReader {

  /**
   * The JSON Schema types.
   */
  protected const TYPES = ['string', 'integer', 'number', 'boolean', 'object', 'array', 'null'];

  /**
   * The JSON length from which a prepared document is kept and shared.
   *
   * Below it, preparing again costs less than keeping it: a component's
   * props, a field type's settings.
   */
  protected const SHARED_PREPARED = 65536;

  /**
   * How many large prepared documents are kept, the oldest let go first.
   */
  protected const PREPARED_KEPT = 8;

  /**
   * The large documents prepared so far, by a hash of their JSON.
   *
   * @var array<string, array>
   */
  private static array $prepared = [];

  /**
   * The keywords that hold one subschema.
   */
  protected const SINGLE = [
    'items',
    'additionalItems',
    'additionalProperties',
    'unevaluatedProperties',
    'unevaluatedItems',
    'contains',
    'propertyNames',
    'not',
    'if',
    'then',
    'else',
    'contentSchema',
  ];

  /**
   * The keywords that hold a list of subschemas.
   */
  protected const LISTS = ['allOf', 'anyOf', 'oneOf', 'prefixItems'];

  /**
   * The keywords that hold a map of subschemas.
   */
  protected const MAPS = ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'];

  /**
   * The keywords that only annotate: dropped, with a note.
   */
  protected const ANNOTATIONS = [
    '$comment',
    'deprecated',
    'contentMediaType',
    'contentEncoding',
    'contentSchema',
    '$anchor',
    '$dynamicAnchor',
    'x-translation-context',
  ];

  /**
   * The keywords a frame reads as its own, the rest of a frame left over.
   */
  protected const FRAME = [
    '$schema',
    '$id',
    '$defs',
    'title',
    'description',
    'type',
    'properties',
    'required',
    'additionalProperties',
    'unevaluatedProperties',
    'allOf',
    ContractEmitter::EXTENSION,
    'readOnly',
  ];

  /**
   * The keywords a reference to a structure mixed in stands with.
   */
  protected const MIXED_IN = ['$ref', 'unevaluatedProperties'];

  /**
   * The keywords a collection says of itself.
   */
  protected const COLLECTION = [
    'type',
    'title',
    'description',
    'items',
    'minItems',
    'maxItems',
    'uniqueItems',
    'default',
    ContractEmitter::EXTENSION,
  ];

  /**
   * The keywords an object read as a plain map may say.
   */
  protected const MAP = [
    'type',
    'title',
    'description',
    'properties',
    'required',
    'additionalProperties',
    'readOnly',
    'writeOnly',
    ContractEmitter::EXTENSION,
  ];

  /**
   * The keywords of a value held whole that its own definition says.
   */
  protected const WHOLE = [
    'title',
    'description',
    'default',
    'examples',
    'readOnly',
    'writeOnly',
    '$comment',
    'deprecated',
    ContractEmitter::EXTENSION,
  ];

  /**
   * The keywords an entry of a `oneOf` list of values may say.
   */
  protected const CHOICE = ['const', 'title', 'description'];

  /**
   * The `format` each data type is written with, by format.
   */
  protected const FORMATS = [
    'idn-email' => 'email',
    'email' => 'email',
    'uri' => 'uri',
    'date-time' => 'datetime_iso8601',
  ];

  /**
   * The Regex each date or time `format` is checked by, RFC 3339's.
   */
  protected const FORMAT_PATTERNS = [
    'date' => '/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/D',
    'time' => '/^([01]\d|2[0-3]):[0-5]\d:([0-5]\d|60)(\.\d+)?([Zz]|[+-]([01]\d|2[0-3]):[0-5]\d)$/D',
  ];

  /**
   * The constraint each keyword of a value is read into.
   */
  protected const BOUNDS = [
    'minimum' => ['Range', 'min'],
    'maximum' => ['Range', 'max'],
    'minLength' => ['Length', 'min'],
    'maxLength' => ['Length', 'max'],
    'minItems' => ['Count', 'min'],
    'maxItems' => ['Count', 'max'],
  ];

  /**
   * The keywords read for a value, beside the type and what bounds it.
   */
  protected const VALUE = [
    'type',
    'format',
    'title',
    'description',
    'default',
    'examples',
    'oneOf',
    'enum',
    'const',
    'pattern',
    'not',
    'allOf',
    'anyOf',
    'readOnly',
    'writeOnly',
    'meta:enum',
    'exclusiveMinimum',
    'exclusiveMaximum',
    ContractEmitter::EXTENSION,
  ];

  /**
   * What was noted while reading, by JSON pointer.
   *
   * @var array<string, string>
   */
  protected array $notes = [];

  /**
   * The pointers of the structures the frame being read holds.
   *
   * @var string[]
   */
  protected array $frames = [];

  /**
   * The document being read, and its hash, once taken.
   *
   * @var array{0: array, 1: string}|null
   */
  protected ?array $hashed = NULL;

  /**
   * Constructs a SchemaReader.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry|null $registry
   *   The registry a document read on its own is registered with
   *   (structure()); NULL for a reader that only fills a blueprint, which
   *   holds a registry itself.
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface|null $typedDataManager
   *   The typed data manager, for the blueprints a whole document is
   *   surveyed into (notes()); NULL for a reader that only fills one.
   */
  public function __construct(
    protected readonly ?ResourceRegistry $registry = NULL,
    protected readonly ?TypedDataManagerInterface $typedDataManager = NULL,
  ) {}

  /**
   * Reads a document's root object into a blueprint.
   *
   * @param array $document
   *   The schema, decoded with objects as arrays.
   * @param \Drupal\surface\Resource\BlueprintInterface $inputs
   *   The blueprint to fill: the build step's own.
   *
   * @return array<string, string>
   *   What was read otherwise than as a key or a rule, or not at all, by
   *   JSON pointer.
   *
   * @throws \InvalidArgumentException
   *   For what cannot be read at all, naming its pointer.
   */
  public function read(array $document, BlueprintInterface $inputs): array {
    $root = static::prepared($document);
    $this->notes = [];
    $this->frame($root, '', static::blueprintOf($inputs), $root);
    return $this->notes;
  }

  /**
   * Fills the blueprint of a structure read from a document.
   *
   * The inputs of what ResourceRegistry::defineRead() registers: the
   * object at the pointer, read as a frame.
   *
   * @param \Drupal\surface\Resource\BlueprintInterface $inputs
   *   The structure's blueprint.
   * @param array $root
   *   The document, as prepared().
   * @param string $pointer
   *   The JSON pointer of the structure's object in it.
   * @param string|null $hash
   *   The document's hash, as ResourceBlueprint::readStructure() took it:
   *   handed on to each structure the frame holds, so it is taken once.
   */
  public static function fill(BlueprintInterface $inputs, array $root, string $pointer, ?string $hash = NULL): void {
    $reader = new static();
    $reader->hashed = $hash === NULL ? NULL : [$root, $hash];
    $reader->frame(static::at($root, $pointer), $pointer, static::blueprintOf($inputs), $root);
  }

  /**
   * Reads what a def says the structure it is is called and is for.
   *
   * Its `title`, unless that is its own key, which is what the emitter
   * titles a def whose structure has no label; and its `description`.
   * The structure read from the def takes them as its label and its
   * description, so a document emits them again where it read them.
   *
   * Decision: see
   * docs/decisions.md#a-resource-and-a-structure-may-say-what-they-are-called-and-what-they-are-for.
   *
   * @param array $root
   *   The document, as prepared().
   * @param string $pointer
   *   The JSON pointer of the structure's object in it.
   * @param string $id
   *   The structure's id: for a def, its key.
   *
   * @return array{0: string|null, 1: string|null}
   *   The label and the description; both NULL for an object that is not
   *   a def, whose title is its key's or its operation's.
   */
  public static function names(array $root, string $pointer, string $id): array {
    if (!preg_match('#^/\$defs/[^/]+$#', $pointer) || !is_array($root['$defs'] ?? NULL)) {
      return [NULL, NULL];
    }
    try {
      $def = static::at($root, $pointer);
    }
    catch (\InvalidArgumentException) {
      return [NULL, NULL];
    }
    $title = is_string($def['title'] ?? NULL) && $def['title'] !== '' && $def['title'] !== $id ? $def['title'] : NULL;
    $description = is_string($def['description'] ?? NULL) && $def['description'] !== '' ? $def['description'] : NULL;
    return [$title, $description];
  }

  /**
   * Registers a document as a structure, to be built by the name answered.
   *
   * @param array $document
   *   The schema, decoded with objects as arrays.
   * @param string $id
   *   The id the structure is built and emitted with.
   * @param string $module
   *   The module it is reported under.
   *
   * @return string
   *   The name ResourcesInterface::build() takes.
   */
  public function structure(array $document, string $id, string $module = 'surface'): string {
    if ($this->registry === NULL) {
      throw new \LogicException('A schema is registered as a structure by the surface.schema_reader service, which holds the registry.');
    }
    $root = static::prepared($document);
    $inputs = new ResourceCallable(static::class, 'fill', [$root, '']);
    return $this->registry->defineRead($id, $module, $inputs, hash('xxh128', serialize([$id, $root, ''])));
  }

  /**
   * Lists what reading a whole document notes, without building anything.
   *
   * @param array $document
   *   The schema.
   *
   * @return array<string, string>
   *   The notes, by JSON pointer, every frame of the document read: its
   *   root, its defs and every object a key holds.
   */
  public function notes(array $document): array {
    $root = static::prepared($document);
    if ($this->registry === NULL) {
      throw new \LogicException('A whole document is surveyed by the surface.schema_reader service, which holds the registry.');
    }
    $this->notes = [];
    $this->survey($root, '', $root, []);
    ksort($this->notes);
    return $this->notes;
  }

  /**
   * Converts a document, and makes it ready to be read.
   *
   * Read as JSON decodes it, objects as arrays; upconverted
   * (upconvert()); its `x-surface` markers put back where the
   * emitter wrote them (SchemaResolver::restored()), and each rule a
   * container states for a child it wires ports to moved into the child's
   * def, in the child's own words (localized()).
   *
   * @param array $document
   *   The schema.
   *
   * @return array
   *   The schema, prepared.
   */
  public static function prepared(array $document): array {
    // Read as JSON: an object the emitter holds as a stdClass (an empty
    // map, the variants' map) is an array like any other. What this
    // answers is a function of the JSON alone, so a large document is
    // prepared once and shared, however many builds read it.
    // Decision: see
    // docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
    $json = json_encode($document, JSON_PRESERVE_ZERO_FRACTION);
    $key = $json === FALSE || strlen($json) < self::SHARED_PREPARED ? NULL : hash('xxh128', $json);
    if ($key !== NULL && isset(self::$prepared[$key])) {
      $kept = self::$prepared[$key];
      unset(self::$prepared[$key]);
      return self::$prepared[$key] = $kept;
    }
    // A document holding no object decodes to itself: it is read as it
    // is, so it shares its arrays and strings with what the caller holds.
    $decoded = match (TRUE) {
      $json === FALSE => [],
      static::decodesToItself($document) => $document,
      default => json_decode($json, TRUE),
    };
    $prepared = static::localized(SchemaResolver::restored(static::upconvert(is_array($decoded) ? $decoded : [])));
    if ($key !== NULL) {
      self::$prepared[$key] = $prepared;
      if (count(self::$prepared) > self::PREPARED_KEPT) {
        unset(self::$prepared[array_key_first(self::$prepared)]);
      }
    }
    return $prepared;
  }

  /**
   * Answers whether a document is what decoding its JSON gives back.
   *
   * It is when it holds no object at any depth (a decoded object is an
   * array) and floats are written to round trip, as PHP writes them by
   * default.
   *
   * @param array $document
   *   The document.
   *
   * @return bool
   *   TRUE when JSON would give the same array back.
   */
  protected static function decodesToItself(array $document): bool {
    if ((string) ini_get('serialize_precision') !== '-1') {
      return FALSE;
    }
    foreach ($document as $value) {
      if (is_object($value) || (is_array($value) && !static::decodesToItself($value))) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Answers whether no structure read from a document holds a secret.
   *
   * A key read from a document is secret only where its schema says
   * `writeOnly: true` (finished()), or where it holds a named shape, whose
   * own definition says what it says. A document that says neither
   * anywhere, at any depth and in any def, holds no secret in any
   * structure read from it, so a door that only asks for secrets need not
   * build one to know. Read with a margin: a `writeOnly` key holding
   * TRUE anywhere, or any def a shape is known by, answers FALSE.
   *
   * Decision: see
   * docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
   *
   * @param array $root
   *   The document, prepared.
   * @param \Closure(string): bool $isShape
   *   Whether the site has a shape of an id.
   *
   * @return bool
   *   TRUE when nothing read from it can be secret.
   */
  public static function holdsNoSecret(array $root, \Closure $isShape): bool {
    $defs = [];
    if (!static::writesNoSecret($root, $defs)) {
      return FALSE;
    }
    foreach (array_keys(is_array($root['$defs'] ?? NULL) ? $root['$defs'] : []) as $name) {
      $defs[(string) $name] = TRUE;
    }
    foreach (array_keys($defs) as $name) {
      if ($isShape((string) $name)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Looks through a schema for `writeOnly: true`, noting each def it names.
   *
   * @param array $schema
   *   The schema, or any value inside it.
   * @param array<string, true> $defs
   *   Collects the defs a `$ref` names.
   *
   * @return bool
   *   FALSE as soon as one is found.
   */
  protected static function writesNoSecret(array $schema, array &$defs): bool {
    if (($schema['writeOnly'] ?? NULL) === TRUE) {
      return FALSE;
    }
    if (is_string($schema['$ref'] ?? NULL)) {
      $name = SchemaResolver::defName($schema['$ref']);
      if ($name !== NULL) {
        $defs[$name] = TRUE;
      }
    }
    foreach ($schema as $value) {
      if (is_array($value) && !static::writesNoSecret($value, $defs)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Converts a draft-04, -06 or -07 schema to JSON Schema 2020-12.
   *
   * What changed between them, and what single-directory components and
   * OpenAPI 3.0 write: `definitions` is `$defs`, and a reference into it
   * points there; `id` is `$id`; a boolean `exclusiveMinimum` or
   * `exclusiveMaximum` beside its bound is the bound itself; `nullable:
   * true` allows null; an `items` list is `prefixItems`, and
   * `additionalItems` what follows it, `items`; `dependencies` is
   * `dependentRequired` or `dependentSchemas`. Applied at every depth,
   * wherever a subschema stands, whatever `$schema` says, since each
   * conversion reads a spelling 2020-12 does not have.
   *
   * @param array $document
   *   The schema.
   *
   * @return array
   *   The schema in JSON Schema 2020-12, `$schema` saying so at its root.
   */
  public static function upconvert(array $document): array {
    $converted = static::upconverted($document);
    $converted['$schema'] = ContractEmitter::DIALECT;
    return ['$schema' => ContractEmitter::DIALECT] + $converted;
  }

  /**
   * Converts one subschema, and every subschema inside it.
   *
   * @param array $schema
   *   The subschema.
   *
   * @return array
   *   The same, converted.
   */
  protected static function upconverted(array $schema): array {
    if (isset($schema['definitions']) && is_array($schema['definitions'])) {
      $schema = static::renamedKeyword($schema, 'definitions', ['$defs' => (is_array($schema['$defs'] ?? NULL) ? $schema['$defs'] : []) + $schema['definitions']]);
    }
    if (isset($schema['id']) && is_string($schema['id']) && !isset($schema['$id'])) {
      $schema = static::renamedKeyword($schema, 'id', ['$id' => $schema['id']]);
    }
    if (is_string($schema['$ref'] ?? NULL) && str_starts_with($schema['$ref'], '#/definitions/')) {
      $schema['$ref'] = '#/$defs/' . substr($schema['$ref'], strlen('#/definitions/'));
    }
    foreach (['Minimum' => 'minimum', 'Maximum' => 'maximum'] as $side => $bound) {
      $exclusive = 'exclusive' . $side;
      if (is_bool($schema[$exclusive] ?? NULL)) {
        if ($schema[$exclusive] && isset($schema[$bound])) {
          $schema[$exclusive] = $schema[$bound];
          unset($schema[$bound]);
        }
        else {
          unset($schema[$exclusive]);
        }
      }
    }
    if (($schema['nullable'] ?? NULL) === TRUE) {
      if (isset($schema['type'])) {
        $schema['type'] = array_values(array_unique([...(array) $schema['type'], 'null']));
      }
      if (is_array($schema['enum'] ?? NULL) && !in_array(NULL, $schema['enum'], TRUE)) {
        $schema['enum'][] = NULL;
      }
    }
    // Each change only where there is one, so a subschema with nothing
    // to convert stays the array it was, shared with what it came from.
    if (array_key_exists('nullable', $schema)) {
      unset($schema['nullable']);
    }
    if (isset($schema['items']) && is_array($schema['items']) && array_is_list($schema['items']) && $schema['items'] !== []) {
      $schema['prefixItems'] = $schema['items'];
      unset($schema['items']);
      if (array_key_exists('additionalItems', $schema)) {
        $schema['items'] = $schema['additionalItems'];
      }
    }
    if (array_key_exists('additionalItems', $schema)) {
      unset($schema['additionalItems']);
    }
    if (isset($schema['dependencies']) && is_array($schema['dependencies'])) {
      $split = [];
      foreach ($schema['dependencies'] as $name => $dependency) {
        $split[is_array($dependency) && array_is_list($dependency) ? 'dependentRequired' : 'dependentSchemas'][$name] = $dependency;
      }
      $schema = static::renamedKeyword($schema, 'dependencies', $split);
    }
    foreach ($schema as $keyword => $value) {
      if (!is_array($value)) {
        continue;
      }
      if (in_array($keyword, self::SINGLE, TRUE) && !array_is_list($value)) {
        $converted = static::upconverted($value);
        if ($converted !== $value) {
          $schema[$keyword] = $converted;
        }
      }
      elseif (in_array($keyword, self::LISTS, TRUE) && array_is_list($value)) {
        $converted = array_map(static fn (mixed $member): mixed => is_array($member) ? static::upconverted($member) : $member, $value);
        if ($converted !== $value) {
          $schema[$keyword] = $converted;
        }
      }
      elseif (in_array($keyword, self::MAPS, TRUE)) {
        foreach ($value as $name => $member) {
          if (is_array($member)) {
            $converted = static::upconverted($member);
            if ($converted !== $member) {
              $schema[$keyword][$name] = $converted;
            }
          }
        }
      }
    }
    return $schema;
  }

  /**
   * Replaces a keyword by others, where it stood.
   *
   * @param array $schema
   *   The schema.
   * @param string $keyword
   *   The keyword replaced.
   * @param array $with
   *   The keywords in its place, with their values.
   *
   * @return array
   *   The schema.
   */
  protected static function renamedKeyword(array $schema, string $keyword, array $with): array {
    $renamed = [];
    foreach ($schema as $key => $value) {
      if ($key === $keyword) {
        $renamed += $with;
        continue;
      }
      if (!array_key_exists($key, $with)) {
        $renamed[$key] = $value;
      }
    }
    return $renamed;
  }

  /**
   * Moves each rule a container states for a child into the child's def.
   *
   * A child that reads ports is written once, as a def that names no
   * container, and what depends on a port is stated where it is attached,
   * on the container's `allOf`: an `if` on the container's key wired to
   * the port and a `then` reaching into the child at the key. Read back,
   * that rule is the child's, on the port: its `if` renamed from the
   * container's key to the port, its `then` the narrowing inside the
   * child, added to the def's `allOf` once, however many sites state it.
   *
   * Decision: see docs/decisions.md#a-port-is-a-live-sibling-value.
   *
   * @param array $root
   *   The document.
   *
   * @return array
   *   The same, each such rule in the child's def.
   */
  protected static function localized(array $root): array {
    $moved = [];
    $root = static::localizedIn($root, $root, $moved);
    foreach ($moved as $name => $conditionals) {
      if (!is_array($root['$defs'][$name] ?? NULL)) {
        continue;
      }
      $def = $root['$defs'][$name];
      $own = is_array($def['allOf'] ?? NULL) ? $def['allOf'] : [];
      foreach ($conditionals as $conditional) {
        if (array_filter($own, static fn (mixed $stated): bool => Subschemas::same($stated, $conditional)) === []) {
          $own[] = $conditional;
        }
      }
      $root['$defs'][$name]['allOf'] = $own;
    }
    return $root;
  }

  /**
   * Finds the rules stated at a child's site in one frame, and inside it.
   *
   * @param array $frame
   *   An object schema: the root, a def, an object a key holds.
   * @param array $root
   *   The document.
   * @param array<string, array[]> $moved
   *   Collects each def's rules, by the def's key.
   *
   * @return array
   *   The frame, the rules found taken out of its `allOf`.
   */
  protected static function localizedIn(array $frame, array $root, array &$moved): array {
    foreach (['properties', '$defs'] as $keyword) {
      foreach (is_array($frame[$keyword] ?? NULL) ? $frame[$keyword] : [] as $name => $property) {
        if (is_array($property) && (isset($property['properties']) || isset($property['$defs']))) {
          $localized = static::localizedIn($property, $root, $moved);
          if ($localized !== $property) {
            $frame[$keyword][$name] = $localized;
          }
        }
      }
    }
    if (!is_array($frame['allOf'] ?? NULL) || !is_array($frame['properties'] ?? NULL)) {
      return $frame;
    }
    $sites = [];
    foreach ($frame['properties'] as $name => $property) {
      $reads = is_array($property) ? ($property[ContractEmitter::EXTENSION]['reads'] ?? NULL) : NULL;
      $def = is_array($property) ? SchemaResolver::refersTo($property) : NULL;
      if (is_array($reads) && $reads !== [] && $def !== NULL && is_array($root['$defs'][$def][ContractEmitter::EXTENSION]['ports'] ?? NULL)) {
        $ports = $root['$defs'][$def][ContractEmitter::EXTENSION]['ports'];
        // Only a port the def reads, wired to a key of this frame: a map
        // whose entries each read a sibling map's entry is no such site.
        if (array_diff_key($reads, $ports) === [] && array_diff(array_values($reads), array_keys($frame['properties'])) === []) {
          $sites[(string) $name] = ['def' => $def, 'ports' => array_flip(array_map('strval', $reads))];
        }
      }
    }
    if ($sites === []) {
      return $frame;
    }
    foreach ($frame['allOf'] as $i => $conditional) {
      if (!is_array($conditional) || !isset($conditional['if'], $conditional['then']) || isset($conditional['else'])) {
        continue;
      }
      $then = $conditional['then'];
      $touched = is_array($then['properties'] ?? NULL) ? array_keys($then['properties']) : [];
      if (count($touched) !== 1 || array_diff(array_keys($then), ['properties']) !== [] || !isset($sites[(string) $touched[0]])) {
        continue;
      }
      $site = $sites[(string) $touched[0]];
      $condition = is_array($conditional['if']) ? static::condition($conditional['if']) : NULL;
      if ($condition === NULL || array_diff($condition->keys(), array_keys($site['ports'])) !== []) {
        continue;
      }
      $inside = $then['properties'][$touched[0]];
      if (!is_array($inside)) {
        continue;
      }
      unset($inside['type']);
      $localized = [
        'if' => static::renamed($condition, $site['ports'])->schema(),
        'then' => $inside,
      ];
      if (isset($conditional[ContractEmitter::EXTENSION])) {
        $localized[ContractEmitter::EXTENSION] = $conditional[ContractEmitter::EXTENSION];
      }
      $moved[$site['def']][] = $localized;
      unset($frame['allOf'][$i]);
    }
    $frame['allOf'] = array_values($frame['allOf']);
    if ($frame['allOf'] === []) {
      unset($frame['allOf']);
    }
    return $frame;
  }

  /**
   * Renames the keys a condition reads.
   *
   * @param \Drupal\surface\Refinement\Condition $condition
   *   The condition.
   * @param array<string, string> $names
   *   Each key's new name, by its old one.
   *
   * @return \Drupal\surface\Refinement\Condition
   *   The same test of the renamed keys.
   */
  protected static function renamed(Condition $condition, array $names): Condition {
    $key = $condition->key === NULL ? NULL : ($names[$condition->key] ?? $condition->key);
    return match ($condition->kind) {
      'present' => Condition::present((string) $key),
      'absent' => Condition::absent((string) $key),
      'equals' => Condition::equals((string) $key, $condition->operand),
      'in' => Condition::in((string) $key, $condition->operand),
      'atLeast' => Condition::atLeast((string) $key, $condition->operand),
      'atMost' => Condition::atMost((string) $key, $condition->operand),
      'above' => Condition::above((string) $key, $condition->operand),
      'below' => Condition::below((string) $key, $condition->operand),
      'not' => Condition::not(static::renamed($condition->conditions[0], $names)),
      'allOf' => Condition::allOf(...array_map(static fn (Condition $inner): Condition => static::renamed($inner, $names), $condition->conditions)),
      default => Condition::anyOf(...array_map(static fn (Condition $inner): Condition => static::renamed($inner, $names), $condition->conditions)),
    };
  }

  /**
   * Reads a condition of the vocabulary from its `if`.
   *
   * The inverse of Condition::schema(): `present`, `absent`, `equals`,
   * `in` and the four bounds of one key, and `not`, `allOf`, `anyOf` of
   * them; an `if` testing several keys at once is their `allOf`.
   *
   * @param array $if
   *   The `if`.
   *
   * @return \Drupal\surface\Refinement\Condition|null
   *   The condition, or NULL for an `if` outside the vocabulary.
   */
  public static function condition(array $if): ?Condition {
    unset($if[ContractEmitter::EXTENSION]);
    if (array_keys($if) === ['not'] && is_array($if['not'])) {
      $inner = static::condition($if['not']);
      return $inner === NULL ? NULL : Condition::not($inner);
    }
    foreach (['allOf', 'anyOf'] as $combinator) {
      if (array_keys($if) === [$combinator] && is_array($if[$combinator]) && array_is_list($if[$combinator]) && $if[$combinator] !== []) {
        $inner = array_map(static fn (mixed $member): ?Condition => is_array($member) ? static::condition($member) : NULL, $if[$combinator]);
        if (in_array(NULL, $inner, TRUE)) {
          return NULL;
        }
        return $combinator === 'allOf' ? Condition::allOf(...$inner) : Condition::anyOf(...$inner);
      }
    }
    if (array_diff(array_keys($if), ['properties', 'required']) !== [] || !is_array($if['properties'] ?? NULL) || $if['properties'] === []) {
      return NULL;
    }
    $required = is_array($if['required'] ?? NULL) ? array_map('strval', $if['required']) : [];
    $tests = [];
    $needs = [];
    foreach ($if['properties'] as $key => $test) {
      $key = (string) $key;
      if (!is_array($test)) {
        return NULL;
      }
      $tested = static::test($key, $test);
      if ($tested === NULL) {
        return NULL;
      }
      $tests[] = $tested;
      if ($tested->kind !== 'absent') {
        $needs[] = $key;
      }
    }
    sort($needs);
    sort($required);
    if ($needs !== $required) {
      return NULL;
    }
    return count($tests) === 1 ? $tests[0] : Condition::allOf(...$tests);
  }

  /**
   * Reads the test of one key in an `if`.
   *
   * @param string $key
   *   The key.
   * @param array $test
   *   The schema it is held to.
   *
   * @return \Drupal\surface\Refinement\Condition|null
   *   The test, or NULL for one outside the vocabulary.
   */
  protected static function test(string $key, array $test): ?Condition {
    if ($test === ['not' => ['type' => 'null']]) {
      return Condition::present($key);
    }
    if ($test === ['type' => 'null']) {
      return Condition::absent($key);
    }
    if (array_keys($test) === ['const'] && is_scalar($test['const'])) {
      return Condition::equals($key, $test['const']);
    }
    if (array_keys($test) === ['enum'] && is_array($test['enum']) && $test['enum'] !== [] && array_filter($test['enum'], static fn (mixed $value): bool => !is_scalar($value)) === []) {
      return Condition::in($key, $test['enum']);
    }
    if (count($test) === 2 && ($test['type'] ?? NULL) === 'number') {
      $bound = array_diff_key($test, ['type' => TRUE]);
      $value = reset($bound);
      if (!is_int($value) && !is_float($value)) {
        return NULL;
      }
      return match (key($bound)) {
        'minimum' => Condition::atLeast($key, $value),
        'maximum' => Condition::atMost($key, $value),
        'exclusiveMinimum' => Condition::above($key, $value),
        'exclusiveMaximum' => Condition::below($key, $value),
        default => NULL,
      };
    }
    return NULL;
  }

  /**
   * Gets the build step's blueprint behind the interface.
   *
   * @param \Drupal\surface\Resource\BlueprintInterface $inputs
   *   The blueprint.
   *
   * @return \Drupal\surface\ResourceBuild\ResourceBlueprint
   *   The same.
   */
  protected static function blueprintOf(BlueprintInterface $inputs): ResourceBlueprint {
    if (!$inputs instanceof ResourceBlueprint) {
      throw new \LogicException(sprintf('A schema is read into the blueprint the build step hands defineInputs(); %s is not one.', get_class($inputs)));
    }
    return $inputs;
  }

  /**
   * Reads the subschema at a JSON pointer.
   *
   * @param array $root
   *   The document.
   * @param string $pointer
   *   The pointer, '' for the root.
   *
   * @return array
   *   The subschema.
   *
   * @throws \InvalidArgumentException
   *   When nothing is there.
   */
  protected static function at(array $root, string $pointer): array {
    $node = $root;
    if ($pointer === '') {
      return $node;
    }
    foreach (explode('/', substr($pointer, 1)) as $segment) {
      $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
      if (!is_array($node) || !array_key_exists($segment, $node)) {
        throw new \InvalidArgumentException(sprintf('%s: the document holds nothing there.', $pointer));
      }
      $node = $node[$segment];
    }
    if (!is_array($node)) {
      throw new \InvalidArgumentException(sprintf('%s: the document holds no schema there.', $pointer));
    }
    return $node;
  }

  /**
   * Spells a pointer one step further.
   *
   * @param string $pointer
   *   The pointer.
   * @param string|int ...$segments
   *   The segments added.
   *
   * @return string
   *   The pointer.
   */
  protected static function below(string $pointer, string|int ...$segments): string {
    foreach ($segments as $segment) {
      $pointer .= '/' . str_replace(['~', '/'], ['~0', '~1'], (string) $segment);
    }
    return $pointer;
  }

  /**
   * Notes something read otherwise than said, or not read.
   *
   * @param string $pointer
   *   Where it stands.
   * @param string $note
   *   What became of it.
   */
  protected function note(string $pointer, string $note): void {
    $this->notes[$pointer === '' ? '/' : $pointer] = $note;
  }

  /**
   * Reads one frame: the root, a def, or an object a key holds.
   *
   * @param array $schema
   *   The object schema.
   * @param string $pointer
   *   Its pointer in the document.
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $inputs
   *   The blueprint its keys are declared in.
   * @param array $root
   *   The document.
   *
   * @throws \InvalidArgumentException
   *   When the frame is no object.
   */
  protected function frame(array $schema, string $pointer, ResourceBlueprint $inputs, array $root): void {
    // A def that is only a reference to another def is that def.
    if (array_keys(array_diff_key($schema, ['title' => TRUE, 'description' => TRUE])) === ['$ref'] && is_string($schema['$ref'])) {
      [$name, $def] = $this->def($schema['$ref'], static::below($pointer, '$ref'), $root);
      $this->frame($def, static::below('', '$defs', $name), $inputs, $root);
      return;
    }
    $this->assertObject($schema, $pointer);
    $extension = is_array($schema[ContractEmitter::EXTENSION] ?? NULL) ? $schema[ContractEmitter::EXTENSION] : [];
    $properties = is_array($schema['properties'] ?? NULL) ? $schema['properties'] : [];
    $required = array_map('strval', is_array($schema['required'] ?? NULL) ? $schema['required'] : []);
    $left = [];

    // Closed, as every frame of a structure is: a key it does not declare
    // is refused before anything else is read.
    $closed = ($schema['additionalProperties'] ?? NULL) === FALSE || ($schema['unevaluatedProperties'] ?? NULL) === FALSE;
    if (!$closed) {
      $this->note(static::below($pointer, 'additionalProperties'), 'Read closed: a structure holds the keys it declares, and a key the schema leaves open is refused.');
    }
    $this->frameExtension($extension, $pointer, $inputs);

    // The frame's allOf: structures mixed in, variants' conditionals,
    // rules, and what is left over.
    $members = [];
    $mixins = [];
    $conditionals = [];
    foreach (is_array($schema['allOf'] ?? NULL) ? $schema['allOf'] : [] as $i => $member) {
      $at = static::below($pointer, 'allOf', $i);
      $reference = is_array($member) && is_string($member['$ref'] ?? NULL) ? $member['$ref'] : NULL;
      if ($reference !== NULL && array_diff(array_keys($member), self::MIXED_IN) === []) {
        [$name, $def] = $this->def($member['$ref'], static::below($at, '$ref'), $root);
        if ($this->isObject($def)) {
          $mixins[$name] = $this->membersOf($def, $root);
          foreach ($mixins[$name] as $key) {
            $members[$key] = $name;
          }
          continue;
        }
      }
      if (is_array($member) && isset($member['if'], $member['then']) && !isset($member['else']) && is_array($member['if']) && is_array($member['then'])) {
        $conditionals[$i] = $member;
        continue;
      }
      $left['allOf'][] = static::plain($member);
      $this->note($at, 'Kept as a JsonSchema constraint on the frame: no key or rule says it.');
    }

    // Variants: a key a sibling's value chooses an object for.
    $variants = [];
    foreach ($properties as $name => $property) {
      $name = (string) $name;
      if (is_array($property) && !isset($members[$name])) {
        $found = $this->variantsAt($name, $property, $conditionals, $properties);
        if ($found !== NULL) {
          $variants[$name] = $found;
          foreach ($found['consumed'] as $i) {
            unset($conditionals[$i]);
          }
        }
      }
    }

    $mixed = [];
    foreach ($properties as $name => $property) {
      $name = (string) $name;
      $at = static::below($pointer, 'properties', $name);
      if (isset($members[$name])) {
        $structure = $members[$name];
        if (!isset($mixed[$structure])) {
          $mixed[$structure] = TRUE;
          $inputs->mixInRead($this->structureAt($inputs, $structure, $root, static::below('', '$defs', $structure)));
        }
        if (is_array($property) && array_diff(array_keys($property), [ContractEmitter::EXTENSION]) !== []) {
          $this->note($at, 'Not read: what an owner narrows of a member of a structure mixed in is said where the owner declares it.');
        }
        continue;
      }
      if (str_contains($name, '.')) {
        throw new \InvalidArgumentException(sprintf('%s: "%s" cannot be a key, which holds no dot: a dot is how a path names a key inside another.', $at, $name));
      }
      if ($property === FALSE) {
        $this->note($at, 'Not read: a key that may never be sent is no key.');
        continue;
      }
      if ($property === TRUE) {
        $property = [];
      }
      if (!is_array($property)) {
        throw new \InvalidArgumentException(sprintf('%s: a property is a schema, an object or a boolean.', $at));
      }
      if (isset($variants[$name])) {
        $this->variantsKey($name, $property, $variants[$name], in_array($name, $required, TRUE), $at, $inputs, $root, $properties);
        continue;
      }
      $this->key($name, $property, in_array($name, $required, TRUE), $at, $inputs, $root, array_map('strval', array_keys($properties)));
    }
    foreach (array_diff($required, array_map('strval', array_keys($properties))) as $name) {
      if (!isset($members[$name])) {
        $this->note(static::below($pointer, 'required'), sprintf('"%s" is required and declared nowhere: not read.', $name));
      }
    }

    // A rule may narrow a member of a structure mixed in, which the frame
    // says nothing of beside its place: read it as its def declares it.
    $declared = $properties;
    foreach (array_keys($mixins) as $structure) {
      $def = SchemaResolver::inline($root['$defs'][$structure], $root);
      foreach (is_array($def['properties'] ?? NULL) ? $def['properties'] : [] as $key => $member) {
        if (isset($members[(string) $key]) && is_array($member)) {
          $declared[(string) $key] = $member;
        }
      }
    }
    $this->rules($conditionals, $declared, $pointer, $inputs, $left);

    foreach (array_diff_key($schema, array_flip(self::FRAME)) as $keyword => $value) {
      $at = static::below($pointer, (string) $keyword);
      if (in_array($keyword, self::ANNOTATIONS, TRUE) || str_starts_with((string) $keyword, 'x-')) {
        $this->note($at, 'Dropped: an annotation, which checks nothing.');
        continue;
      }
      if (in_array($keyword, ['default', 'examples'], TRUE)) {
        $this->note($at, 'Dropped: what a whole frame starts from is each key\'s own default.');
        continue;
      }
      $left[(string) $keyword] = static::plain($value);
      $this->note($at, 'Kept as a JsonSchema constraint on the frame: no key or rule says it.');
    }
    if (($schema['readOnly'] ?? FALSE) === TRUE) {
      $this->note(static::below($pointer, 'readOnly'), 'Not read: a whole locked by an access answer is that answer\'s, not the structure\'s.');
    }
    if ($left !== []) {
      $inputs->constrainFrame($this->withDefs($left, $root));
    }
  }

  /**
   * Reads what a frame says of itself under `x-surface`.
   *
   * @param array $extension
   *   The frame's `x-surface`.
   * @param string $pointer
   *   The frame's pointer.
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $inputs
   *   The blueprint.
   */
  protected function frameExtension(array $extension, string $pointer, ResourceBlueprint $inputs): void {
    $at = static::below($pointer, ContractEmitter::EXTENSION);
    foreach (is_array($extension['ports'] ?? NULL) ? $extension['ports'] : [] as $port => $declared) {
      if (!is_array($declared) || !is_string($declared['type'] ?? NULL)) {
        continue;
      }
      $shape = is_string($declared['shape'] ?? NULL) ? $this->shape($declared['shape'], $inputs) : NULL;
      if (isset($declared['shape']) && $shape === NULL) {
        $this->note(static::below($at, 'ports', (string) $port, 'shape'), 'Read as the shape\'s type: the site has no shape of that id.');
      }
      $inputs->reads((string) $port, $shape ?? $declared['type'], (bool) ($declared['required'] ?? FALSE));
    }
    foreach (is_array($extension['checkedOnServer'] ?? NULL) ? $extension['checkedOnServer'] : [] as $i => $checked) {
      if (is_array($checked) && ($checked['constraint'] ?? NULL) === 'JsonSchema' && is_array($checked['schema'] ?? NULL)) {
        $inputs->constrainFrame($checked['schema']);
      }
      else {
        $this->note(static::below($at, 'checkedOnServer', $i), 'Not read: a constraint the server checks is known by its plugin alone.');
      }
    }
    if (is_int($extension['version'] ?? NULL) && $extension['version'] > 1) {
      $this->note(static::below($at, 'version'), 'Not read: a version counts the changes made to stored values, which are the declaring class\'s.');
    }
    foreach (['doesNotApply'] as $moment) {
      if (isset($extension[$moment])) {
        $this->note(static::below($at, $moment), 'Not read: it says what is true of these values or this account, not of the structure.');
      }
    }
  }

  /**
   * Lists the keys an object def holds, through what it mixes in itself.
   *
   * @param array $def
   *   The def.
   * @param array $root
   *   The document.
   * @param string[] $seen
   *   The defs read on the way.
   *
   * @return string[]
   *   The keys.
   */
  protected function membersOf(array $def, array $root, array $seen = []): array {
    $keys = array_map('strval', array_keys(is_array($def['properties'] ?? NULL) ? $def['properties'] : []));
    foreach (is_array($def['allOf'] ?? NULL) ? $def['allOf'] : [] as $member) {
      $name = is_array($member) && is_string($member['$ref'] ?? NULL) ? SchemaResolver::defName($member['$ref']) : NULL;
      if ($name !== NULL && !in_array($name, $seen, TRUE) && is_array($root['$defs'][$name] ?? NULL)) {
        array_push($keys, ...$this->membersOf($root['$defs'][$name], $root, [...$seen, $name]));
      }
    }
    return array_values(array_unique($keys));
  }

  /**
   * Refuses a frame that is no object.
   *
   * @param array $schema
   *   The schema.
   * @param string $pointer
   *   Its pointer.
   *
   * @throws \InvalidArgumentException
   *   When it is not.
   */
  protected function assertObject(array $schema, string $pointer): void {
    $types = array_values(array_diff((array) ($schema['type'] ?? 'object'), ['null']));
    if ($types !== ['object']) {
      throw new \InvalidArgumentException(sprintf('%s: what is read into a structure is an object; this is %s.', $pointer === '' ? '/' : $pointer, $types === [] ? 'null' : implode(' or ', array_map('strval', $types))));
    }
  }

  /**
   * Answers whether a schema is an object a frame can be read from.
   *
   * @param array $schema
   *   The schema.
   *
   * @return bool
   *   TRUE for an object schema, or a reference to one.
   */
  protected function isObject(array $schema): bool {
    $types = array_values(array_diff((array) ($schema['type'] ?? ''), ['null']));
    return $types === ['object'] || (!isset($schema['type']) && (isset($schema['properties']) || isset($schema['$ref'])));
  }

  /**
   * Reads the def a local reference points at.
   *
   * @param string $reference
   *   The `$ref`.
   * @param string $pointer
   *   Where the reference stands.
   * @param array $root
   *   The document.
   *
   * @return array{0: string, 1: array}
   *   The def's key and the def.
   *
   * @throws \InvalidArgumentException
   *   For a local reference to no def the document holds.
   */
  protected function def(string $reference, string $pointer, array $root): array {
    $name = SchemaResolver::defName($reference);
    if ($name === NULL || !is_array($root['$defs'][$name] ?? NULL)) {
      throw new \InvalidArgumentException(sprintf('%s: "%s" refers to no def this document holds under $defs.', $pointer, $reference));
    }
    return [$name, $root['$defs'][$name]];
  }

  /**
   * Answers whether a reference is to a def of the document.
   *
   * @param string $reference
   *   The `$ref`.
   *
   * @return bool
   *   TRUE for `#/$defs/<key>`.
   */
  protected static function isLocal(string $reference): bool {
    return SchemaResolver::defName($reference) !== NULL;
  }

  /**
   * Finds variants at a key: the objects a sibling's value chooses between.
   *
   * Said by the key's own `x-surface` (`by` and `variants`, as the
   * emitter writes them), or by the frame's conditionals alone: each an
   * `if` naming one sibling's `const` and a `then` giving the key an
   * object, the key itself a `oneOf` or `anyOf` of objects.
   *
   * @param string $name
   *   The key.
   * @param array $property
   *   Its schema.
   * @param array<int, array> $conditionals
   *   The frame's conditionals, by index.
   * @param array $properties
   *   The frame's properties.
   *
   * @return array{by: string, variants: array<string, array{schema: array, pointer: string|null, index: int|null}>, consumed: int[]}|null
   *   The deciding key, each value's object (its schema, and the pointer
   *   of a def it refers to), and the conditionals that state them; NULL
   *   when the key holds no variants.
   */
  protected function variantsAt(string $name, array $property, array $conditionals, array $properties): ?array {
    $extension = is_array($property[ContractEmitter::EXTENSION] ?? NULL) ? $property[ContractEmitter::EXTENSION] : [];
    $by = is_string($extension['by'] ?? NULL) ? $extension['by'] : NULL;
    $union = is_array($property['anyOf'] ?? NULL) ? $property['anyOf'] : (is_array($property['oneOf'] ?? NULL) ? $property['oneOf'] : NULL);
    $objects = is_array($union) && $union !== [] && array_filter($union, fn (mixed $member): bool => !is_array($member) || (!$this->isObject($member) && $member !== ['type' => 'null'])) === [];
    if ($by === NULL && !$objects) {
      return NULL;
    }
    $found = [];
    $consumed = [];
    foreach ($conditionals as $i => $conditional) {
      $then = $conditional['then'];
      $if = $conditional['if'];
      $test = static::condition($if);
      if ($test === NULL || $test->kind !== 'equals' || ($by !== NULL && $test->key !== $by) || array_keys($then) !== ['properties'] || array_keys($then['properties']) !== [$name] || !is_array($then['properties'][$name])) {
        continue;
      }
      $chosen = $then['properties'][$name];
      unset($chosen['title'], $chosen['description']);
      $first = is_array($chosen['allOf'][0] ?? NULL) ? $chosen['allOf'][0] : [];
      if (!isset($chosen['$ref']) && is_string($first['$ref'] ?? NULL) && count($first) === 1) {
        // The variant at this site, narrowed by what its access locked:
        // the variant is the def, and the lock its access's.
        $chosen = ['$ref' => $first['$ref']];
      }
      if (!$this->isObject($chosen)) {
        continue;
      }
      $by ??= $test->key;
      if ($test->key !== $by || !isset($properties[$by])) {
        continue;
      }
      $value = is_bool($test->operand) ? ($test->operand ? '1' : '0') : (string) $test->operand;
      $found[$value] = ['schema' => $chosen, 'index' => $i];
      $consumed[] = $i;
    }
    if ($by === NULL) {
      return NULL;
    }
    $variants = [];
    foreach ($found as $value => ['schema' => $chosen, 'index' => $i]) {
      $variants[$value] = [
        'schema' => $chosen,
        'pointer' => is_string($chosen['$ref'] ?? NULL) && count($chosen) === 1 && static::isLocal($chosen['$ref']) ? static::below('', '$defs', (string) SchemaResolver::defName($chosen['$ref'])) : NULL,
        'index' => $i,
      ];
    }
    // A value the key's own `x-surface` names with no conditional of its
    // own still chooses the def it names.
    foreach (is_array($extension['variants'] ?? NULL) ? $extension['variants'] : [] as $value => $reference) {
      if (!isset($variants[(string) $value]) && is_string($reference) && static::isLocal($reference)) {
        $variants[(string) $value] = [
          'schema' => ['$ref' => $reference],
          'pointer' => static::below('', '$defs', (string) SchemaResolver::defName($reference)),
          'index' => NULL,
        ];
      }
    }
    if ($variants === [] && $union === NULL && is_array($extension['deferred'] ?? NULL)) {
      // Variants a site lists past its budget, deferred: no variant is in
      // the document to read, so the key is an object like any other.
      // Decision: see
      // docs/decisions.md#a-list-left-out-for-size-is-deferred-not-refined.
      return NULL;
    }
    return ['by' => $by, 'variants' => $variants, 'consumed' => $consumed];
  }

  /**
   * Declares a key holding variants.
   *
   * @param string $name
   *   The key.
   * @param array $property
   *   Its schema.
   * @param array{by: string, variants: array<string, array{schema: array, pointer: string|null, index: int|null}>, consumed: int[]} $found
   *   What variantsAt() found.
   * @param bool $required
   *   Whether the frame requires it.
   * @param string $pointer
   *   The key's pointer.
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $inputs
   *   The blueprint.
   * @param array $root
   *   The document.
   * @param array $properties
   *   The frame's properties, for the deciding key's labels.
   */
  protected function variantsKey(string $name, array $property, array $found, bool $required, string $pointer, ResourceBlueprint $inputs, array $root, array $properties): void {
    $labels = [];
    $deciding = is_array($properties[$found['by']] ?? NULL) ? $properties[$found['by']] : [];
    foreach (is_array($deciding['oneOf'] ?? NULL) ? $deciding['oneOf'] : [] as $choice) {
      if (is_array($choice) && array_key_exists('const', $choice) && is_string($choice['title'] ?? NULL) && $choice['const'] !== NULL) {
        $labels[is_bool($choice['const']) ? ($choice['const'] ? '1' : '0') : (string) $choice['const']] = $choice['title'];
      }
    }
    if (is_scalar($deciding['const'] ?? NULL) && is_string($deciding[ContractEmitter::EXTENSION]['fixed'] ?? NULL)) {
      // The one value a deciding key may hold, and the label it is drawn
      // with.
      $labels[(string) $deciding['const']] = $deciding[ContractEmitter::EXTENSION]['fixed'];
    }
    $owner = $inputs->ownerId();
    $variants = [];
    foreach ($found['variants'] as $value => $variant) {
      if ($variant['pointer'] !== NULL) {
        $id = (string) SchemaResolver::defName('#' . $variant['pointer']);
        $this->def('#' . $variant['pointer'], $pointer, $root);
        $at = $variant['pointer'];
      }
      else {
        $id = $owner . '.' . $name . '.' . $value;
        $at = static::below('', 'allOf', (int) $variant['index'], 'then', 'properties', $name);
        $at = $this->located($root, $at) ?? $at;
      }
      $variants[(string) $value] = [
        'name' => $this->structureAt($inputs, $id, $root, $at),
        'label' => $labels[(string) $value] ?? NULL,
      ];
    }
    $shell = $inputs->attachReadVariants($name, $found['by'], $variants);
    $this->describeShell($shell, $property, $name, $required, $pointer);
  }

  /**
   * Finds where a frame's subschema stands in the document.
   *
   * A frame read below the root says its pointers from its own object;
   * a variant stated inline in its `then` is read from where it is.
   *
   * @param array $root
   *   The document.
   * @param string $pointer
   *   The pointer from the frame.
   *
   * @return string|null
   *   The pointer from the root, when the frame is the root.
   */
  protected function located(array $root, string $pointer): ?string {
    try {
      static::at($root, $pointer);
      return $pointer;
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
  }

  /**
   * Labels and describes the map a child is held in.
   *
   * @param \Drupal\Core\TypedData\MapDataDefinition $shell
   *   The map attach() handed back.
   * @param array $property
   *   The key's schema.
   * @param string $name
   *   The key.
   * @param bool $required
   *   Whether the frame requires it.
   * @param string $pointer
   *   The key's pointer.
   */
  protected function describeShell(MapDataDefinition $shell, array $property, string $name, bool $required, string $pointer): void {
    if (is_string($property['title'] ?? NULL)) {
      $shell->setLabel($property['title']);
    }
    if (is_string($property['description'] ?? NULL)) {
      $shell->setDescription($property['description']);
    }
    if ($required) {
      $shell->setRequired(TRUE);
    }
  }

  /**
   * Reads one property into a key.
   *
   * @param string $name
   *   The key.
   * @param array $property
   *   Its schema.
   * @param bool $required
   *   Whether the frame requires it.
   * @param string $pointer
   *   Its pointer.
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $inputs
   *   The blueprint.
   * @param array $root
   *   The document.
   * @param string[] $keys
   *   The frame's keys, which a port may be wired to.
   */
  protected function key(string $name, array $property, bool $required, string $pointer, ResourceBlueprint $inputs, array $root, array $keys = []): void {
    $extension = is_array($property[ContractEmitter::EXTENSION] ?? NULL) ? $property[ContractEmitter::EXTENSION] : [];
    $this->droppedExtension($extension, static::below($pointer, ContractEmitter::EXTENSION));
    $reference = $this->siteReference($property, $pointer);

    // A child: an object def it refers to, or an object of its own.
    if ($reference !== NULL) {
      [$def_name, $def] = $this->def($reference, static::below($pointer, '$ref'), $root);
      if ($this->isObject($def)) {
        $reads = $this->wiring($extension, $def, static::below($pointer, ContractEmitter::EXTENSION, 'reads'), $keys);
        $from = $root;
        foreach (is_array($def[ContractEmitter::EXTENSION]['ports'] ?? NULL) ? $def[ContractEmitter::EXTENSION]['ports'] : [] as $port => $declared) {
          if (!isset($reads[$port]) && is_array($declared) && ($declared['required'] ?? FALSE) === TRUE) {
            // Nothing here can wire it: read so that nothing has to.
            $from['$defs'][$def_name][ContractEmitter::EXTENSION]['ports'][$port]['required'] = FALSE;
            $this->note(static::below('', '$defs', $def_name, ContractEmitter::EXTENSION, 'ports', (string) $port, 'required'), sprintf('Read optional where %s attaches it unwired.', $pointer));
          }
        }
        $shell = $inputs->attach($name, $this->structureAt($inputs, $def_name, $from, static::below('', '$defs', $def_name)), $reads);
        $this->describeShell($shell, $property, $name, $required, $pointer);
        return;
      }
      $shape = $this->shape($def_name, $inputs);
      if ($shape !== NULL) {
        $this->shapedKey($name, $property, $def, $shape, $required, $pointer, $inputs);
        return;
      }
      // A scalar def the site has no shape for: the def where it stands.
      $this->note(static::below($pointer, '$ref'), sprintf('Read in place: the site has no shape "%s", so the def is the key\'s own.', $def_name));
      $site = $property;
      unset($site['$ref']);
      $property = SchemaResolver::inline(['$ref' => $reference], $root);
      foreach ($site as $keyword => $value) {
        $property[$keyword] = $value;
      }
    }
    $types = $this->types($property, $pointer);
    $not_null = array_values(array_diff($types, ['null']));
    if ($not_null === ['object'] && (is_array($property['properties'] ?? NULL) && $property['properties'] !== [])) {
      if ($this->isPlainMap($property)) {
        $map = $this->map($property, $pointer, $root);
        $inputs->addDefinition($name, $this->finished($map, $property, $required, in_array('null', $types, TRUE)));
        return;
      }
      $shell = $inputs->attach($name, $this->structureAt($inputs, $inputs->ownerId() . '.' . $name, $root, $pointer));
      $this->describeShell($shell, $property, $name, $required, $pointer);
      return;
    }
    if ($not_null === ['array'] && is_array($property['items'] ?? NULL) && ($this->isObject($property['items']) || $this->refersToObject($property['items'], $root))) {
      $this->collection($name, $property, $required, $pointer, $inputs, $root);
      return;
    }
    [$definition, $default] = $this->value($property, $pointer, $root, $types);
    $locked = ($property['readOnly'] ?? FALSE) === TRUE;
    if ($locked && array_key_exists('const', $property)) {
      // Locked at what it holds: the value is what it starts from.
      $default = $property['const'];
    }
    $inputs->addDefinition($name, $this->finished($definition, $property, $required, in_array('null', $types, TRUE)), $default);
    if ($locked) {
      $inputs->lockRead($name);
    }
  }

  /**
   * Reads which reference a site makes, and notes what narrows it.
   *
   * @param array $property
   *   The property.
   * @param string $pointer
   *   Its pointer.
   *
   * @return string|null
   *   The `$ref`, or NULL for none.
   */
  protected function siteReference(array $property, string $pointer): ?string {
    if (is_string($property['$ref'] ?? NULL)) {
      // A reference to another document, or anywhere but a def, is kept
      // whole by foreignValue().
      return static::isLocal($property['$ref']) ? $property['$ref'] : NULL;
    }
    $all = is_array($property['allOf'] ?? NULL) ? $property['allOf'] : [];
    $first = $all[0] ?? NULL;
    if (is_array($first) && is_string($first['$ref'] ?? NULL) && count($first) === 1 && static::isLocal($first['$ref'])) {
      if (count($all) > 1) {
        $this->note(static::below($pointer, 'allOf', 1), 'Not read: what a site narrows of a child (a lock its context made) is the context\'s, not the child\'s.');
      }
      return $first['$ref'];
    }
    return NULL;
  }

  /**
   * Answers whether a schema refers to an object def.
   *
   * @param array $schema
   *   The schema.
   * @param array $root
   *   The document.
   *
   * @return bool
   *   TRUE when it does.
   */
  protected function refersToObject(array $schema, array $root): bool {
    $name = SchemaResolver::refersTo($schema);
    return $name !== NULL && is_array($root['$defs'][$name] ?? NULL) && $this->isObject($root['$defs'][$name]);
  }

  /**
   * Reads which of a container's keys a child's ports read.
   *
   * @param array $extension
   *   The site's `x-surface`.
   * @param array $def
   *   The child's def.
   * @param string $pointer
   *   Where `reads` stands.
   * @param string[] $keys
   *   The keys of the frame the child is attached in.
   *
   * @return array<string, string>
   *   Each port the def reads, mapped to the key wired to it.
   */
  protected function wiring(array $extension, array $def, string $pointer, array $keys): array {
    $reads = is_array($extension['reads'] ?? NULL) ? $extension['reads'] : [];
    $ports = is_array($def[ContractEmitter::EXTENSION]['ports'] ?? NULL) ? $def[ContractEmitter::EXTENSION]['ports'] : [];
    $wired = [];
    foreach ($reads as $port => $key) {
      if (!isset($ports[$port]) || !is_string($key)) {
        $this->note($pointer, 'Not read: each entry of a map from the site reads its own port, which only a provider of keys can wire.');
        return [];
      }
      if (!in_array($key, $keys, TRUE)) {
        $this->note($pointer, 'Not read: a port wired to a key outside the object holding the child, as an alter\'s mount reads its owner\'s keys, has nothing here to read.');
        return [];
      }
      $wired[(string) $port] = $key;
    }
    return $wired;
  }

  /**
   * Finds the shape of an id.
   *
   * @param string $id
   *   The def's key.
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $inputs
   *   The blueprint, which knows the site's shapes.
   *
   * @return string|null
   *   The shape's class, or NULL when the site has none of that id.
   */
  protected function shape(string $id, ResourceBlueprint $inputs): ?string {
    return $inputs->shapeOf($id);
  }

  /**
   * Declares a key with a shape, and what its site says beside it.
   *
   * @param string $name
   *   The key.
   * @param array $property
   *   Its site: `$ref`, then what it says differently from the shape.
   * @param array $def
   *   The shape's def.
   * @param string $shape
   *   The shape's class.
   * @param bool $required
   *   Whether the frame requires it.
   * @param string $pointer
   *   Its pointer.
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $inputs
   *   The blueprint.
   */
  protected function shapedKey(string $name, array $property, array $def, string $shape, bool $required, string $pointer, ResourceBlueprint $inputs): void {
    $definition = $inputs->add($name, $shape, is_string($property['title'] ?? NULL) ? $property['title'] : '');
    $site = array_diff_key($property, ['$ref' => TRUE, 'title' => TRUE]);
    if (is_string($site['description'] ?? NULL)) {
      $definition->setDescription($site['description']);
    }
    if ($required) {
      $definition->setRequired(TRUE);
    }
    if (array_key_exists('default', $site) && $site['default'] !== NULL) {
      DefinitionMetadata::setDefaultValue($definition, $site['default']);
    }
    $types = isset($site['type']) ? array_values(array_diff((array) $site['type'], ['null'])) : [];
    $narrowing = $site + ['type' => $def['type'] ?? NULL];
    foreach ($this->constraints($narrowing, $pointer, $types, $foreign) as [$constraint, $options]) {
      // What the site narrows of a bound the shape holds is that bound,
      // tightened: the rest of it stays the shape's.
      $held = $definition->getConstraints()[$constraint] ?? [];
      $definition->addConstraint($constraint, in_array($constraint, ['Range', 'Length', 'Count'], TRUE) ? array_replace($held, $options) : $options);
    }
    if ($foreign !== []) {
      $definition->addConstraint('JsonSchema', ['schema' => $foreign]);
    }
    $this->settings($definition, is_array($property[ContractEmitter::EXTENSION] ?? NULL) ? $property[ContractEmitter::EXTENSION] : []);
  }

  /**
   * Declares a collection: an array whose items are each a structure.
   *
   * @param string $name
   *   The key.
   * @param array $property
   *   Its schema.
   * @param bool $required
   *   Whether the frame requires it.
   * @param string $pointer
   *   Its pointer.
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $inputs
   *   The blueprint.
   * @param array $root
   *   The document.
   */
  protected function collection(string $name, array $property, bool $required, string $pointer, ResourceBlueprint $inputs, array $root): void {
    $items = $property['items'];
    $reference = $this->siteReference($items, static::below($pointer, 'items'));
    if ($reference !== NULL) {
      [$def_name] = $this->def($reference, static::below($pointer, 'items', '$ref'), $root);
      $child = $this->structureAt($inputs, $def_name, $root, static::below('', '$defs', $def_name));
    }
    else {
      $child = $this->structureAt($inputs, $inputs->ownerId() . '.' . $name, $root, static::below($pointer, 'items'));
    }
    $min = is_int($property['minItems'] ?? NULL) ? $property['minItems'] : NULL;
    $max = is_int($property['maxItems'] ?? NULL) ? $property['maxItems'] : NULL;
    $list = $inputs->attachList($name, $child, $min, $max);
    if (($property['uniqueItems'] ?? FALSE) === TRUE) {
      $list->addConstraint('UniqueItems', []);
    }
    if (is_string($property['title'] ?? NULL)) {
      $list->setLabel($property['title']);
    }
    if (is_string($property['description'] ?? NULL)) {
      $list->setDescription($property['description']);
    }
    if ($required) {
      $list->setRequired(TRUE);
    }
    if (!in_array('null', (array) ($property['type'] ?? []), TRUE) && !$required) {
      DefinitionMetadata::setAlwaysHeld($list);
    }
    foreach (array_diff_key($property, array_flip(self::COLLECTION)) as $keyword => $value) {
      $this->note(static::below($pointer, (string) $keyword), 'Not read: a collection says its title, its description, how many items it holds and whether they differ.');
    }
  }

  /**
   * Answers whether an object is a plain map: keys and nothing more.
   *
   * @param array $schema
   *   The object.
   *
   * @return bool
   *   TRUE when every property is a value, a list or a plain map, and the
   *   object says nothing a map cannot (a reference, a rule, variants).
   */
  protected function isPlainMap(array $schema): bool {
    if (array_diff(array_keys($schema), self::MAP) !== []) {
      return FALSE;
    }
    if (array_key_exists('additionalProperties', $schema) && $schema['additionalProperties'] !== FALSE) {
      return FALSE;
    }
    foreach ($schema['properties'] as $property) {
      if (!is_array($property) || isset($property['$ref']) || isset($property['allOf']) || isset($property[ContractEmitter::EXTENSION]['by'])) {
        return FALSE;
      }
      $types = array_values(array_diff((array) ($property['type'] ?? []), ['null']));
      if ($types === ['object'] && is_array($property['properties'] ?? NULL) && $property['properties'] !== [] && !$this->isPlainMap($property)) {
        return FALSE;
      }
      if ($types === ['array'] && is_array($property['items'] ?? NULL) && $this->isObject($property['items'])) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Reads a plain map.
   *
   * @param array $schema
   *   The object.
   * @param string $pointer
   *   Its pointer.
   * @param array $root
   *   The document.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The map, each property a definition of its own.
   */
  protected function map(array $schema, string $pointer, array $root): MapDataDefinition {
    $map = MapDataDefinition::create();
    if (($schema['additionalProperties'] ?? NULL) !== FALSE) {
      $this->note(static::below($pointer, 'additionalProperties'), 'Read closed: a map holds the keys it declares, and a key the schema leaves open is refused.');
    }
    $extension = is_array($schema[ContractEmitter::EXTENSION] ?? NULL) ? $schema[ContractEmitter::EXTENSION] : [];
    if (($extension['group'] ?? FALSE) === TRUE) {
      DefinitionMetadata::setGrouping($map);
    }
    if (is_string($extension['after'] ?? NULL)) {
      DefinitionMetadata::setPlacedAfter($map, $extension['after']);
    }
    $required = array_map('strval', is_array($schema['required'] ?? NULL) ? $schema['required'] : []);
    foreach ($schema['properties'] as $name => $property) {
      $name = (string) $name;
      $at = static::below($pointer, 'properties', $name);
      $types = $this->types($property, $at);
      if (array_values(array_diff($types, ['null'])) === ['object'] && is_array($property['properties'] ?? NULL) && $property['properties'] !== []) {
        $inner = $this->map($property, $at, $root);
      }
      else {
        [$inner, $default] = $this->value($property, $at, $root, $types);
        if ($default !== NULL) {
          DefinitionMetadata::setDefaultValue($inner, $default);
        }
      }
      $this->droppedExtension(is_array($property[ContractEmitter::EXTENSION] ?? NULL) ? $property[ContractEmitter::EXTENSION] : [], static::below($at, ContractEmitter::EXTENSION));
      $map->setPropertyDefinition($name, $this->finished($inner, $property, in_array($name, $required, TRUE), in_array('null', $types, TRUE)));
    }
    return $map;
  }

  /**
   * Gives a definition what every key has: its label, and what it holds.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param array $property
   *   Its schema.
   * @param bool $required
   *   Whether its frame requires it.
   * @param bool $nullable
   *   Whether its type allows null.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The same.
   */
  protected function finished(DataDefinitionInterface $definition, array $property, bool $required, bool $nullable): DataDefinitionInterface {
    if ($definition instanceof DataDefinition) {
      if (is_string($property['title'] ?? NULL)) {
        $definition->setLabel($property['title']);
      }
      if (is_string($property['description'] ?? NULL)) {
        $definition->setDescription($property['description']);
      }
      if ($required) {
        $definition->setRequired(TRUE);
      }
    }
    if (($property['writeOnly'] ?? FALSE) === TRUE) {
      DefinitionMetadata::setSecret($definition);
    }
    elseif (!$required && !$nullable && array_key_exists('default', $property) && $property['default'] !== NULL && ($property['readOnly'] ?? FALSE) !== TRUE) {
      // An optional key whose type refuses null, and that starts from a
      // value: one its storage always holds, which null cannot clear.
      // Decision: see docs/decisions.md#a-key-its-storage-always-holds.
      DefinitionMetadata::setAlwaysHeld($definition);
    }
    return $definition;
  }

  /**
   * Notes what a key's `x-surface` says that is not read back.
   *
   * @param array $extension
   *   The key's `x-surface`.
   * @param string $pointer
   *   Where it stands.
   */
  protected function droppedExtension(array $extension, string $pointer): void {
    if (($extension['refine'] ?? FALSE) === TRUE) {
      $this->note(static::below($pointer, 'refine'), 'Not read: a rule only the server knows, which the key is as wide as without.');
    }
    if (($extension['live'] ?? FALSE) === TRUE) {
      $this->note(static::below($pointer, 'live'), 'Not read: settled per request, by a rule or an access answer only the server knows, which the key is as wide as without.');
    }
    if (is_array($extension['deferred'] ?? NULL)) {
      $this->note(static::below($pointer, 'deferred'), 'Not read: a list the site decides, left out of this document for its size, so the key is read open.');
    }
  }

  /**
   * Reads the JSON types a property allows.
   *
   * @param array|bool $property
   *   The property.
   * @param string $pointer
   *   Its pointer.
   *
   * @return string[]
   *   The types, `null` among them where it is allowed; inferred from a
   *   list of values or a `const` where no `type` is given; none for a
   *   property that says nothing of its type.
   *
   * @throws \InvalidArgumentException
   *   For a type JSON Schema does not have.
   */
  protected function types(array|bool $property, string $pointer): array {
    if (!is_array($property)) {
      return [];
    }
    if (isset($property['type'])) {
      $types = array_map(static fn (mixed $type): string => is_string($type) ? $type : json_encode($type), (array) $property['type']);
      foreach ($types as $type) {
        if (!in_array($type, self::TYPES, TRUE)) {
          throw new \InvalidArgumentException(sprintf('%s: "%s" is no JSON Schema type.', static::below($pointer, 'type'), $type));
        }
      }
      return $types;
    }
    $values = [];
    if (is_array($property['oneOf'] ?? NULL)) {
      foreach ($property['oneOf'] as $choice) {
        if (!is_array($choice) || !array_key_exists('const', $choice)) {
          return [];
        }
        $values[] = $choice['const'];
      }
    }
    elseif (is_array($property['enum'] ?? NULL)) {
      $values = $property['enum'];
    }
    elseif (array_key_exists('const', $property)) {
      $values = [$property['const']];
    }
    $types = [];
    foreach ($values as $value) {
      $types[] = match (TRUE) {
        $value === NULL => 'null',
        is_bool($value) => 'boolean',
        is_int($value) => 'integer',
        is_float($value) => 'number',
        is_string($value) => 'string',
        default => 'object',
      };
    }
    $types = array_values(array_unique($types));
    if (in_array('integer', $types, TRUE) && in_array('number', $types, TRUE)) {
      $types = array_values(array_diff($types, ['integer']));
    }
    return $types;
  }

  /**
   * Reads a value: a scalar, a list of scalars, or anything else held whole.
   *
   * @param array $property
   *   The property.
   * @param string $pointer
   *   Its pointer.
   * @param array $root
   *   The document.
   * @param string[] $types
   *   Its types, as types() read them.
   *
   * @return array{0: \Drupal\Core\TypedData\DataDefinitionInterface, 1: mixed}
   *   The definition, and the default it starts from (NULL for none).
   */
  protected function value(array $property, string $pointer, array $root, array $types): array {
    $not_null = array_values(array_diff($types, ['null']));
    $default = $property['default'] ?? NULL;
    if ($not_null === ['array'] && !isset($property['prefixItems'])) {
      $items = is_array($property['items'] ?? NULL) ? $property['items'] : [];
      $item_types = $this->types($items, static::below($pointer, 'items'));
      if (array_values(array_diff($item_types, ['null'])) === ['array'] || ($items !== [] && $this->isObject($items))) {
        $item = $this->foreignValue($items, static::below($pointer, 'items'), $root);
      }
      else {
        [$item] = $this->value($items, static::below($pointer, 'items'), $root, $item_types === [] ? [] : array_values(array_diff($item_types, ['null'])));
        if ($item instanceof DataDefinition && is_string($items['title'] ?? NULL)) {
          $item->setLabel($items['title']);
        }
      }
      $list = new ListDataDefinition(['type' => 'list'], $item);
      $foreign = [];
      $bounds = array_diff_key($property, ['items' => TRUE]);
      foreach ($this->constraints($bounds, $pointer, ['array'], $foreign) as [$constraint, $options]) {
        $list->addConstraint($constraint, $options);
      }
      if ($foreign !== []) {
        $list->addConstraint('JsonSchema', ['schema' => $this->withDefs($foreign, $root)]);
      }
      $this->settings($list, is_array($property[ContractEmitter::EXTENSION] ?? NULL) ? $property[ContractEmitter::EXTENSION] : []);
      $this->examples($list, $property);
      return [$list, $default];
    }
    if (count($not_null) !== 1 || in_array($not_null[0], ['object', 'array', 'null'], TRUE)) {
      return [$this->foreignValue($property, $pointer, $root), $default];
    }
    $type = match ($not_null[0]) {
      'integer' => 'integer',
      'number' => 'float',
      'boolean' => 'boolean',
      default => 'string',
    };
    $format = is_string($property['format'] ?? NULL) ? $property['format'] : NULL;
    if ($type === 'string' && $format !== NULL) {
      if (isset(self::FORMATS[$format])) {
        $type = self::FORMATS[$format];
        if ($format === 'email') {
          $this->note(static::below($pointer, 'format'), 'Read as an email address, which Drupal holds to RFC 6531: written back as idn-email.');
        }
      }
      elseif (!isset(self::FORMAT_PATTERNS[$format]) && $format !== 'uuid') {
        $this->note(static::below($pointer, 'format'), sprintf('Dropped: no constraint checks the "%s" format, an annotation.', $format));
      }
    }
    $definition = DataDefinition::create($type);
    $foreign = [];
    foreach ($this->constraints($property, $pointer, $not_null, $foreign) as [$constraint, $options]) {
      $definition->addConstraint($constraint, $options);
    }
    if ($type === 'string' && $format !== NULL && isset(self::FORMAT_PATTERNS[$format]) && !isset($property['pattern'])) {
      $definition->addConstraint('Regex', ['pattern' => self::FORMAT_PATTERNS[$format]]);
    }
    if ($type === 'string' && $format === 'uuid') {
      $definition->addConstraint('Uuid', []);
    }
    if ($type === 'string' && $format === 'date' && !isset($property['oneOf']) && !isset($property['enum'])) {
      // A date is drawn as a date input, which is what the emitter states
      // the format for; an `x-surface.widget` read below still decides.
      $definition->setSetting('widget', 'date');
    }
    if ($foreign !== []) {
      $definition->addConstraint('JsonSchema', ['schema' => $this->withDefs($foreign, $root)]);
    }
    $this->settings($definition, is_array($property[ContractEmitter::EXTENSION] ?? NULL) ? $property[ContractEmitter::EXTENSION] : []);
    $this->examples($definition, $property);
    return [$definition, $default];
  }

  /**
   * Reads a value no data type of its own says: held whole to its schema.
   *
   * @param array $property
   *   The property.
   * @param string $pointer
   *   Its pointer.
   * @param array $root
   *   The document.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   An `any` definition, held to the property's schema.
   */
  protected function foreignValue(array $property, string $pointer, array $root): DataDefinition {
    $definition = DataDefinition::create('any');
    $kept = static::plain(array_diff_key($property, array_flip(self::WHOLE)));
    if ($kept !== []) {
      $definition->addConstraint('JsonSchema', ['schema' => $this->withDefs($kept, $root)]);
      $this->note($pointer, sprintf('Kept as a JsonSchema constraint: %s, which no data type says.', implode(', ', array_keys($kept))));
    }
    $this->examples($definition, $property);
    return $definition;
  }

  /**
   * Reads the JSON types a value held whole to its schema was stated as.
   *
   * A property no data type says, a value of several JSON types among
   * them, is read as `any` with its schema kept whole on a JsonSchema
   * constraint (foreignValue()); the types are that schema's `type`, which
   * is where a widget reads them.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return string[]
   *   The types its kept schema states, `null` among them where it is
   *   allowed, in the order stated; none for a definition that keeps no
   *   schema or a schema that states no type.
   */
  public static function statedTypes(DataDefinitionInterface $definition): array {
    $schema = $definition->getConstraints()['JsonSchema']['schema'] ?? NULL;
    $types = is_array($schema) ? ($schema['type'] ?? []) : [];
    return array_values(array_filter((array) $types, static fn (mixed $type): bool => is_string($type) && in_array($type, self::TYPES, TRUE)));
  }

  /**
   * Reads a value's examples.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param array $property
   *   Its schema.
   */
  protected function examples(DataDefinitionInterface $definition, array $property): void {
    if (is_array($property['examples'] ?? NULL) && $property['examples'] !== []) {
      DefinitionMetadata::setExamples($definition, array_values($property['examples']));
    }
  }

  /**
   * Reads what a key's `x-surface` asks of how it is drawn.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param array $extension
   *   The key's `x-surface`.
   */
  protected function settings(DataDefinitionInterface $definition, array $extension): void {
    if (!$definition instanceof DataDefinition) {
      return;
    }
    $widget = $extension['widget'] ?? NULL;
    if (in_array($widget, ['radios', 'time', 'date', 'checkboxes'], TRUE)) {
      $definition->setSetting('widget', $widget);
    }
    elseif ($widget === 'textarea') {
      $definition->setSetting('multiline', TRUE);
    }
  }

  /**
   * Reads the constraints a value's keywords say.
   *
   * @param array $property
   *   The value's schema.
   * @param string $pointer
   *   Its pointer.
   * @param string[] $types
   *   Its types, null left out.
   * @param array|null $foreign
   *   Collects what no constraint says: the keywords kept for a
   *   JsonSchema constraint.
   *
   * @param-out array $foreign
   *
   * @return array<int, array{0: string, 1: array}>
   *   Each constraint and its options, in the order the keywords stand.
   */
  protected function constraints(array $property, string $pointer, array $types, ?array &$foreign = []): array {
    $foreign = [];
    $constraints = [];
    $numeric = array_intersect($types, ['integer', 'number']) !== [];
    $integer = $types === ['integer'];
    $bounds = [];
    $unique = FALSE;
    $message = is_string($property[ContractEmitter::EXTENSION]['patternMessage'] ?? NULL) ? $property[ContractEmitter::EXTENSION]['patternMessage'] : NULL;
    foreach ($property as $keyword => $value) {
      $at = static::below($pointer, (string) $keyword);
      if (isset(self::BOUNDS[$keyword]) && (is_int($value) || is_float($value))) {
        [$constraint, $option] = self::BOUNDS[$keyword];
        if ($constraint === 'Range' && !$numeric) {
          $foreign[$keyword] = $value;
          continue;
        }
        $bounds[$constraint][$option] = $value;
        continue;
      }
      switch ($keyword) {
        case 'uniqueItems':
          // Read after the bounds, where the emitter writes it. False is
          // what an array says with no keyword at all.
          if ($types === ['array'] && is_bool($value)) {
            $unique = $value;
          }
          else {
            $foreign[$keyword] = static::plain($value);
            $this->note($at, 'Kept as a JsonSchema constraint: no constraint of the vocabulary says it.');
          }
          break;

        case 'exclusiveMinimum':
        case 'exclusiveMaximum':
          if ($integer && (is_int($value) || is_float($value))) {
            // Exact for an integer: the next whole number in.
            $bounds['Range'][$keyword === 'exclusiveMinimum' ? 'min' : 'max'] = $keyword === 'exclusiveMinimum' ? (int) floor($value) + 1 : (int) ceil($value) - 1;
          }
          else {
            $foreign[$keyword] = $value;
          }
          break;

        case 'pattern':
          $regex = is_string($value) ? static::regex($value) : NULL;
          if ($regex === NULL) {
            $foreign[$keyword] = $value;
            break;
          }
          $constraints[] = ['Regex', ['pattern' => $regex] + ($message === NULL ? [] : ['message' => $message])];
          break;

        case 'not':
          $regex = is_array($value) && array_keys($value) === ['pattern'] && is_string($value['pattern']) ? static::regex($value['pattern']) : NULL;
          if ($regex === NULL) {
            $foreign[$keyword] = static::plain($value);
            break;
          }
          $options = ['pattern' => $regex, 'match' => FALSE];
          $constraints[] = ['Regex', $options + ($message === NULL ? [] : ['message' => $message])];
          break;

        case 'oneOf':
        case 'enum':
        case 'const':
          $choice = $this->choice($property, $keyword);
          if ($choice === NULL) {
            if ($keyword !== 'const' || ($property['readOnly'] ?? FALSE) !== TRUE) {
              $foreign[$keyword] = static::plain($value);
            }
            break;
          }
          if ($choice !== []) {
            $constraints[] = $choice;
          }
          break;

        case 'allOf':
        case 'anyOf':
          $parts = is_array($value) ? $this->parts($value, $types) : NULL;
          if ($parts === NULL) {
            $foreign[$keyword] = static::plain($value);
            break;
          }
          $constraints[] = [$keyword === 'allOf' ? 'Sequentially' : 'AtLeastOneOf', ['constraints' => $parts]];
          break;

        case ContractEmitter::EXTENSION:
          foreach (is_array($value['checkedOnServer'] ?? NULL) ? $value['checkedOnServer'] : [] as $i => $checked) {
            if (is_array($checked) && ($checked['constraint'] ?? NULL) === 'JsonSchema' && is_array($checked['schema'] ?? NULL)) {
              $constraints[] = ['JsonSchema', ['schema' => $checked['schema']]];
            }
            else {
              $this->note(static::below($at, 'checkedOnServer', $i), 'Not read: a constraint the server checks is known by its plugin alone.');
            }
          }
          break;

        default:
          if (in_array($keyword, self::VALUE, TRUE) || in_array($keyword, ['items', '$ref', 'minItems', 'maxItems'], TRUE)) {
            break;
          }
          if (in_array($keyword, self::ANNOTATIONS, TRUE) || str_starts_with((string) $keyword, 'x-')) {
            $this->note($at, 'Dropped: an annotation, which checks nothing.');
            break;
          }
          $foreign[$keyword] = static::plain($value);
          $this->note($at, 'Kept as a JsonSchema constraint: no constraint of the vocabulary says it.');
      }
    }
    foreach ($bounds as $constraint => $options) {
      $constraints[] = [$constraint, $options];
    }
    if ($unique) {
      $constraints[] = ['UniqueItems', []];
    }
    return $constraints;
  }

  /**
   * Reads the parts of a composite, each as one constraint.
   *
   * @param array $parts
   *   The `allOf` or `anyOf`.
   * @param string[] $types
   *   The value's types.
   *
   * @return array|null
   *   Each part as `[name => options]`, or NULL when one cannot be read as
   *   exactly one constraint.
   */
  protected function parts(array $parts, array $types): ?array {
    if ($parts === [] || !array_is_list($parts)) {
      return NULL;
    }
    $read = [];
    foreach ($parts as $part) {
      if (!is_array($part)) {
        return NULL;
      }
      $saved = $this->notes;
      $constraints = $this->constraints($part, '', $types, $foreign);
      $this->notes = $saved;
      if ($foreign !== [] || count($constraints) !== 1) {
        return NULL;
      }
      [$name, $options] = $constraints[0];
      $read[] = [$name => $options];
    }
    return $read;
  }

  /**
   * Reads a list of allowed values.
   *
   * @param array $property
   *   The value's schema.
   * @param string $keyword
   *   `oneOf`, `enum` or `const`.
   *
   * @return array|null
   *   The constraint and its options; an empty array where the list only
   *   allows null; NULL when the keyword is no list of values.
   */
  protected function choice(array $property, string $keyword): ?array {
    $values = [];
    $labels = [];
    $descriptions = [];
    if ($keyword === 'oneOf') {
      if (!is_array($property['oneOf']) || $property['oneOf'] === []) {
        return NULL;
      }
      foreach ($property['oneOf'] as $choice) {
        $choosing = is_array($choice) && array_key_exists('const', $choice) && array_diff(array_keys($choice), self::CHOICE) === [];
        if (!$choosing || (!is_scalar($choice['const']) && $choice['const'] !== NULL)) {
          return NULL;
        }
        if ($choice['const'] === NULL) {
          continue;
        }
        $values[] = $choice['const'];
        $labels[] = is_string($choice['title'] ?? NULL) ? $choice['title'] : (string) $choice['const'];
        $descriptions[] = is_string($choice['description'] ?? NULL) ? $choice['description'] : NULL;
      }
    }
    elseif ($keyword === 'enum') {
      if (!is_array($property['enum']) || $property['enum'] === [] || array_filter($property['enum'], static fn (mixed $value): bool => !is_scalar($value) && $value !== NULL) !== []) {
        return NULL;
      }
      $named = is_array($property['meta:enum'] ?? NULL) ? $property['meta:enum'] : [];
      foreach ($property['enum'] as $value) {
        if ($value === NULL) {
          continue;
        }
        $values[] = $value;
        $labels[] = is_string($named[(string) $value] ?? NULL) ? $named[(string) $value] : NULL;
        $descriptions[] = NULL;
      }
      if (array_filter($labels) === []) {
        return $values === [] ? [] : ['Choice', ['choices' => $values]];
      }
    }
    else {
      if (($property['readOnly'] ?? FALSE) === TRUE || !is_scalar($property['const'])) {
        return NULL;
      }
      if (is_string($property[ContractEmitter::EXTENSION]['fixed'] ?? NULL)) {
        // A deciding key whose variants offer one value: the list is the
        // variants', so the key declares none of its own.
        // Decision: see
        // docs/decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter.
        return [];
      }
      return ['Choice', ['choices' => [$property['const']]]];
    }
    if ($values === []) {
      return [];
    }
    $options = [
      'choices' => $values,
      'labels' => array_combine(array_map('strval', $values), array_map(static fn (?string $label, mixed $value): string => $label ?? (string) $value, $labels, $values)),
    ];
    $described = array_filter(array_combine(array_map('strval', $values), $descriptions), static fn (?string $description): bool => $description !== NULL);
    if ($described !== []) {
      $options['descriptions'] = $described;
    }
    return ['LabeledChoice', $options];
  }

  /**
   * Writes a pattern as the PHP expression the emitter carries it from.
   *
   * PatternTranslation run backwards: a pattern inside the subset both
   * engines read alike is the body of a Regex, delimited by a character
   * it does not hold, with `D`, so a `$` is the very end in PHP as it is
   * in ECMA, and `u` where it holds a character outside ASCII. A pattern
   * outside the subset has no Regex both read alike, and is kept whole.
   *
   * Decision: see docs/decisions.md#a-pattern-both-engines-read-alike.
   *
   * @param string $pattern
   *   The `pattern`.
   *
   * @return string|null
   *   The PHP expression, or NULL when the pattern is outside the subset.
   */
  public static function regex(string $pattern): ?string {
    $modifiers = 'D' . (preg_match('/[\x80-\xFF]/', $pattern) ? 'u' : '');
    foreach (['/', '#', '~', '@', '%', '!', ';', ','] as $delimiter) {
      $unescaped = preg_match('/(?<!\\\\)(\\\\\\\\)*' . preg_quote($delimiter, '/') . '/', $pattern);
      if ($unescaped) {
        continue;
      }
      $regex = $delimiter . $pattern . $delimiter . $modifiers;
      if (@preg_match($regex, '') !== FALSE && PatternTranslation::ecma($regex) === $pattern) {
        return $regex;
      }
    }
    return NULL;
  }

  /**
   * Reads the conditionals of a frame: rules, and what is left over.
   *
   * @param array<int, array> $conditionals
   *   The frame's conditionals no variants took, by index.
   * @param array $properties
   *   The frame's properties.
   * @param string $pointer
   *   The frame's pointer.
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $inputs
   *   The blueprint.
   * @param array $left
   *   Collects what is left over for the frame's JsonSchema constraint.
   */
  protected function rules(array $conditionals, array $properties, string $pointer, ResourceBlueprint $inputs, array &$left): void {
    // A key a rule rules out is required wherever no rule does: the
    // emitter states that as one more conditional, and guards each rule
    // requiring it with the same test.
    $ruling = [];
    foreach ($conditionals as $conditional) {
      $then = $conditional['then'];
      if (array_keys($then) === ['properties'] && is_array($then['properties'])) {
        foreach ($then['properties'] as $key => $narrowing) {
          if ($narrowing === FALSE && count($then['properties']) === 1) {
            $ruling[(string) $key][] = static::plain($conditional['if']);
          }
        }
      }
    }
    $unless = [];
    foreach ($ruling as $key => $ifs) {
      $any = count($ifs) === 1 ? $ifs[0] : ['anyOf' => $ifs];
      $unless[$key] = array_keys($any) === ['not'] && is_array($any['not']) ? $any['not'] : ['not' => $any];
    }

    foreach ($conditionals as $i => $conditional) {
      $at = static::below($pointer, 'allOf', $i);
      $if = static::plain($conditional['if']);
      $then = $conditional['then'];
      $required = is_array($then['required'] ?? NULL) ? array_map('strval', $then['required']) : [];
      if (array_diff(array_keys($then), ['properties', 'required']) === [] && !isset($then['properties']) && count($required) === 1 && isset($unless[$required[0]]) && Subschemas::same($if, $unless[$required[0]])) {
        // Required wherever no rule rules it out: the key is required.
        $this->requireKey($inputs, $required[0]);
        continue;
      }
      // A rule requiring a key another rule may rule out, guarded so.
      if (array_keys($if) === ['allOf'] && count($if['allOf']) === 2 && count($required) === 1 && isset($unless[$required[0]]) && Subschemas::same($if['allOf'][1], $unless[$required[0]])) {
        $if = $if['allOf'][0];
      }
      $condition = static::condition($if);
      $effects = $condition === NULL ? NULL : $this->effects($then, $properties);
      if ($condition === NULL || $effects === NULL) {
        $left['allOf'][] = static::plain(['if' => $conditional['if'], 'then' => $conditional['then']]);
        $this->note($at, 'Kept as a JsonSchema constraint on the frame: its condition or its effect is outside the vocabulary of declared rules.');
        continue;
      }
      foreach ($effects as $key => $said) {
        $rule = $inputs->narrow($key)->when($condition);
        foreach ($said as $effect) {
          match ($effect[0]) {
            'doesNotApply' => $rule->doesNotApply(),
            'require' => $rule->require(),
            'because' => $rule->because($effect[1]),
            default => $rule->constrain($effect[0], $effect[1]),
          };
        }
      }
    }
  }

  /**
   * Marks a declared key required.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $inputs
   *   The blueprint.
   * @param string $key
   *   The key.
   */
  protected function requireKey(ResourceBlueprint $inputs, string $key): void {
    $definition = $inputs->definitionOf($key);
    if ($definition instanceof DataDefinition) {
      $definition->setRequired(TRUE);
    }
  }

  /**
   * Reads what a `then` does to each key it names, as a rule's effects.
   *
   * @param array $then
   *   The `then`.
   * @param array $properties
   *   The frame's properties, each key as declared.
   *
   * @return array<string, array<int, array{0: string, 1?: mixed}>>|null
   *   Each key's effects, in order: a constraint and its options,
   *   `require`, `doesNotApply`, `because` and its text; NULL when the
   *   `then` does anything a rule cannot.
   */
  protected function effects(array $then, array $properties): ?array {
    if (array_diff(array_keys($then), ['properties', 'required']) !== []) {
      return NULL;
    }
    $effects = [];
    foreach (is_array($then['properties'] ?? NULL) ? $then['properties'] : [] as $key => $narrowing) {
      $key = (string) $key;
      $declared = $properties[$key] ?? NULL;
      if (!is_array($declared)) {
        return NULL;
      }
      if ($narrowing === FALSE) {
        $effects[$key][] = ['doesNotApply'];
        continue;
      }
      if (!is_array($narrowing)) {
        return NULL;
      }
      $said = $this->narrowed($narrowing, $declared, in_array($key, is_array($then['required'] ?? NULL) ? $then['required'] : [], TRUE));
      if ($said === NULL) {
        return NULL;
      }
      $effects[$key] = $said;
    }
    foreach (is_array($then['required'] ?? NULL) ? $then['required'] : [] as $key) {
      $key = (string) $key;
      if (!is_array($properties[$key] ?? NULL)) {
        return NULL;
      }
      $effects[$key] ??= [];
      if (!in_array(['require'], $effects[$key], TRUE)) {
        array_unshift($effects[$key], ['require']);
      }
    }
    return $effects;
  }

  /**
   * Reads what a `then` narrows of one key, as a rule's effects.
   *
   * @param array $narrowing
   *   What the `then` says of the key.
   * @param array $declared
   *   The key as declared.
   * @param bool $requires
   *   Whether the `then` requires it.
   *
   * @return array<int, array{0: string, 1?: mixed}>|null
   *   The effects, or NULL for a keyword no effect says.
   */
  protected function narrowed(array $narrowing, array $declared, bool $requires): ?array {
    $effects = [];
    $bounds = [];
    $declared_types = (array) ($declared['type'] ?? []);
    foreach ($narrowing as $keyword => $value) {
      if (isset(self::BOUNDS[$keyword]) && (is_int($value) || is_float($value))) {
        [$constraint, $option] = self::BOUNDS[$keyword];
        $bounds[$constraint][$option] = $value;
        continue;
      }
      switch ($keyword) {
        case 'type':
          $stated = (array) $value;
          // The declared type, stated beside a keyword only one type
          // reads, narrows nothing; without null, it is the key required.
          if ($stated !== $declared_types && !($requires && array_values(array_diff($declared_types, ['null'])) === $stated)) {
            return NULL;
          }
          break;

        case 'description':
          if (!is_string($value)) {
            return NULL;
          }
          $effects[] = ['because', $value];
          break;

        case 'title':
          if ($value !== ($declared['title'] ?? NULL)) {
            return NULL;
          }
          break;

        case 'oneOf':
          $values = [];
          foreach (is_array($value) ? $value : [] as $choice) {
            if (!is_array($choice) || !array_key_exists('const', $choice)) {
              return NULL;
            }
            if ($choice['const'] !== NULL) {
              $values[] = $choice['const'];
            }
          }
          $bounds['Choice']['choices'] = $values;
          break;

        case 'items':
          if (!is_array($value) || array_keys($value) !== ['oneOf']) {
            return NULL;
          }
          $values = [];
          foreach ($value['oneOf'] as $choice) {
            if (!is_array($choice) || !array_key_exists('const', $choice)) {
              return NULL;
            }
            $values[] = $choice['const'];
          }
          $bounds['Choice']['choices'] = $values;
          break;

        case 'pattern':
          $regex = is_string($value) ? static::regex($value) : NULL;
          if ($regex === NULL) {
            return NULL;
          }
          $message = $narrowing[ContractEmitter::EXTENSION]['patternMessage'] ?? NULL;
          $bounds['Regex'] = ['pattern' => $regex] + (is_string($message) ? ['message' => $message] : []);
          break;

        case ContractEmitter::EXTENSION:
          break;

        default:
          return NULL;
      }
    }
    foreach ($bounds as $constraint => $options) {
      $effects[] = [$constraint, $options];
    }
    if ($requires) {
      array_unshift($effects, ['require']);
    }
    return $effects;
  }

  /**
   * Gives a schema left over the defs it refers to.
   *
   * @param array $schema
   *   The schema left over.
   * @param array $root
   *   The document.
   *
   * @return array
   *   The same, with `$defs` holding each def it refers to, at any depth.
   */
  protected function withDefs(array $schema, array $root): array {
    $needed = [];
    $this->referred($schema, $root, $needed);
    if ($needed === []) {
      return $schema;
    }
    $defs = [];
    foreach ($needed as $name) {
      $defs[$name] = static::plain($root['$defs'][$name]);
    }
    return $schema + ['$defs' => $defs];
  }

  /**
   * Collects the defs a schema refers to, through the defs themselves.
   *
   * @param mixed $schema
   *   A schema, or anything inside one.
   * @param array $root
   *   The document.
   * @param string[] $needed
   *   Collects the defs' keys.
   */
  protected function referred(mixed $schema, array $root, array &$needed): void {
    if (!is_array($schema)) {
      return;
    }
    foreach ($schema as $keyword => $value) {
      if ($keyword === '$ref' && is_string($value)) {
        $name = SchemaResolver::defName($value);
        if ($name !== NULL && !in_array($name, $needed, TRUE) && is_array($root['$defs'][$name] ?? NULL)) {
          $needed[] = $name;
          $this->referred($root['$defs'][$name], $root, $needed);
        }
        continue;
      }
      if ($keyword !== ContractEmitter::EXTENSION && !in_array($keyword, ['const', 'default', 'examples', 'enum'], TRUE)) {
        $this->referred($value, $root, $needed);
      }
    }
  }

  /**
   * Strips what only this module says from a schema left over.
   *
   * @param mixed $schema
   *   A schema, or anything inside one.
   *
   * @return mixed
   *   The same, with no `x-surface` at any depth.
   */
  protected static function plain(mixed $schema): mixed {
    if (!is_array($schema)) {
      return $schema;
    }
    unset($schema[ContractEmitter::EXTENSION]);
    foreach ($schema as $keyword => $value) {
      if (is_array($value) && !in_array($keyword, ['const', 'default', 'examples', 'enum'], TRUE)) {
        $schema[$keyword] = static::plain($value);
      }
    }
    return $schema;
  }

  /**
   * Reads every frame of a document for its notes, declaring into nothing.
   *
   * @param array $schema
   *   A frame.
   * @param string $pointer
   *   Its pointer.
   * @param array $root
   *   The document.
   * @param string[] $seen
   *   The pointers read on the way.
   */
  protected function survey(array $schema, string $pointer, array $root, array $seen): void {
    $was = $this->frames;
    $this->frames = [];
    if ($this->typedDataManager === NULL) {
      throw new \LogicException('A whole document is surveyed by the surface.schema_reader service, which holds the typed data manager.');
    }
    $blueprint = (new ResourceBlueprint(new SurfaceBuilder(), $this->typedDataManager, FALSE, $this->registry))->useShapes(NULL);
    $this->frame($schema, $pointer, $blueprint, $root);
    $found = $this->frames;
    $this->frames = $was;
    foreach ($found as $frame) {
      if (!in_array($frame, $seen, TRUE)) {
        $seen[] = $frame;
        $this->survey(static::at($root, $frame), $frame, $root, $seen);
      }
    }
  }

  /**
   * Registers the structure an object of the document is, to be built.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $inputs
   *   The blueprint reading the frame that holds it.
   * @param string $id
   *   Its id.
   * @param array $root
   *   The document.
   * @param string $pointer
   *   Its pointer.
   *
   * @return string
   *   The name it is built by.
   */
  protected function structureAt(ResourceBlueprint $inputs, string $id, array $root, string $pointer): string {
    $this->frames[] = $pointer;
    if ($this->hashed === NULL || $this->hashed[0] !== $root) {
      $this->hashed = [$root, ResourceBlueprint::documentHash($root)];
    }
    return $inputs->readStructure($id, $root, $pointer, $this->hashed[1]);
  }

}
