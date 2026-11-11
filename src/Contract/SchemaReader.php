<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\surface\Contract\Keywords\ReadBack;
use Drupal\surface\Reference\References;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\ResourceBuild\NeedsFrame;
use Drupal\surface\Refinement\Condition;
use Drupal\surface\Refinement\Rule;
use Drupal\surface\Resource\Arrangement;
use Drupal\surface\Resource\ArrangementGroup;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceCallable;
use Drupal\surface\ResourceBuild\ResourceBlueprint;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use PortableSchema\Builder\Keywords;
use PortableSchema\Builder\Schema;
use PortableSchema\Registry;
use PortableSchema\UriTemplate;

/**
 * Reads a JSON Schema document into a blueprint: the emitter run backwards.
 *
 * ContractEmitter writes a sealed data surface as a Portable Schema
 * document, a dialect of JSON Schema 2020-12; this reads such a document,
 * one written as plain JSON Schema 2020-12 alike (nothing here reads
 * `$schema`), or one written anywhere else, into the keys,
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
 * - a `$ref` to a type of the Portable Schema library, through the
 *   registry the reader holds: one LeafTypes::LIBRARY names that leaf
 *   type, what its document says beyond the row kept; any other read from
 *   its document, an object one as a structure;
 * - `portable-typeBy` choosing a key's schema by a sibling's value,
 *   variants that sibling chooses, where each value chooses an object
 *   schema the registry holds (typeBy());
 * - our own `x-surface` keywords, read back where they say something a
 *   key holds: `ports`, `reads`, `by` and `variants`, `collection`, and
 *   the widget a key asks for; and the dialect's: a Regex's message from
 *   `x-portable-messages` under its keyword's code, and the
 *   `drupal:JsonSchema` check of `portable-check`, a JsonSchema
 *   constraint.
 *
 * What none of these can say (`not`, `patternProperties`, `contains`,
 * `dependentSchemas`, `multipleOf`, a type that is several types, a
 * conditional outside the vocabulary or one that widens, a reference to
 * another document) is kept, not refused: the part of the schema left
 * over is a JsonSchema constraint, on the key, or on the frame for what
 * is said of the object, checked by the pipeline against that part and
 * named under `portable-check`. A keyword that only annotates (`$comment`,
 * `deprecated`, `contentMediaType`, another vendor's `x-` keyword) is
 * dropped, and so is what only the server knew (`refine`, `live`, any
 * check of `portable-check` but a reference's, which is read as what the
 * key refers to), a list left out for its size (`deferred`) and every
 * link of `x-portable-links`; each is noted, by its JSON
 * pointer. A document the reader cannot read at all (no object at its
 * root or at a def, a key holding a dot, a reference to a def it does not
 * hold, a type JSON Schema does not have) is refused, naming the pointer.
 *
 * Draft-04, -06 and -07, which single-directory components and many
 * tools write, are converted first (upconvert()), on the array; the walk
 * then reads the document as `PortableSchema\Builder\Schema` values, and
 * every entry point takes and answers arrays (prepared()).
 *
 * Decision: see docs/decisions.md#the-reader-reads-a-schema.
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
   * The keywords that identify a schema, which only a document's root has.
   *
   * A schema below the root of a Portable Schema document holds no `$id`
   * and no `$schema`; an anchor names a place by an `$id`'s base. The
   * reader drops them below the root, with a note (kept()).
   */
  protected const IDENTIFIERS = ['$id', '$schema', '$anchor', '$dynamicAnchor'];

  /**
   * The note on an identifier dropped below the root.
   */
  protected const IDENTIFIER_DROPPED = 'Dropped: an identifier, which a Portable Schema document holds at its root alone.';

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
    PortableKeywords::CHECK,
    PortableKeywords::MESSAGES,
    PortableKeywords::UI,
    PortableKeywords::LINKS,
    'readOnly',
  ];

  /**
   * What a member of a keyword holding schemas is called, by the keyword.
   *
   * For the refusal of one that is no schema (assertSchemas()); an entry of
   * a list is called by its keyword.
   */
  protected const MEMBERS = [
    'properties' => 'a property',
    '$defs' => 'a def',
    'patternProperties' => 'a property a pattern names',
    'dependentSchemas' => 'a schema a key depends on',
    'items' => 'items',
    'additionalProperties' => 'additionalProperties',
    'unevaluatedProperties' => 'unevaluatedProperties',
    'unevaluatedItems' => 'unevaluatedItems',
    'contains' => 'contains',
    'propertyNames' => 'propertyNames',
    'if' => 'if',
    'then' => 'then',
    'else' => 'else',
    'not' => 'not',
    'contentSchema' => 'contentSchema',
  ];

  /**
   * The keyword that chooses a value's schema by another value.
   */
  protected const TYPE_BY = 'portable-typeBy';

  /**
   * The keywords of the dialect the server's validator checks, kept.
   *
   * Each is kept in the JsonSchema constraint of what holds it, and noted.
   * `portable-typeBy` is read as variants where it can be (typeBy()).
   */
  protected const CHECKED_BY_VALIDATOR = ['portable-refine', 'portable-compare', self::TYPE_BY];

  /**
   * The keywords of the dialect that are not read, and why, by keyword.
   */
  protected const UNREAD = [
    'x-portable-type' => 'which names a type of the library the keywords beside it say',
    'x-portable-translatable' => 'which says a text is translated, as a key that holds it says by its own type',
    'x-portable-provider' => 'which names who provides a value, as the server knows',
    'x-portable-unchecked' => 'which says what the server\'s validator leaves unchecked, the validator\'s to decide',
  ];

  /**
   * What an id of the type library starts with.
   */
  protected const LIBRARY_PREFIX = Registry::TYPES_NAMESPACE . ':';

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
    PortableKeywords::CHECK,
    PortableKeywords::MESSAGES,
    PortableKeywords::UI,
    PortableKeywords::LINKS,
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
    PortableKeywords::CHECK,
    PortableKeywords::MESSAGES,
    PortableKeywords::UI,
    PortableKeywords::LINKS,
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
    PortableKeywords::CHECK,
    PortableKeywords::MESSAGES,
    PortableKeywords::UI,
    PortableKeywords::LINKS,
  ];

  /**
   * The keywords an entry of a `oneOf` list of values may say.
   */
  protected const CHOICE = ['const', 'title', 'description'];

  /**
   * The Regex each date or time `format` is checked by, RFC 3339's.
   */
  protected const FORMAT_PATTERNS = [
    'date' => '/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/D',
    'time' => '/^([01]\d|2[0-3]):[0-5]\d:([0-5]\d|60)(\.\d+)?([Zz]|[+-]([01]\d|2[0-3]):[0-5]\d)$/D',
  ];

  /**
   * The keywords read for a value, beside its constraints.
   *
   * The constraints' keywords are the table's (ConstraintKeywords); a
   * `format` is read as a leaf type (LeafTypes), a Regex of
   * FORMAT_PATTERNS, or a row's constraint.
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
    'readOnly',
    'writeOnly',
    'meta:enum',
    ContractEmitter::EXTENSION,
    PortableKeywords::MESSAGES,
    PortableKeywords::UI,
    PortableKeywords::LINKS,
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
   * The document being read, as prepared(): what its structures are read by.
   *
   * A structure is registered by the document's array and a pointer into
   * it (structureAt()), which the build serializes and hashes; the walk
   * reads the same document as a Schema (schemaOf()).
   */
  protected array $document = [];

  /**
   * The documents read so far as Schema values, by their hash.
   *
   * The build reads one structure of a document at a time (fill()), and
   * a document a provider lists holds many: each is converted once.
   *
   * @var array<string, \PortableSchema\Builder\Schema>
   */
  private static array $schemas = [];

  /**
   * The document prepared() answered last, and the Schema value it is.
   *
   * A caller reads what it was answered: the same array is read as that
   * Schema, not converted again (schemaOf()). Only a document below
   * SHARED_PREPARED is kept so; a large one is shared by its hash.
   *
   * @var array{0: array, 1: \PortableSchema\Builder\Schema}|null
   */
  private static ?array $last = NULL;

  /**
   * The registry the reader reads without a container, the type library.
   */
  private static ?Registry $types = NULL;

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
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface|null $entityTypeManager
   *   The entity type manager, which says whether an entity type a
   *   reference's check names is one the site has; NULL to read it as
   *   named.
   * @param \PortableSchema\Registry|null $library
   *   The registry a `$ref` to a `portable:` id, and a `portable-typeBy`,
   *   is read through (surface.portable_registry); NULL for the type
   *   library alone.
   */
  public function __construct(
    protected readonly ?ResourceRegistry $registry = NULL,
    protected readonly ?TypedDataManagerInterface $typedDataManager = NULL,
    protected readonly ?EntityTypeManagerInterface $entityTypeManager = NULL,
    protected readonly ?Registry $library = NULL,
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
    [$root, $schema] = static::preparing($document);
    $this->notes = [];
    $this->document = $root;
    $schema ??= static::schemaOf($root, NULL);
    $this->frame($schema, '', static::blueprintOf($inputs), $schema);
    return $this->notes;
  }

  /**
   * Fills the blueprint of a structure read from a document.
   *
   * The inputs of what ResourceRegistry::defineRead() registers: the
   * object at the pointer, read as a frame, against the entity types the
   * site has, which a reference's check names.
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
    // Called by the build, with no service of its own: the site's entity
    // types and the registry are read from the container where there is
    // one.
    $reader = new static(entityTypeManager: \Drupal::hasService('entity_type.manager') ? \Drupal::entityTypeManager() : NULL, library: static::library());
    $reader->hashed = $hash === NULL ? NULL : [$root, $hash];
    $reader->document = $root;
    $schema = static::schemaOf($root, $hash);
    $reader->frame(static::at($schema, $pointer), $pointer, static::blueprintOf($inputs), $schema);
  }

  /**
   * Gets a prepared document as a Schema value.
   *
   * @param array $root
   *   The document, as prepared().
   * @param string|null $hash
   *   Its hash, when taken: the value is kept by it and shared.
   *
   * @return \PortableSchema\Builder\Schema
   *   The document.
   */
  protected static function schemaOf(array $root, ?string $hash): Schema {
    $last = self::$last !== NULL && self::$last[0] === $root ? self::$last[1] : NULL;
    if ($hash === NULL) {
      return $last ?? Schema::from($root);
    }
    if (isset(self::$schemas[$hash])) {
      $kept = self::$schemas[$hash];
      unset(self::$schemas[$hash]);
      return self::$schemas[$hash] = $kept;
    }
    self::$schemas[$hash] = $last ?? Schema::from($root);
    if (count(self::$schemas) > self::PREPARED_KEPT) {
      unset(self::$schemas[array_key_first(self::$schemas)]);
    }
    return self::$schemas[$hash];
  }

  /**
   * Gets the registry a reader the build makes reads through.
   *
   * @return \PortableSchema\Registry
   *   The site's (surface.portable_registry), or, with no container, the
   *   type library.
   */
  protected static function library(): Registry {
    if (\Drupal::hasService('surface.portable_registry')) {
      $library = \Drupal::service('surface.portable_registry');
      assert($library instanceof Registry);
      return $library;
    }
    return self::$types ??= Registry::withTypes();
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
    if (!preg_match('#^/\$defs/([^/]+)$#', $pointer, $matched) || !is_array($root['$defs'] ?? NULL)) {
      return [NULL, NULL];
    }
    // A def's own two words, read where they stand: no walk is needed.
    $def = $root['$defs'][str_replace(['~1', '~0'], ['/', '~'], $matched[1])] ?? NULL;
    if (!is_array($def)) {
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
    [$root, $schema] = static::preparing($document);
    if ($this->registry === NULL) {
      throw new \LogicException('A whole document is surveyed by the surface.schema_reader service, which holds the registry.');
    }
    $this->notes = [];
    $this->document = $root;
    $schema ??= static::schemaOf($root, NULL);
    $this->survey($schema, '', $schema, []);
    ksort($this->notes);
    return $this->notes;
  }

  /**
   * Converts a document, and makes it ready to be read.
   *
   * Read as JSON decodes it, objects as arrays; upconverted
   * (upconvert()); then, as a Schema, its `x-surface` markers put back
   * where the emitter wrote them (SchemaResolver::restored()), and read
   * in contract 5's spelling where contract 4 wrote them (respelled());
   * each rule a container states for a child it wires ports to moved into
   * the child's def, in the child's own words (localized()). Answered as
   * JSON decodes it (Schemas::decoded()): the array a structure read from
   * it is registered and hashed by.
   *
   * @param array $document
   *   The schema.
   *
   * @return array
   *   The schema, prepared.
   */
  public static function prepared(array $document): array {
    [$prepared, $schema, $large] = static::preparing($document);
    if ($schema !== NULL && !$large) {
      // Kept for the caller who reads what it was answered. A large
      // document is shared by its hash instead (schemaOf()), so what
      // preparing it holds stays a fraction of the document.
      self::$last = [$prepared, $schema];
    }
    return $prepared;
  }

  /**
   * Prepares a document, as prepared() says, and answers its Schema too.
   *
   * @param array $document
   *   The schema.
   *
   * @return array{0: array, 1: \PortableSchema\Builder\Schema|null, 2: bool}
   *   The schema prepared; the Schema value it is, NULL where a large
   *   document was prepared before and shared; and whether it is large
   *   (SHARED_PREPARED).
   */
  protected static function preparing(array $document): array {
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
      return [self::$prepared[$key] = $kept, NULL, TRUE];
    }
    // A document holding no object decodes to itself: it is read as it
    // is.
    $decoded = match (TRUE) {
      $json === FALSE => [],
      static::decodesToItself($document) => $document,
      default => json_decode($json, TRUE),
    };
    // Upconverted on the array, since a draft's spellings are no keywords
    // of the dialect; then read as a Schema, and written back as JSON
    // decodes it. Where nothing moved, the array is what decoding the
    // Schema gives back, and shares its arrays and strings with what the
    // caller holds.
    $upconverted = static::upconvert(is_array($decoded) ? $decoded : []);
    // Refused before it is read: a subschema that is no schema.
    static::assertSchemas($upconverted);
    $read = Schema::from($upconverted);
    $schema = static::localized(static::respelled(SchemaResolver::restored($read)));
    $prepared = $schema === $read ? $upconverted : Schemas::decoded($schema);
    assert(is_array($prepared));
    if ($key !== NULL) {
      self::$prepared[$key] = $prepared;
      if (count(self::$prepared) > self::PREPARED_KEPT) {
        unset(self::$prepared[array_key_first(self::$prepared)]);
      }
    }
    return [$prepared, $schema, $key !== NULL];
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
   * build one to know. Read with a margin: a `writeOnly: true` in any
   * subschema, or any def a shape is known by, answers FALSE.
   *
   * A `$ref` to a type of the library that says `writeOnly: true`, or
   * holds a type that does, answers FALSE too.
   *
   * Decision: see
   * docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
   *
   * @param array $root
   *   The document, prepared.
   * @param \Closure(string): bool $isShape
   *   Whether the site has a shape of an id.
   * @param string|null $hash
   *   The document's hash, when the caller took it: the Schema value a
   *   build read it as is walked, where one is kept.
   *
   * @return bool
   *   TRUE when nothing read from it can be secret.
   */
  public static function holdsNoSecret(array $root, \Closure $isShape, ?string $hash = NULL): bool {
    $defs = [];
    $types = [];
    // Only what holds a schema is walked: a keyword that holds data says
    // no key.
    $schema = match (TRUE) {
      $hash !== NULL && isset(self::$schemas[$hash]) => self::$schemas[$hash],
      self::$last !== NULL && self::$last[0] === $root => self::$last[1],
      default => $root,
    };
    if (!static::writesNoSecret($schema, $defs, $types) || ($types !== [] && (new static(library: static::library()))->librarySecret(array_keys($types)))) {
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
   * Looks through a schema for `writeOnly: true`, noting what it refers to.
   *
   * Only the keywords that hold a schema, a map or a list of them, are
   * entered.
   *
   * @param \PortableSchema\Builder\Schema|array $schema
   *   The schema: a Schema, or as JSON decodes it.
   * @param array<string, true> $defs
   *   Collects the defs a `$ref` names.
   * @param array<string, true> $types
   *   Collects the types of the library a `$ref` names.
   *
   * @return bool
   *   FALSE as soon as one says `writeOnly: true`.
   */
  protected static function writesNoSecret(Schema|array $schema, array &$defs, array &$types): bool {
    $keywords = $schema instanceof Schema ? $schema->all() : $schema;
    if (($keywords['writeOnly'] ?? NULL) === TRUE) {
      return FALSE;
    }
    $reference = $keywords['$ref'] ?? NULL;
    if (is_string($reference)) {
      $name = SchemaResolver::defName($reference);
      if ($name !== NULL) {
        $defs[$name] = TRUE;
      }
      elseif (static::isLibrary($reference)) {
        $types[$reference] = TRUE;
      }
    }
    foreach ($keywords as $keyword => $value) {
      $shape = Keywords::TABLE[$keyword] ?? NULL;
      if ($shape === Keywords::SCHEMA) {
        $value = [$value];
      }
      elseif ($shape !== Keywords::SCHEMA_MAP && $shape !== Keywords::SCHEMA_LIST) {
        continue;
      }
      foreach (is_array($value) ? $value : [] as $member) {
        if ((is_array($member) || Schemas::isObjectSchema($member)) && !static::writesNoSecret($member, $defs, $types)) {
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
   * @param string[] $seen
   *   The ids read on the way here.
   *
   * @return bool
   *   TRUE when one says so; FALSE for an id the registry does not hold.
   */
  protected function librarySecret(array $ids, array $seen = []): bool {
    foreach (array_diff($ids, $seen) as $id) {
      $seen[] = $id;
      $flat = $this->libraryKeywords($id);
      if ($flat === NULL) {
        continue;
      }
      $secret = FALSE;
      $inside = [];
      Schemas::map(Schema::from($flat), static function (Schema $schema) use (&$secret, &$inside): Schema {
        $secret = $secret || $schema->get('writeOnly') === TRUE;
        if (static::isLibrary($schema->get('$ref'))) {
          $inside[] = (string) $schema->get('$ref');
        }
        return $schema;
      });
      if ($secret || $this->librarySecret($inside, $seen)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Walks every subschema of a document, refusing what is no schema.
   *
   * It enters only the keywords that hold a schema, a map of schemas or a
   * list of them (Keywords::shape()), never one that holds data; a
   * boolean schema holds none. Each member of such a keyword is an object
   * or a boolean, and a schema's keywords are names, not numbers.
   *
   * @param array $schema
   *   The schema, decoded with objects as arrays.
   * @param string $pointer
   *   The schema's pointer.
   *
   * @throws \InvalidArgumentException
   *   For a member that is no schema, naming its pointer.
   */
  protected static function assertSchemas(array $schema, string $pointer = ''): void {
    foreach ($schema as $keyword => $value) {
      if (is_int($keyword)) {
        throw new \InvalidArgumentException(sprintf('%s: "%d" is no keyword: a schema\'s keywords are names.', $pointer === '' ? '/' : $pointer, $keyword));
      }
      // The keywords that hold schemas are all in the table: a keyword
      // outside it holds a value.
      $shape = Keywords::TABLE[$keyword] ?? NULL;
      if ($shape === Keywords::SCHEMA) {
        $members = [$keyword => $value];
        $at = $pointer;
      }
      elseif (($shape === Keywords::SCHEMA_MAP || $shape === Keywords::SCHEMA_LIST) && is_array($value)) {
        $members = $value;
        $at = $pointer . '/' . $keyword;
      }
      else {
        continue;
      }
      foreach ($members as $name => $member) {
        if (is_array($member)) {
          static::assertSchemas($member, static::below($at, $name));
        }
        elseif (!is_bool($member)) {
          throw new \InvalidArgumentException(sprintf('%s: %s is a schema, an object or a boolean.', static::below($at, $name), self::MEMBERS[$keyword] ?? 'an entry of ' . $keyword));
        }
      }
    }
  }

  /**
   * Reads the schema a JsonSchema check holds, refusing what is no schema.
   *
   * The check's options are data, which prepared() does not walk: the
   * schema they hold is walked here, as assertSchemas() walks a document.
   *
   * @param array $schema
   *   The schema, as the check's options hold it.
   * @param string $pointer
   *   Where it stands.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema.
   *
   * @throws \InvalidArgumentException
   *   For a member that is no schema, naming its pointer.
   */
  protected static function checked(array $schema, string $pointer): Schema {
    static::assertSchemas($schema, $pointer);
    return Schema::from($schema);
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
   *   The schema in JSON Schema 2020-12, `$schema` naming the dialect the
   *   emitter writes (ContractEmitter::DIALECT), a profile of it, at its
   *   root; a document that named 2020-12 itself reads as the same.
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
    return static::eachSubschema($schema, static::upconverted(...));
  }

  /**
   * Converts each subschema directly inside a schema.
   *
   * @param array $schema
   *   The schema.
   * @param \Closure(array): array $convert
   *   What converts one subschema, and every subschema inside it.
   *
   * @return array
   *   The schema, each subschema converted; the same array where nothing
   *   changed.
   */
  protected static function eachSubschema(array $schema, \Closure $convert): array {
    foreach ($schema as $keyword => $value) {
      if (!is_array($value)) {
        continue;
      }
      if (in_array($keyword, self::SINGLE, TRUE) && !array_is_list($value)) {
        $converted = $convert($value);
        if ($converted !== $value) {
          $schema[$keyword] = $converted;
        }
      }
      elseif (in_array($keyword, self::LISTS, TRUE) && array_is_list($value)) {
        $converted = array_map(static fn (mixed $member): mixed => is_array($member) ? $convert($member) : $member, $value);
        if ($converted !== $value) {
          $schema[$keyword] = $converted;
        }
      }
      elseif (in_array($keyword, self::MAPS, TRUE)) {
        foreach ($value as $name => $member) {
          if (is_array($member)) {
            $converted = $convert($member);
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
   * Reads what contract 4 said under `x-surface` in the dialect's keywords.
   *
   * A document served before contract 5 named a constraint only the server
   * checks under `x-surface.checkedOnServer`, a list of `{constraint,
   * message, schema}`, and a Regex's message under
   * `x-surface.patternMessage`. They are read as `portable-check` and
   * `x-portable-messages` say them now (PortableKeywords::write()), the
   * pattern's message under the code of the keyword it goes with, so such
   * a document is checked as it was. Applied at every depth, after the
   * markers are back in place (SchemaResolver::restored()). The widget
   * hints a document served before them said under `x-surface` are read
   * as `x-portable-ui` says them now (hinted()), and a reference and a
   * deferred list as their check's options and links say them (linked()).
   *
   * Decision: see
   * docs/decisions.md#the-served-document-is-a-portable-schema-document.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A subschema.
   *
   * @return \PortableSchema\Builder\Schema
   *   The same, in contract 5's spelling.
   */
  protected static function respelled(Schema $schema): Schema {
    return Schemas::map($schema, static::respelledOne(...), FALSE);
  }

  /**
   * Reads one subschema in contract 5's spelling, as respelled() says.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A subschema.
   *
   * @return \PortableSchema\Builder\Schema
   *   The same, in contract 5's spelling.
   */
  protected static function respelledOne(Schema $schema): Schema {
    $schema = static::hinted($schema);
    $extension = $schema->get(ContractEmitter::EXTENSION);
    if (is_array($extension) && (array_key_exists('checkedOnServer', $extension) || array_key_exists('patternMessage', $extension))) {
      $entries = [];
      foreach (is_array($extension['checkedOnServer'] ?? NULL) ? $extension['checkedOnServer'] : [] as $entry) {
        if (is_array($entry) && is_string($entry['constraint'] ?? NULL) && $entry['constraint'] !== '') {
          $message = is_string($entry['message'] ?? NULL) ? $entry['message'] : '';
          $held = is_array($entry['schema'] ?? NULL) ? ['schema' => $entry['schema']] : [];
          $entries[] = ['constraint' => $entry['constraint'], 'message' => $message] + $held;
        }
      }
      $code = match (TRUE) {
        $schema->has('pattern') => 'pattern',
        $schema->has('not') => 'not',
        default => NULL,
      };
      $messages = $code !== NULL && is_string($extension['patternMessage'] ?? NULL) ? [$code => $extension['patternMessage']] : [];
      unset($extension['checkedOnServer'], $extension['patternMessage']);
      $schema = $extension === [] ? $schema->without(ContractEmitter::EXTENSION) : $schema->with(ContractEmitter::EXTENSION, $extension);
      $had = $schema->get(PortableKeywords::MESSAGES);
      $said = PortableKeywords::write($entries, $messages + (is_array($had) ? $had : []));
      if (isset($said[PortableKeywords::CHECK])) {
        // As JSON decodes a check's options, an object as an array.
        $said[PortableKeywords::CHECK] = array_map(static fn (array|\stdClass $options): array => (array) $options, $said[PortableKeywords::CHECK]);
      }
      $checks = $schema->get(PortableKeywords::CHECK);
      if (is_array($checks)) {
        $said[PortableKeywords::CHECK] = $checks + ($said[PortableKeywords::CHECK] ?? []);
      }
      $schema = PortableKeywords::before($schema, $said);
    }
    return static::linked($schema);
  }

  /**
   * Reads what a document served before the links said under `x-surface`.
   *
   * A key that refers to an entity said what it refers to as
   * `x-surface.reference: {entityType, bundles?}`, read now as its check's
   * options, the plugin's own names `{entityTypeId, bundles?}`; an id
   * whose entity type a sibling names said `{by}`, which is
   * left out, since its lookup link names a path the document does not
   * give. A deferred list said its fetch as `x-surface.deferred.fetch`,
   * read now as its `choose` link, `deferred` left `true` or its entries.
   *
   * Decision: see
   * docs/decisions.md#a-reference-and-a-deferred-list-are-the-dialects-check-options-and-links.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A subschema.
   *
   * @return \PortableSchema\Builder\Schema
   *   The same, in this spelling.
   */
  protected static function linked(Schema $schema): Schema {
    $extension = $schema->get(ContractEmitter::EXTENSION);
    if (!is_array($extension)) {
      return $schema;
    }
    $reference = $extension['reference'] ?? NULL;
    $deferred = $extension['deferred'] ?? NULL;
    $fetch = is_array($deferred) && is_string($deferred['fetch'] ?? NULL) ? $deferred['fetch'] : NULL;
    if (!is_array($reference) && $fetch === NULL) {
      return $schema;
    }
    $said = [];
    if (is_array($reference)) {
      unset($extension['reference']);
      if (is_string($reference['entityType'] ?? NULL) && $reference['entityType'] !== '') {
        // Bundles as they were said: a value that is no list of names is
        // noted where the check is read (referenceOptions()).
        $bundles = $reference['bundles'] ?? [];
        $checks = $schema->get(PortableKeywords::CHECK);
        $checks = is_array($checks) ? $checks : [];
        $checks[PortableKeywords::PROVIDER . References::CONSTRAINT] = ['entityTypeId' => $reference['entityType']] + ($bundles === [] ? [] : ['bundles' => $bundles]);
        $said[PortableKeywords::CHECK] = $checks;
      }
    }
    $link = NULL;
    if ($fetch !== NULL) {
      $entries = $deferred['entries'] ?? NULL;
      $extension['deferred'] = is_array($entries) ? ['entries' => $entries] : TRUE;
      $by = is_string($extension['by'] ?? NULL) && !is_array($entries) ? $extension['by'] : NULL;
      $link = PortableLinks::choose($fetch, $by);
    }
    $schema = $extension === [] ? $schema->without(ContractEmitter::EXTENSION) : $schema->with(ContractEmitter::EXTENSION, $extension);
    $schema = PortableKeywords::before($schema, $said);
    return $link === NULL ? $schema : PortableLinks::set($schema, $link);
  }

  /**
   * Notes the links a key carries, which are not read.
   *
   * A link names where a client asks the server, and the server writes
   * its own again. A key that refers to an entity is read from its check
   * (constraints()); an id whose entity type a sibling names, known only
   * by its `lookup` link's `source`, is read as a value of its type.
   *
   * @param \PortableSchema\Builder\Schema $property
   *   The key's schema.
   * @param string $pointer
   *   Where it stands.
   */
  protected function droppedLinks(Schema $property, string $pointer): void {
    if (!$property->has(PortableKeywords::LINKS)) {
      return;
    }
    $links = $property->get(PortableKeywords::LINKS);
    if (!is_array($links) || !array_is_list($links)) {
      $this->note(static::below($pointer, PortableKeywords::LINKS), 'Not read: not a link.');
      return;
    }
    foreach ($links as $i => $link) {
      $at = static::below($pointer, PortableKeywords::LINKS, $i);
      $link = $link instanceof \stdClass ? (array) $link : $link;
      if (PortableLinks::all(Schema::any()->with(PortableKeywords::LINKS, [$link])) === []) {
        // No `rel` and `href` to read it by.
        $this->note($at, 'Not read: not a link.');
        continue;
      }
      if ($link['rel'] === PortableLinks::LOOKUP && isset($link['source']) && $this->referenceOptions($property) === NULL) {
        $this->note($at, 'Not read: a link names where a client asks, and an id whose entity type its sibling names is read as a value of its type alone.');
        continue;
      }
      $this->note($at, 'Not read: a link names where a client asks.');
    }
  }

  /**
   * Reads what a key's reference check says it refers to.
   *
   * The check's options are the plugin's own, `{entityTypeId, bundles?}`.
   * An entity type this site does not have, or bundles that are no list
   * of names, are not read: the check is then known by its plugin alone,
   * and the key is read as a value of its type.
   *
   * @param \PortableSchema\Builder\Schema $property
   *   The key's schema.
   * @param string|null $why
   *   Set to why the options are not read, where the check names an
   *   entity type they cannot be read for; NULL otherwise.
   *
   * @return array{entityTypeId: string, bundles?: string[]}|null
   *   The constraint's options, as an author declares them; NULL where its
   *   check names no entity type, or one that is not read.
   */
  protected function referenceOptions(Schema $property, ?string &$why = NULL): ?array {
    $why = NULL;
    $checks = $property->get(PortableKeywords::CHECK);
    $options = is_array($checks) ? ($checks[PortableKeywords::PROVIDER . References::CONSTRAINT] ?? NULL) : NULL;
    $options = $options instanceof \stdClass ? (array) $options : $options;
    if (!is_array($options) || !is_string($options['entityTypeId'] ?? NULL) || $options['entityTypeId'] === '') {
      return NULL;
    }
    if ($this->entityTypeManager !== NULL && !$this->entityTypeManager->hasDefinition($options['entityTypeId'])) {
      $why = sprintf('it refers to %s, an entity type this site does not have', $options['entityTypeId']);
      return NULL;
    }
    $bundles = $options['bundles'] ?? [];
    if (!is_array($bundles) || !array_is_list($bundles) || array_filter($bundles, static fn (mixed $bundle): bool => !is_string($bundle) || $bundle === '') !== []) {
      $why = 'its bundles are not a list of names';
      return NULL;
    }
    return ['entityTypeId' => $options['entityTypeId']] + ($bundles === [] ? [] : ['bundles' => $bundles]);
  }

  /**
   * Reads the widget hints said under `x-surface` as `x-portable-ui`.
   *
   * A document served before the hints moved said a key's `widget`,
   * `multiple`, `arrangement` and `emptyOption` under its `x-surface`, the
   * whole group on each key that names it. They are read as the emitter
   * writes them now: `widget`, the options (`multiple`, each text,
   * `emptyOption`), `group` by its id and `weight`, and each group
   * declared once under `groups` on the object whose keys name it.
   *
   * Decision: see docs/decisions.md#the-ui-hints-are-x-portable-ui.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A subschema, its keys not read yet.
   *
   * @return \PortableSchema\Builder\Schema
   *   The same, its hints and its keys' groups in `x-portable-ui`.
   */
  protected static function hinted(Schema $schema): Schema {
    $ui = [];
    $extension = $schema->get(ContractEmitter::EXTENSION);
    $moved = array_flip(['widget', 'multiple', 'arrangement', 'emptyOption']);
    if (is_array($extension) && array_intersect_key($extension, $moved) !== []) {
      $options = ($extension['multiple'] ?? NULL) === TRUE ? ['multiple' => TRUE] : [];
      $arrangement = is_array($extension['arrangement'] ?? NULL) ? $extension['arrangement'] : [];
      foreach (Arrangement::TEXTS as $text) {
        if (is_string($arrangement[$text] ?? NULL) && $arrangement[$text] !== '') {
          $options[$text] = $arrangement[$text];
        }
      }
      if (is_array($extension['emptyOption'] ?? NULL)) {
        $options['emptyOption'] = $extension['emptyOption'];
      }
      $ui = (is_string($extension['widget'] ?? NULL) ? ['widget' => $extension['widget']] : [])
        + ($options === [] ? [] : ['options' => $options])
        + (is_string($arrangement['group']['id'] ?? NULL) ? ['group' => $arrangement['group']['id']] : [])
        + (is_int($arrangement['weight'] ?? NULL) ? ['weight' => $arrangement['weight']] : []);
      unset($extension['widget'], $extension['multiple'], $extension['arrangement'], $extension['emptyOption']);
      $schema = $extension === [] ? $schema->without(ContractEmitter::EXTENSION) : $schema->with(ContractEmitter::EXTENSION, $extension);
    }
    $groups = [];
    $properties = $schema->get('properties');
    foreach (is_array($properties) ? $properties : [] as $member) {
      $said = Schemas::isObjectSchema($member) ? $member->get(ContractEmitter::EXTENSION) : NULL;
      $group = is_array($said) ? ($said['arrangement']['group'] ?? NULL) : NULL;
      if (is_array($group) && is_string($group['id'] ?? NULL) && is_string($group['label'] ?? NULL)) {
        $declared = PortableUi::declaration($group['id'], $group['label'], is_string($group['as'] ?? NULL) ? $group['as'] : ArrangementGroup::FIELDSET, ($group['collapsed'] ?? FALSE) === TRUE, is_int($group['weight'] ?? NULL) ? $group['weight'] : NULL, is_string($group['description'] ?? NULL) ? $group['description'] : NULL);
        unset($declared['id']);
        $groups[$group['id']] ??= $declared;
      }
    }
    if ($groups !== []) {
      $ui['groups'] = $groups;
    }
    return PortableUi::set($schema, $ui);
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
   * @param \PortableSchema\Builder\Schema $root
   *   The document.
   *
   * @return \PortableSchema\Builder\Schema
   *   The same, each such rule in the child's def.
   */
  protected static function localized(Schema $root): Schema {
    $moved = [];
    $root = static::localizedIn($root, $root, $moved);
    $defs = $root->get('$defs');
    if ($moved === [] || !is_array($defs)) {
      return $root;
    }
    foreach ($moved as $name => $conditionals) {
      $def = $defs[$name] ?? NULL;
      if (!Schemas::isObjectSchema($def)) {
        continue;
      }
      $own = is_array($def->get('allOf')) ? $def->get('allOf') : [];
      foreach ($conditionals as $conditional) {
        if (array_filter($own, static fn (mixed $stated): bool => Schemas::same($stated, $conditional)) === []) {
          $own[] = $conditional;
        }
      }
      $defs[$name] = $def->with('allOf', $own);
    }
    return $root->with('$defs', $defs);
  }

  /**
   * Finds the rules stated at a child's site in one frame, and inside it.
   *
   * @param \PortableSchema\Builder\Schema $frame
   *   An object schema: the root, a def, an object a key holds.
   * @param \PortableSchema\Builder\Schema $root
   *   The document.
   * @param array<string, \PortableSchema\Builder\Schema[]> $moved
   *   Collects each def's rules, by the def's key.
   *
   * @return \PortableSchema\Builder\Schema
   *   The frame, the rules found taken out of its `allOf`.
   */
  protected static function localizedIn(Schema $frame, Schema $root, array &$moved): Schema {
    foreach (['properties', '$defs'] as $keyword) {
      $members = $frame->get($keyword);
      $changed = FALSE;
      foreach (is_array($members) ? $members : [] as $name => $property) {
        if (Schemas::isObjectSchema($property) && ($property->get('properties') !== NULL || $property->get('$defs') !== NULL)) {
          $localized = static::localizedIn($property, $root, $moved);
          if ($localized !== $property) {
            $members[$name] = $localized;
            $changed = TRUE;
          }
        }
      }
      if ($changed) {
        $frame = $frame->with($keyword, $members);
      }
    }
    $all = $frame->get('allOf');
    $properties = $frame->get('properties');
    if (!is_array($all) || !is_array($properties)) {
      return $frame;
    }
    $defs = $root->get('$defs');
    $defs = is_array($defs) ? $defs : [];
    $sites = [];
    foreach ($properties as $name => $property) {
      $said = Schemas::isObjectSchema($property) ? $property->get(ContractEmitter::EXTENSION) : NULL;
      $reads = is_array($said) ? ($said['reads'] ?? NULL) : NULL;
      $def = Schemas::isObjectSchema($property) ? SchemaResolver::refersTo($property) : NULL;
      $declared = $def !== NULL && Schemas::isObjectSchema($defs[$def] ?? NULL) ? $defs[$def]->get(ContractEmitter::EXTENSION) : NULL;
      if (is_array($reads) && $reads !== [] && is_array($declared['ports'] ?? NULL)) {
        $ports = $declared['ports'];
        // Only a port the def reads, wired to a key of this frame: a map
        // whose entries each read a sibling map's entry is no such site.
        if (array_diff_key($reads, $ports) === [] && array_diff(array_values($reads), array_keys($properties)) === []) {
          $sites[(string) $name] = ['def' => $def, 'ports' => array_flip(array_map('strval', $reads))];
        }
      }
    }
    if ($sites === []) {
      return $frame;
    }
    foreach ($all as $i => $conditional) {
      if (!Schemas::isObjectSchema($conditional) || $conditional->get('if') === NULL || $conditional->get('then') === NULL || $conditional->get('else') !== NULL) {
        continue;
      }
      $then = $conditional->get('then');
      $touched = Schemas::isObjectSchema($then) && is_array($then->get('properties')) ? array_keys($then->get('properties')) : [];
      if (count($touched) !== 1 || array_diff(array_keys($then->all()), ['properties']) !== [] || !isset($sites[(string) $touched[0]])) {
        continue;
      }
      $site = $sites[(string) $touched[0]];
      $condition = Schemas::isObjectSchema($conditional->get('if')) ? static::conditionOf($conditional->get('if')) : NULL;
      if ($condition === NULL || array_diff($condition->keys(), array_keys($site['ports'])) !== []) {
        continue;
      }
      $inside = $then->get('properties')[$touched[0]];
      if (!Schemas::isObjectSchema($inside)) {
        continue;
      }
      $localized = Schema::any()
        ->with('if', static::renamed($condition, $site['ports'])->schema())
        ->with('then', $inside->without('type'));
      if ($conditional->has(ContractEmitter::EXTENSION)) {
        $localized = $localized->with(ContractEmitter::EXTENSION, $conditional->get(ContractEmitter::EXTENSION));
      }
      $moved[$site['def']][] = $localized;
      unset($all[$i]);
    }
    $all = array_values($all);
    return $all === [] ? $frame->without('allOf') : $frame->with('allOf', $all);
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
    return static::conditionOf(Schema::from($if));
  }

  /**
   * Reads a condition of the vocabulary from its `if`, as condition() says.
   *
   * @param \PortableSchema\Builder\Schema $if
   *   The `if`.
   *
   * @return \Drupal\surface\Refinement\Condition|null
   *   The condition, or NULL for an `if` outside the vocabulary.
   */
  protected static function conditionOf(Schema $if): ?Condition {
    if ($if->isBoolean()) {
      return NULL;
    }
    $keywords = $if->all();
    unset($keywords[ContractEmitter::EXTENSION]);
    if (array_keys($keywords) === ['not'] && Schemas::isObjectSchema($keywords['not'])) {
      $inner = static::conditionOf($keywords['not']);
      return $inner === NULL ? NULL : Condition::not($inner);
    }
    foreach (['allOf', 'anyOf'] as $combinator) {
      if (array_keys($keywords) === [$combinator] && is_array($keywords[$combinator]) && array_is_list($keywords[$combinator]) && $keywords[$combinator] !== []) {
        $inner = array_map(static fn (mixed $member): ?Condition => Schemas::isObjectSchema($member) ? static::conditionOf($member) : NULL, $keywords[$combinator]);
        if (in_array(NULL, $inner, TRUE)) {
          return NULL;
        }
        return $combinator === 'allOf' ? Condition::allOf(...$inner) : Condition::anyOf(...$inner);
      }
    }
    if (array_diff(array_keys($keywords), ['properties', 'required']) !== [] || !is_array($keywords['properties'] ?? NULL) || $keywords['properties'] === []) {
      return NULL;
    }
    $required = is_array($keywords['required'] ?? NULL) ? array_map('strval', $keywords['required']) : [];
    $tests = [];
    $needs = [];
    foreach ($keywords['properties'] as $key => $test) {
      $key = (string) $key;
      if (!Schemas::isObjectSchema($test)) {
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
   * @param \PortableSchema\Builder\Schema $test
   *   The schema it is held to.
   *
   * @return \Drupal\surface\Refinement\Condition|null
   *   The test, or NULL for one outside the vocabulary.
   */
  protected static function test(string $key, Schema $test): ?Condition {
    if (Schemas::same($test, ['not' => ['type' => 'null']])) {
      return Condition::present($key);
    }
    if (Schemas::same($test, ['type' => 'null'])) {
      return Condition::absent($key);
    }
    $keywords = $test->all();
    if (array_keys($keywords) === ['const'] && is_scalar($keywords['const'])) {
      return Condition::equals($key, $keywords['const']);
    }
    if (array_keys($keywords) === ['enum'] && is_array($keywords['enum']) && $keywords['enum'] !== [] && array_filter($keywords['enum'], static fn (mixed $value): bool => !is_scalar($value)) === []) {
      return Condition::in($key, $keywords['enum']);
    }
    if (count($keywords) === 2 && ($keywords['type'] ?? NULL) === 'number') {
      $bound = array_diff_key($keywords, ['type' => TRUE]);
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
   * @param \PortableSchema\Builder\Schema $root
   *   The document.
   * @param string $pointer
   *   The pointer, '' for the root.
   *
   * @return \PortableSchema\Builder\Schema
   *   The subschema.
   *
   * @throws \InvalidArgumentException
   *   When nothing is there.
   */
  protected static function at(Schema $root, string $pointer): Schema {
    $node = Schemas::at($root, $pointer);
    if ($node === NULL) {
      throw new \InvalidArgumentException(sprintf('%s: the document holds nothing there.', $pointer));
    }
    if (!Schemas::isObjectSchema($node)) {
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
   * @param \PortableSchema\Builder\Schema $schema
   *   The object schema.
   * @param string $pointer
   *   Its pointer in the document.
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $inputs
   *   The blueprint its keys are declared in.
   * @param \PortableSchema\Builder\Schema $root
   *   The document.
   *
   * @throws \InvalidArgumentException
   *   When the frame is no object.
   */
  protected function frame(Schema $schema, string $pointer, ResourceBlueprint $inputs, Schema $root): void {
    // A def that is only a reference to another def is that def.
    if (array_keys(array_diff_key($schema->all(), ['title' => TRUE, 'description' => TRUE])) === ['$ref'] && is_string($schema->get('$ref'))) {
      [$name, $def] = $this->def($schema->get('$ref'), static::below($pointer, '$ref'), $root);
      $this->frame($def, static::below('', '$defs', $name), $inputs, $root);
      return;
    }
    $this->assertObject($schema, $pointer);
    $extension = static::extensionOf($schema);
    $properties = is_array($schema->get('properties')) ? $schema->get('properties') : [];
    $required = array_map('strval', is_array($schema->get('required')) ? $schema->get('required') : []);
    $left = [];

    // Closed, as every frame of a structure is: a key it does not declare
    // is refused before anything else is read.
    $closed = Schemas::boolean($schema->get('additionalProperties')) === FALSE || Schemas::boolean($schema->get('unevaluatedProperties')) === FALSE;
    if (!$closed) {
      $this->note(static::below($pointer, 'additionalProperties'), 'Read closed: a structure holds the keys it declares, and a key the schema leaves open is refused.');
    }
    $this->frameExtension($extension, $pointer, $inputs);
    $groups = Schemas::data((Schemas::data($schema->get(PortableKeywords::UI)) ?? [])['groups'] ?? NULL) ?? [];
    foreach (PortableKeywords::checks($schema->all()) as $check) {
      if ($check['constraint'] === 'JsonSchema' && is_array($check['options']['schema'] ?? NULL)) {
        $at = static::below($pointer, PortableKeywords::CHECK, PortableKeywords::PROVIDER . 'JsonSchema', 'schema');
        $inputs->constrainFrame(static::fragment($this->keptSchema(static::checked($check['options']['schema'], $at), $at)));
      }
      else {
        $this->note(static::below($pointer, PortableKeywords::CHECK, PortableKeywords::PROVIDER . $check['constraint']), 'Not read: a constraint the server checks is known by its plugin alone.');
      }
    }

    // The frame's allOf: structures mixed in, variants' conditionals,
    // rules, and what is left over.
    $members = [];
    $mixins = [];
    $conditionals = [];
    $all = $schema->get('allOf');
    foreach (is_array($all) ? $all : [] as $i => $member) {
      $at = static::below($pointer, 'allOf', $i);
      $reference = Schemas::isObjectSchema($member) && is_string($member->get('$ref')) ? $member->get('$ref') : NULL;
      if ($reference !== NULL && array_diff(array_keys($member->all()), self::MIXED_IN) === []) {
        [$name, $def] = $this->def($reference, static::below($at, '$ref'), $root);
        if ($this->isObject($def)) {
          $mixins[$name] = $this->membersOf($def, $root);
          foreach ($mixins[$name] as $key) {
            $members[$key] = $name;
          }
          continue;
        }
      }
      if (Schemas::isObjectSchema($member) && Schemas::isObjectSchema($member->get('if')) && Schemas::isObjectSchema($member->get('then')) && $member->get('else') === NULL) {
        $conditionals[$i] = $member;
        continue;
      }
      $left['allOf'][] = $this->kept($member, $at);
      $this->note($at, 'Kept as a JsonSchema constraint on the frame: no key or rule says it.');
    }

    // Variants: a key a sibling's value chooses an object for, by the
    // frame's conditionals or by `portable-typeBy`.
    $variants = [];
    $chosen = [];
    foreach ($properties as $name => $property) {
      $name = (string) $name;
      if (Schemas::isObjectSchema($property) && !isset($members[$name])) {
        if ($property->has(self::TYPE_BY)) {
          $typed = $this->typeBy($name, $property, $properties, static::below($pointer, 'properties', $name), $inputs);
          if ($typed !== NULL) {
            $chosen[$name] = $typed;
          }
          continue;
        }
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
          $inputs->mixInRead($this->structureAt($inputs, $structure, $this->document, static::below('', '$defs', $structure)));
        }
        $said = [ContractEmitter::EXTENSION, ...PortableKeywords::KEYWORDS];
        if (Schemas::isObjectSchema($property) && array_diff(array_keys($property->all()), $said) !== []) {
          $this->note($at, 'Not read: what an owner narrows of a member of a structure mixed in is said where the owner declares it.');
        }
        continue;
      }
      if (str_contains($name, '.')) {
        throw new \InvalidArgumentException(sprintf('%s: "%s" cannot be a key, which holds no dot: a dot is how a path names a key inside another.', $at, $name));
      }
      if (Schemas::boolean($property) === FALSE) {
        $this->note($at, 'Not read: a key that may never be sent is no key.');
        continue;
      }
      if (Schemas::boolean($property) === TRUE) {
        $property = Schema::any();
      }
      if (!$property instanceof Schema) {
        throw new \InvalidArgumentException(sprintf('%s: a property is a schema, an object or a boolean.', $at));
      }
      if (isset($chosen[$name])) {
        $shell = $inputs->attachReadVariants($name, $chosen[$name]['by'], $chosen[$name]['variants'], static::title($property));
        $this->describeShell($shell, $property, $name, in_array($name, $required, TRUE), $at);
      }
      elseif (isset($variants[$name])) {
        $this->variantsKey($name, $property, $variants[$name], in_array($name, $required, TRUE), $at, $inputs, $root, $properties);
      }
      else {
        $this->key($name, $property, in_array($name, $required, TRUE), $at, $inputs, $root, array_map('strval', array_keys($properties)));
      }
      $arrangement = $this->arranged($property, $groups, $at, $pointer);
      if ($arrangement !== NULL) {
        $inputs->describe($name, group: $arrangement->group, weight: $arrangement->weight, placeholder: $arrangement->placeholder, prefix: $arrangement->prefix, suffix: $arrangement->suffix, help: $arrangement->help);
      }
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
      $def = SchemaResolver::inline($root->get('$defs')[$structure], $root);
      foreach (is_array($def->get('properties')) ? $def->get('properties') : [] as $key => $member) {
        if (isset($members[(string) $key]) && Schemas::isObjectSchema($member)) {
          $declared[(string) $key] = $member;
        }
      }
    }
    $this->rules($conditionals, $declared, $pointer, $inputs, $left);

    foreach (array_diff_key($schema->all(), array_flip(self::FRAME)) as $keyword => $value) {
      $keyword = (string) $keyword;
      $at = static::below($pointer, $keyword);
      if ($this->unread($keyword, $at)) {
        continue;
      }
      if (in_array($keyword, self::ANNOTATIONS, TRUE) || str_starts_with($keyword, 'x-')) {
        $this->note($at, 'Dropped: an annotation, which checks nothing.');
        continue;
      }
      if (in_array($keyword, ['default', 'examples'], TRUE)) {
        $this->note($at, 'Dropped: what a whole frame starts from is each key\'s own default.');
        continue;
      }
      $left[$keyword] = $this->kept($value, $at);
      $this->note($at, static::keptNote($keyword) ?? 'Kept as a JsonSchema constraint on the frame: no key or rule says it.');
    }
    if ($schema->get('readOnly') === TRUE) {
      $this->note(static::below($pointer, 'readOnly'), 'Not read: a whole locked by an access answer is that answer\'s, not the structure\'s.');
    }
    if ($left !== []) {
      $inputs->constrainFrame($this->withDefs($left, $root));
    }
  }

  /**
   * Reads a key's or a frame's `x-surface`.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   *
   * @return array
   *   Its `x-surface`; empty where it has none, or one that is no object.
   */
  protected static function extensionOf(Schema $schema): array {
    return Schemas::data($schema->get(ContractEmitter::EXTENSION)) ?? [];
  }

  /**
   * Notes a keyword of the dialect that is not read, as dropped.
   *
   * The annotations of the type library: what they say is the library's,
   * or, for `x-portable-unchecked`, the server's validator's to decide.
   *
   * @param string $keyword
   *   The keyword.
   * @param string $pointer
   *   Where it stands.
   *
   * @return bool
   *   TRUE when the keyword is one of UNREAD, noted.
   */
  protected function unread(string $keyword, string $pointer): bool {
    if (!isset(self::UNREAD[$keyword])) {
      return FALSE;
    }
    $this->note($pointer, sprintf('Not read: %s, %s.', $keyword, self::UNREAD[$keyword]));
    return TRUE;
  }

  /**
   * Gets the note on a keyword of the dialect kept for the server's check.
   *
   * @param string $keyword
   *   The keyword.
   *
   * @return string|null
   *   The note for `portable-refine`, `portable-compare` and
   *   `portable-typeBy`; NULL for any other keyword.
   */
  protected static function keptNote(string $keyword): ?string {
    return in_array($keyword, self::CHECKED_BY_VALIDATOR, TRUE)
      ? sprintf('Kept as a JsonSchema constraint: %s is checked by the server\'s validator, not read into the structure.', $keyword)
      : NULL;
  }

  /**
   * Reads how a key is arranged where it is drawn, from its hints.
   *
   * Its `x-portable-ui`: `group`, with the group its frame declares under
   * `groups`; `weight`; and the options `placeholder`, `prefix`, `suffix`
   * and `help`, described as an author describes them. The options
   * `emptyOption` and `multiple` are the options resolver's to decide, and
   * not read. What cannot be read is noted: a weight that is no whole
   * number, a text that is no string, a group widget other than `fieldset`
   * or `details` (read as a fieldset), and a group with no label, whose
   * keys are read ungrouped.
   *
   * Decision: see docs/decisions.md#the-ui-hints-are-x-portable-ui.
   *
   * @param \PortableSchema\Builder\Schema $property
   *   The key's schema.
   * @param array $groups
   *   The groups its frame declares, by id.
   * @param string $pointer
   *   The key's pointer.
   * @param string $frame
   *   The pointer of its frame, the object schema that declares the groups.
   *
   * @return \Drupal\surface\Resource\Arrangement|null
   *   The arrangement, or NULL where the hints say none.
   */
  protected function arranged(Schema $property, array $groups, string $pointer, string $frame): ?Arrangement {
    $ui = Schemas::data($property->get(PortableKeywords::UI)) ?? [];
    if ($ui === []) {
      return NULL;
    }
    $at = static::below($pointer, PortableKeywords::UI);
    $options = Schemas::data($ui['options'] ?? NULL) ?? [];
    foreach (['emptyOption', 'multiple'] as $option) {
      if (array_key_exists($option, $options)) {
        $this->note(static::below($at, 'options', $option), 'Not read: the options resolver decides it from the key\'s list of values.');
      }
    }
    $texts = [];
    foreach (Arrangement::TEXTS as $text) {
      if (!array_key_exists($text, $options)) {
        continue;
      }
      if (!is_string($options[$text])) {
        $this->note(static::below($at, 'options', $text), 'Not read: a text is a string.');
      }
      elseif ($options[$text] !== '') {
        $texts[$text] = $options[$text];
      }
    }
    $weight = $this->weight($ui, $at);
    $group = NULL;
    if (is_string($ui['group'] ?? NULL)) {
      $id = $ui['group'];
      $declared = Schemas::data($groups[$id] ?? NULL);
      $declared_at = static::below($frame, PortableKeywords::UI, 'groups', $id);
      if (!is_array($declared) || $id === '' || strpbrk($id, '.:') !== FALSE) {
        $this->note(static::below($at, 'group'), 'Not read: the group is declared nowhere in the frame, or cannot be named so.');
      }
      elseif (!is_string($declared['label'] ?? NULL) || PlainText::of($declared['label']) === '') {
        $this->note($declared_at, 'Not read: a group with no label is not read.');
        $this->note(static::below($at, 'group'), 'Read ungrouped: its group has no label.');
      }
      else {
        $widget = $declared['widget'] ?? NULL;
        if ($widget !== NULL && $widget !== ArrangementGroup::DETAILS && $widget !== ArrangementGroup::FIELDSET) {
          $this->note(static::below($declared_at, 'widget'), 'Read as a fieldset: a group is drawn as a fieldset or a details.');
        }
        $description = is_string($declared['description'] ?? NULL) && $declared['description'] !== '' ? $declared['description'] : NULL;
        $group_weight = $this->weight($declared, $declared_at);
        $group = $widget === ArrangementGroup::DETAILS
          ? ArrangementGroup::details($id, $declared['label'], ((Schemas::data($declared['options'] ?? NULL) ?? [])['open'] ?? TRUE) === FALSE, $group_weight, $description)
          : ArrangementGroup::fieldset($id, $declared['label'], $group_weight, $description);
      }
    }
    if ($group === NULL && $weight === NULL && $texts === []) {
      return NULL;
    }
    return new Arrangement($group, $weight, $texts['placeholder'] ?? NULL, $texts['prefix'] ?? NULL, $texts['suffix'] ?? NULL, $texts['help'] ?? NULL);
  }

  /**
   * Reads the weight of a key or a group, noting one that is no whole number.
   *
   * @param array $hints
   *   The key's `x-portable-ui`, or the group as its frame declares it.
   * @param string $pointer
   *   Its pointer.
   *
   * @return int|null
   *   The weight, or NULL for none.
   */
  protected function weight(array $hints, string $pointer): ?int {
    if (!array_key_exists('weight', $hints)) {
      return NULL;
    }
    $weight = static::integral($hints['weight']);
    if ($weight === NULL) {
      $this->note(static::below($pointer, 'weight'), 'Not read: a weight is a whole number.');
    }
    return $weight;
  }

  /**
   * Reads a whole number, as JSON may decode one.
   *
   * @param mixed $number
   *   The value.
   *
   * @return int|null
   *   The number, or NULL for anything else.
   */
  protected static function integral(mixed $number): ?int {
    return match (TRUE) {
      is_int($number) => $number,
      is_float($number) && floor($number) === $number && abs($number) < PHP_INT_MAX => (int) $number,
      default => NULL,
    };
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
   * @param \PortableSchema\Builder\Schema $def
   *   The def.
   * @param \PortableSchema\Builder\Schema $root
   *   The document.
   * @param string[] $seen
   *   The defs read on the way.
   *
   * @return string[]
   *   The keys.
   */
  protected function membersOf(Schema $def, Schema $root, array $seen = []): array {
    $keys = array_map('strval', array_keys(is_array($def->get('properties')) ? $def->get('properties') : []));
    $defs = $root->get('$defs');
    foreach (is_array($def->get('allOf')) ? $def->get('allOf') : [] as $member) {
      $name = Schemas::isObjectSchema($member) && is_string($member->get('$ref')) ? SchemaResolver::defName($member->get('$ref')) : NULL;
      if ($name !== NULL && !in_array($name, $seen, TRUE) && is_array($defs) && Schemas::isObjectSchema($defs[$name] ?? NULL)) {
        array_push($keys, ...$this->membersOf($defs[$name], $root, [...$seen, $name]));
      }
    }
    return array_values(array_unique($keys));
  }

  /**
   * Refuses a frame that is no object.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   * @param string $pointer
   *   Its pointer.
   *
   * @throws \InvalidArgumentException
   *   When it is not.
   */
  protected function assertObject(Schema $schema, string $pointer): void {
    $types = array_values(array_diff((array) ($schema->get('type') ?? 'object'), ['null']));
    if ($schema->isBoolean() || $types !== ['object']) {
      throw new \InvalidArgumentException(sprintf('%s: what is read into a structure is an object; this is %s.', $pointer === '' ? '/' : $pointer, $schema->isBoolean() ? 'a boolean schema' : ($types === [] ? 'null' : implode(' or ', array_map('strval', $types)))));
    }
  }

  /**
   * Answers whether a schema is an object a frame can be read from.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   *
   * @return bool
   *   TRUE for an object schema, or a reference to one.
   */
  protected function isObject(Schema $schema): bool {
    $types = array_values(array_diff((array) ($schema->get('type') ?? ''), ['null']));
    if ($types === ['object']) {
      return TRUE;
    }
    if ($schema->get('type') !== NULL || ($schema->get('properties') === NULL && $schema->get('$ref') === NULL)) {
      return FALSE;
    }
    // A type of the library is an object where the library says so; an
    // id the registry does not hold is read as any other reference.
    $flat = $schema->get('properties') === NULL && static::isLibrary($schema->get('$ref')) ? $this->libraryKeywords((string) $schema->get('$ref')) : NULL;
    return $flat === NULL || static::isLibraryObject($flat);
  }

  /**
   * Reads the def a local reference points at.
   *
   * @param string $reference
   *   The `$ref`.
   * @param string $pointer
   *   Where the reference stands.
   * @param \PortableSchema\Builder\Schema $root
   *   The document.
   *
   * @return array{0: string, 1: \PortableSchema\Builder\Schema}
   *   The def's key and the def.
   *
   * @throws \InvalidArgumentException
   *   For a local reference to no def the document holds.
   */
  protected function def(string $reference, string $pointer, Schema $root): array {
    $name = SchemaResolver::defName($reference);
    $defs = $root->get('$defs');
    $def = $name !== NULL && is_array($defs) ? ($defs[$name] ?? NULL) : NULL;
    if ($name === NULL || !Schemas::isObjectSchema($def)) {
      throw new \InvalidArgumentException(sprintf('%s: "%s" refers to no def this document holds under $defs.', $pointer, $reference));
    }
    return [$name, $def];
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
   * @param \PortableSchema\Builder\Schema $property
   *   Its schema.
   * @param array<int, \PortableSchema\Builder\Schema> $conditionals
   *   The frame's conditionals, by index.
   * @param array $properties
   *   The frame's properties.
   *
   * @return array{by: string, variants: array<string, array{schema: \PortableSchema\Builder\Schema, pointer: string|null, index: int|null}>, consumed: int[]}|null
   *   The deciding key, each value's object (its schema, and the pointer
   *   of a def it refers to), and the conditionals that state them; NULL
   *   when the key holds no variants.
   */
  protected function variantsAt(string $name, Schema $property, array $conditionals, array $properties): ?array {
    $extension = static::extensionOf($property);
    $by = is_string($extension['by'] ?? NULL) ? $extension['by'] : NULL;
    $union = is_array($property->get('anyOf')) ? $property->get('anyOf') : (is_array($property->get('oneOf')) ? $property->get('oneOf') : NULL);
    $objects = is_array($union) && $union !== [] && array_filter($union, fn (mixed $member): bool => !Schemas::isObjectSchema($member) || (!$this->isObject($member) && !Schemas::same($member, ['type' => 'null']))) === [];
    if ($by === NULL && !$objects) {
      return NULL;
    }
    $found = [];
    $consumed = [];
    foreach ($conditionals as $i => $conditional) {
      $then = $conditional->get('then');
      $test = static::conditionOf($conditional->get('if'));
      $said = is_array($then->get('properties')) ? $then->get('properties') : NULL;
      if ($test === NULL || $test->kind !== 'equals' || ($by !== NULL && $test->key !== $by) || array_keys($then->all()) !== ['properties'] || $said === NULL || array_keys($said) !== [$name] || !Schemas::isObjectSchema($said[$name])) {
        continue;
      }
      $chosen = $said[$name]->without('title')->without('description');
      $all = $chosen->get('allOf');
      $first = is_array($all) && Schemas::isObjectSchema($all[0] ?? NULL) ? $all[0] : Schema::any();
      if ($chosen->get('$ref') === NULL && is_string($first->get('$ref')) && count($first->all()) === 1) {
        // The variant at this site, narrowed by what its access locked:
        // the variant is the def, and the lock its access's.
        $chosen = Schema::any()->with('$ref', $first->get('$ref'));
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
      $reference = $chosen->get('$ref');
      $variants[$value] = [
        'schema' => $chosen,
        'pointer' => is_string($reference) && count($chosen->all()) === 1 && static::isLocal($reference) ? static::below('', '$defs', (string) SchemaResolver::defName($reference)) : NULL,
        'index' => $i,
      ];
    }
    // A value the key's own `x-surface` names with no conditional of its
    // own still chooses the def it names.
    foreach (is_array($extension['variants'] ?? NULL) ? $extension['variants'] : [] as $value => $reference) {
      if (!isset($variants[(string) $value]) && is_string($reference) && static::isLocal($reference)) {
        $variants[(string) $value] = [
          'schema' => Schema::any()->with('$ref', $reference),
          'pointer' => static::below('', '$defs', (string) SchemaResolver::defName($reference)),
          'index' => NULL,
        ];
      }
    }
    if ($variants === [] && $union === NULL && (($extension['deferred'] ?? NULL) === TRUE || is_array($extension['deferred'] ?? NULL))) {
      // Variants a site lists past its budget, deferred: no variant is in
      // the document to read, so the key is an object like any other.
      // Decision: see
      // docs/decisions.md#a-list-left-out-for-size-is-deferred-not-refined.
      return NULL;
    }
    return ['by' => $by, 'variants' => $variants, 'consumed' => $consumed];
  }

  /**
   * Reads a key whose schema `portable-typeBy` chooses, as variants.
   *
   * Read so where variants say what the keyword does: its `source` reads
   * one value, a sibling's (`1/<key>`), that lists the values it may hold
   * (`enum`, or a `oneOf` of `const`); its `href` is a template of that
   * one `{variable}`; each value's expansion, or else the `fallback`, is
   * the id of an object schema the registry holds. Each such schema is a
   * variant, the structure its document is (libraryStructure()). A
   * `fallback` no value needs is noted and not read. Where any of this
   * does not hold, the keyword is noted, and the key read as a value held
   * whole to its schema, the keyword kept in its JsonSchema constraint for
   * the server's validator (foreignValue()).
   *
   * Decision: see docs/decisions.md#the-reader-reads-a-schema.
   *
   * @param string $name
   *   The key.
   * @param \PortableSchema\Builder\Schema $property
   *   Its schema, which holds `portable-typeBy`.
   * @param array $properties
   *   The frame's properties.
   * @param string $pointer
   *   The key's pointer.
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $inputs
   *   The blueprint, which registers each variant.
   *
   * @return array{by: string, variants: array<string, array{name: string, label: string|null}>}|null
   *   The deciding key and each value's variant; NULL where the keyword is
   *   not read as variants.
   */
  protected function typeBy(string $name, Schema $property, array $properties, string $pointer, ResourceBlueprint $inputs): ?array {
    $at = static::below($pointer, self::TYPE_BY);
    $targets = $this->typeByTargets($name, $property->get(self::TYPE_BY), $properties, $at, $by);
    if (is_string($targets)) {
      $this->note($at, sprintf('Not read: portable-typeBy %s; kept as a JsonSchema constraint, which the server\'s validator checks.', $targets));
      return NULL;
    }
    $labels = static::labels($properties[$by] ?? NULL);
    $variants = [];
    foreach ($targets as $value => $id) {
      $variants[$value] = ['name' => $this->libraryStructure($id, $inputs), 'label' => $labels[$value] ?? NULL];
    }
    $said = ['title', 'description', self::TYPE_BY, ContractEmitter::EXTENSION, ...PortableKeywords::KEYWORDS];
    foreach (array_diff_key($property->all(), array_flip($said)) as $keyword => $value) {
      $this->note(static::below($pointer, (string) $keyword), 'Not read: a key portable-typeBy chooses the schema of is what each variant says.');
    }
    return ['by' => (string) $by, 'variants' => $variants];
  }

  /**
   * Finds the schema `portable-typeBy` chooses for each value it reads.
   *
   * @param string $name
   *   The key.
   * @param mixed $typeBy
   *   The keyword's value.
   * @param array $properties
   *   The frame's properties.
   * @param string $pointer
   *   The keyword's pointer.
   * @param string|null $by
   *   Set to the sibling it reads.
   *
   * @return array<string, string>|string
   *   Each value's schema id, by the value as variants name it; or why
   *   the keyword is not read as variants.
   */
  protected function typeByTargets(string $name, mixed $typeBy, array $properties, string $pointer, ?string &$by = NULL): array|string {
    $by = NULL;
    if (!is_array($typeBy) || !is_string($typeBy['href'] ?? NULL) || !is_array($typeBy['source'] ?? NULL)) {
      return 'says no href and source';
    }
    $source = $typeBy['source'];
    if (count($source) !== 1) {
      return 'reads more than one value, and variants are chosen by one';
    }
    $variable = (string) array_key_first($source);
    $from = $source[$variable];
    if (!is_string($from) || !preg_match('#^1/((?:[^~/]|~[01])+)$#', $from, $matched)) {
      return 'reads a value that is no key beside this one';
    }
    $sibling = str_replace(['~1', '~0'], ['/', '~'], $matched[1]);
    if ($sibling === $name || !Schemas::isObjectSchema($properties[$sibling] ?? NULL)) {
      return sprintf('reads "%s", which is no key beside this one', $sibling);
    }
    $href = $typeBy['href'];
    preg_match_all('/\{([^}]*)\}/', $href, $expressions);
    $rest = (string) preg_replace('/\{[^}]*\}/', '', $href);
    if (array_diff($expressions[1], [$variable]) !== [] || str_contains($rest, '{') || str_contains($rest, '}') || str_contains($href, '#')) {
      return sprintf('names its schema by a template other than {%s}', $variable);
    }
    $values = static::listed($properties[$sibling]);
    if ($values === NULL) {
      return sprintf('reads "%s", which lists no values it may hold', $sibling);
    }
    $registry = $this->portable();
    $fallback = $typeBy['fallback'] ?? NULL;
    if ($fallback !== NULL && (!is_string($fallback) || !$this->readsAsStructure($fallback))) {
      return 'falls back to no object schema the registry holds';
    }
    $targets = [];
    $fell_back = FALSE;
    foreach ($values as $value) {
      // A variant is named by the value that chooses it, as a deciding
      // key's value is matched: a string.
      if (!is_string($value)) {
        return sprintf('reads a value of "%s" that is no string', $sibling);
      }
      $id = UriTemplate::expand($href, [$variable => $value]);
      if ($registry->has($id)) {
        if (!$this->readsAsStructure($id)) {
          return sprintf('chooses %s, which is no object schema', $id);
        }
        $targets[$value] = $id;
      }
      elseif (is_string($fallback)) {
        $targets[$value] = $fallback;
        $fell_back = TRUE;
      }
      else {
        return sprintf('chooses %s for %s, which the registry does not hold', $id, json_encode($value));
      }
    }
    if (is_string($fallback) && !$fell_back) {
      $this->note(static::below($pointer, 'fallback'), sprintf('Not read: every value of "%s" chooses a schema of its own, so none falls back.', $sibling));
    }
    $by = $sibling;
    return $targets;
  }

  /**
   * Lists the values a deciding key may hold, null left out.
   *
   * @param \PortableSchema\Builder\Schema $deciding
   *   Its schema.
   *
   * @return list<mixed>|null
   *   Its `enum`, or the `const` of each entry of its `oneOf`; NULL where
   *   it lists none.
   */
  protected static function listed(Schema $deciding): ?array {
    $values = NULL;
    if (is_array($deciding->get('enum'))) {
      $values = $deciding->get('enum');
    }
    elseif (is_array($deciding->get('oneOf'))) {
      $values = [];
      foreach ($deciding->get('oneOf') as $choice) {
        if (!Schemas::isObjectSchema($choice) || !$choice->has('const')) {
          return NULL;
        }
        $values[] = $choice->get('const');
      }
    }
    $values = $values === NULL ? [] : array_values(array_filter($values, static fn (mixed $value): bool => $value !== NULL));
    return $values === [] ? NULL : $values;
  }

  /**
   * Answers whether the registry holds an object schema of an id.
   *
   * @param string $id
   *   The id.
   *
   * @return bool
   *   TRUE when a structure can be read from it.
   */
  protected function readsAsStructure(string $id): bool {
    $registry = $this->portable();
    if (!$registry->has($id)) {
      return FALSE;
    }
    $schema = Schema::from($registry->get($id));
    return !$schema->isBoolean() && $this->isObject($schema);
  }

  /**
   * Declares a key holding variants.
   *
   * @param string $name
   *   The key.
   * @param \PortableSchema\Builder\Schema $property
   *   Its schema.
   * @param array{by: string, variants: array<string, array{schema: \PortableSchema\Builder\Schema, pointer: string|null, index: int|null}>, consumed: int[]} $found
   *   What variantsAt() found.
   * @param bool $required
   *   Whether the frame requires it.
   * @param string $pointer
   *   The key's pointer.
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $inputs
   *   The blueprint.
   * @param \PortableSchema\Builder\Schema $root
   *   The document.
   * @param array $properties
   *   The frame's properties, for the deciding key's labels.
   */
  protected function variantsKey(string $name, Schema $property, array $found, bool $required, string $pointer, ResourceBlueprint $inputs, Schema $root, array $properties): void {
    $labels = static::labels($properties[$found['by']] ?? NULL);
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
        'name' => $this->structureAt($inputs, $id, $this->document, $at),
        'label' => $labels[(string) $value] ?? NULL,
      ];
    }
    $shell = $inputs->attachReadVariants($name, $found['by'], $variants, static::title($property));
    $this->describeShell($shell, $property, $name, $required, $pointer);
  }

  /**
   * Reads the label of each value a deciding key may hold.
   *
   * @param mixed $deciding
   *   The deciding key's schema.
   *
   * @return array<string, string>
   *   Each value's label, by the value: a `oneOf` entry's `title`, or the
   *   label `x-surface.fixed` gives the one value its `const` allows.
   */
  protected static function labels(mixed $deciding): array {
    if (!Schemas::isObjectSchema($deciding)) {
      return [];
    }
    $labels = [];
    foreach (is_array($deciding->get('oneOf')) ? $deciding->get('oneOf') : [] as $choice) {
      if (Schemas::isObjectSchema($choice) && $choice->has('const') && is_string($choice->get('title')) && $choice->get('const') !== NULL) {
        $const = $choice->get('const');
        $labels[is_bool($const) ? ($const ? '1' : '0') : (string) $const] = $choice->get('title');
      }
    }
    $fixed = static::extensionOf($deciding)['fixed'] ?? NULL;
    if (is_scalar($deciding->get('const')) && is_string($fixed)) {
      // The one value a deciding key may hold, and the label it is drawn
      // with.
      $labels[(string) $deciding->get('const')] = $fixed;
    }
    return $labels;
  }

  /**
   * Finds where a frame's subschema stands in the document.
   *
   * A frame read below the root says its pointers from its own object;
   * a variant stated inline in its `then` is read from where it is.
   *
   * @param \PortableSchema\Builder\Schema $root
   *   The document.
   * @param string $pointer
   *   The pointer from the frame.
   *
   * @return string|null
   *   The pointer from the root, when the frame is the root.
   */
  protected function located(Schema $root, string $pointer): ?string {
    return Schemas::isObjectSchema(Schemas::at($root, $pointer)) ? $pointer : NULL;
  }

  /**
   * Reads the title a key's schema gives it.
   *
   * @param \PortableSchema\Builder\Schema $property
   *   The key's schema.
   *
   * @return string|null
   *   The title, or NULL where it gives none.
   */
  protected static function title(Schema $property): ?string {
    return is_string($property->get('title')) ? $property->get('title') : NULL;
  }

  /**
   * Describes the map a child is held in, labeled by the verb.
   *
   * @param \Drupal\Core\TypedData\MapDataDefinition $shell
   *   The map attach() handed back.
   * @param \PortableSchema\Builder\Schema $property
   *   The key's schema.
   * @param string $name
   *   The key.
   * @param bool $required
   *   Whether the frame requires it.
   * @param string $pointer
   *   The key's pointer.
   */
  protected function describeShell(MapDataDefinition $shell, Schema $property, string $name, bool $required, string $pointer): void {
    if (is_string($property->get('description'))) {
      $shell->setDescription($property->get('description'));
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
   * @param \PortableSchema\Builder\Schema $property
   *   Its schema.
   * @param bool $required
   *   Whether the frame requires it.
   * @param string $pointer
   *   Its pointer.
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $inputs
   *   The blueprint.
   * @param \PortableSchema\Builder\Schema $root
   *   The document.
   * @param string[] $keys
   *   The frame's keys, which a port may be wired to.
   */
  protected function key(string $name, Schema $property, bool $required, string $pointer, ResourceBlueprint $inputs, Schema $root, array $keys = []): void {
    $property = $this->throughLibrary($property, $root);
    $extension = static::extensionOf($property);
    $this->droppedExtension($extension, static::below($pointer, ContractEmitter::EXTENSION));
    $this->droppedLinks($property, $pointer);
    $library = $this->libraryOf($property, $pointer);
    if ($library !== NULL && $library['object']) {
      // An object of the library: the structure its document is.
      $shell = $inputs->attach($name, $this->libraryStructure($library['id'], $inputs), static::title($property));
      $this->describeShell($shell, $property, $name, $required, $pointer);
      $said = ['$ref', 'title', 'description', ContractEmitter::EXTENSION, ...PortableKeywords::KEYWORDS];
      foreach (array_diff_key($property->all(), array_flip($said)) as $keyword => $value) {
        $this->note(static::below($pointer, (string) $keyword), 'Not read: a structure of the library is read as the library states it.');
      }
      return;
    }
    $reference = $this->siteReference($property, $pointer);

    // A child: an object def it refers to, or an object of its own.
    if ($reference !== NULL) {
      [$def_name, $def] = $this->def($reference, static::below($pointer, '$ref'), $root);
      if ($this->isObject($def)) {
        $reads = $this->wiring($extension, $def, static::below($pointer, ContractEmitter::EXTENSION, 'reads'), $keys);
        $from = $this->document;
        $ports = static::extensionOf($def)['ports'] ?? NULL;
        foreach (is_array($ports) ? $ports : [] as $port => $declared) {
          if (!isset($reads[$port]) && is_array($declared) && ($declared['required'] ?? FALSE) === TRUE) {
            // Nothing here can wire it: read so that nothing has to.
            $from['$defs'][$def_name][ContractEmitter::EXTENSION]['ports'][$port]['required'] = FALSE;
            $this->note(static::below('', '$defs', $def_name, ContractEmitter::EXTENSION, 'ports', (string) $port, 'required'), sprintf('Read optional where %s attaches it unwired.', $pointer));
          }
        }
        $shell = $inputs->attach($name, $this->structureAt($inputs, $def_name, $from, static::below('', '$defs', $def_name)), static::title($property), $reads);
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
      $keywords = SchemaResolver::inline(Schema::any()->with('$ref', $reference), $root)->all();
      foreach ($property->without('$ref')->all() as $keyword => $value) {
        $keywords[$keyword] = $value;
      }
      $property = Schema::from($keywords);
    }
    $types = $this->types($property, $pointer);
    $not_null = array_values(array_diff($types, ['null']));
    $nested = $property->get('properties');
    if ($not_null === ['object'] && is_array($nested) && $nested !== []) {
      if ($this->isPlainMap($property)) {
        $map = $this->map($property, $pointer, $root);
        static::declareRead($inputs, $name, $this->finished($map, $property, $required, in_array('null', $types, TRUE)));
        return;
      }
      if (array_diff(array_keys($property->all()), [...self::MAP, 'allOf']) === [] && Schemas::boolean($property->get('additionalProperties')) === FALSE) {
        // An object written as a map, holding what a plain map does not
        // (a structure, a rule, which its `allOf` states): an anonymous
        // map, written inline again.
        // Decision: see docs/decisions.md#an-anonymous-map.
        $shell = $inputs->addMap($name, static::title($property) ?? $name, function (BlueprintInterface $map) use ($name, $property, $pointer, $root): void {
          if (!$map instanceof ResourceBlueprint) {
            throw new NeedsFrame($name);
          }
          $this->frame($property, $pointer, $map, $root);
        });
        $this->describeShell($shell, $property, $name, $required, $pointer);
        return;
      }
      $shell = $inputs->attach($name, $this->structureAt($inputs, $inputs->ownerId() . '.' . $name, $this->document, $pointer), static::title($property));
      $this->describeShell($shell, $property, $name, $required, $pointer);
      return;
    }
    $items = $property->get('items');
    if (Schemas::isObjectSchema($items) && ($library_items = $this->throughLibrary($items, $root)) !== $items) {
      // Items that are a type of the library are read as one.
      $items = $library_items;
      $property = $property->with('items', $items);
    }
    if ($not_null === ['array'] && Schemas::isObjectSchema($items) && ($this->isObject($items) || $this->refersToObject($items, $root))) {
      $this->collection($name, $property, $required, $pointer, $inputs, $root);
      return;
    }
    if (($extension['collection'] ?? NULL) === TRUE) {
      $this->note(static::below($pointer, ContractEmitter::EXTENSION, 'collection'), 'Not read: a collection is a list of structures, and the items here are no object.');
    }
    // The property as read: a type of the library's keywords laid in.
    [$definition, $default, $property] = $this->value($property, $pointer, $root, $types);
    $locked = $property->get('readOnly') === TRUE;
    if ($locked && $property->has('const')) {
      // Locked at what it holds: the value is what it starts from.
      $default = $property->get('const');
    }
    static::declareRead($inputs, $name, $this->finished($definition, $property, $required, in_array('null', $types, TRUE)), $default);
    if ($locked) {
      $inputs->lockRead($name);
    }
  }

  /**
   * Reads which id of the library a schema refers to, where it does.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema: a key's, or a list's items.
   * @param string $pointer
   *   Where it stands.
   *
   * @return array{id: string, leaf: string|null, kept: array<string, mixed>, flat: array<string, mixed>, object: bool}|null
   *   The id; the leaf type it reads as (LeafTypes::LIBRARY), and what of
   *   its document is kept beside the type; its document's keywords, every
   *   reference into the library followed, for a type read in place; and
   *   whether it is an object, read as a structure. NULL for a schema
   *   that refers to no id of the library, or to one the registry does
   *   not hold, noted.
   */
  protected function libraryOf(Schema $schema, string $pointer): ?array {
    $id = $schema->get('$ref');
    if (!static::isLibrary($id)) {
      return NULL;
    }
    $flat = $this->libraryKeywords($id);
    if ($flat === NULL) {
      $this->note(static::below($pointer, '$ref'), sprintf('Not read: the library has no %s.', $id));
      return NULL;
    }
    $leaf = LeafTypes::LIBRARY[$id] ?? NULL;
    $kept = [];
    if ($leaf !== NULL) {
      $row = LeafTypes::ROWS[$leaf];
      $kept = array_diff_key($flat, ['type' => TRUE]);
      if (($kept['format'] ?? NULL) === ($row['format'] ?? NULL)) {
        unset($kept['format']);
      }
    }
    $object = $leaf === NULL && static::isLibraryObject($flat);
    return ['id' => $id, 'leaf' => $leaf, 'kept' => $kept, 'flat' => $flat, 'object' => $object];
  }

  /**
   * Reads what a type of the library says, every reference followed.
   *
   * A type that refers to another (`portable:timestamp` is
   * `portable:integer`) says what that one does, its own keywords laid
   * over. What only names or annotates the type (`$id`, `$schema`,
   * `title`, `description`, `$comment`, the keywords UNREAD lists) is left
   * out: a key's own words are its site's.
   *
   * @param string $id
   *   The id.
   * @param string[] $seen
   *   The ids followed on the way here.
   *
   * @return array<string, mixed>|null
   *   The keywords, or NULL where the registry holds no object schema of
   *   that id.
   */
  protected function libraryKeywords(string $id, array $seen = []): ?array {
    $registry = $this->portable();
    if (!$registry->has($id)) {
      return NULL;
    }
    $schema = Schema::from($registry->get($id));
    if ($schema->isBoolean()) {
      return NULL;
    }
    $keywords = array_diff_key($schema->all(), array_flip(['$id', '$schema', 'title', 'description', '$comment']), self::UNREAD);
    $reference = $keywords['$ref'] ?? NULL;
    $followed = [...$seen, $id];
    if (is_string($reference) && str_starts_with($reference, self::LIBRARY_PREFIX) && !in_array($reference, $followed, TRUE)) {
      unset($keywords['$ref']);
      $keywords = array_replace($this->libraryKeywords($reference, $followed) ?? [], $keywords);
    }
    return $keywords;
  }

  /**
   * Registers the structure a schema of the registry is, to be built.
   *
   * Read from its own document, as a document's root is: an object type
   * of the library, a variant `portable-typeBy` chooses.
   *
   * @param string $id
   *   The schema's id in the registry, the structure's id.
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $inputs
   *   The blueprint reading the frame that holds it.
   *
   * @return string
   *   The name it is built by.
   */
  protected function libraryStructure(string $id, ResourceBlueprint $inputs): string {
    $document = Schemas::decoded($this->portable()->get($id));
    return $inputs->readStructure($id, static::prepared(is_array($document) ? $document : []), '');
  }

  /**
   * Gets the registry a library type and a `portable-typeBy` are read by.
   *
   * @return \PortableSchema\Registry
   *   The reader's own, or the one the build's reader holds (library()).
   */
  protected function portable(): Registry {
    return $this->library ?? static::library();
  }

  /**
   * Declares a key read from a schema with the method its kind is.
   *
   * One value with addDefinition(), a list with addList() and a map with
   * addMap(), each of its keys declared the same way inside, so a read is
   * declared as an author declares it. What the read says of the list or
   * the map itself (its label, constraints, settings and what the module
   * writes beside them) is laid on what the method declared.
   *
   * Decision: see docs/decisions.md#a-blueprint-takes-no-complex-data-types.
   *
   * @param \Drupal\surface\Resource\BlueprintInterface $inputs
   *   The blueprint.
   * @param string $name
   *   The key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $read
   *   What was read.
   * @param mixed $default
   *   What it starts from; NULL for nothing.
   */
  protected static function declareRead(BlueprintInterface $inputs, string $name, DataDefinitionInterface $read, mixed $default = NULL): void {
    if ($read instanceof ListDataDefinitionInterface) {
      $declared = $inputs->addList($name, $read->getItemDefinition(), $read->getLabel() ?? $name, $default);
    }
    elseif ($read instanceof ComplexDataDefinitionInterface) {
      $declared = $inputs->addMap($name, $read->getLabel() ?? $name, static function (BlueprintInterface $map) use ($read): void {
        foreach ($read->getPropertyDefinitions() as $key => $property) {
          static::declareRead($map, (string) $key, $property, DefinitionMetadata::hasDefaultValue($property) ? DefinitionMetadata::getDefaultValue($property) : NULL);
        }
      });
    }
    else {
      $inputs->addDefinition($name, $read, $default);
      return;
    }
    assert($read instanceof DataDefinition);
    $said = $read->toArray();
    foreach ($said as $keyword => $value) {
      if ($keyword !== 'type') {
        $declared[$keyword] = $value;
      }
    }
    if (!isset($said['label'])) {
      unset($declared['label']);
    }
  }

  /**
   * Reads which reference a site makes, and notes what narrows it.
   *
   * @param \PortableSchema\Builder\Schema $property
   *   The property.
   * @param string $pointer
   *   Its pointer.
   *
   * @return string|null
   *   The `$ref`, or NULL for none.
   */
  protected function siteReference(Schema $property, string $pointer): ?string {
    $reference = $property->get('$ref');
    if (is_string($reference)) {
      // A reference to another document, or anywhere but a def, is kept
      // whole by foreignValue().
      return static::isLocal($reference) ? $reference : NULL;
    }
    $all = is_array($property->get('allOf')) ? $property->get('allOf') : [];
    $first = $all[0] ?? NULL;
    if (Schemas::isObjectSchema($first) && is_string($first->get('$ref')) && count($first->all()) === 1 && static::isLocal($first->get('$ref'))) {
      if (count($all) > 1) {
        $this->note(static::below($pointer, 'allOf', 1), 'Not read: what a site narrows of a child (a lock its context made) is the context\'s, not the child\'s.');
      }
      return $first->get('$ref');
    }
    return NULL;
  }

  /**
   * Answers whether a schema refers to an object def.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   * @param \PortableSchema\Builder\Schema $root
   *   The document.
   *
   * @return bool
   *   TRUE when it does.
   */
  protected function refersToObject(Schema $schema, Schema $root): bool {
    $name = SchemaResolver::refersTo($schema);
    $defs = $root->get('$defs');
    $def = $name !== NULL && is_array($defs) ? ($defs[$name] ?? NULL) : NULL;
    return Schemas::isObjectSchema($def) && $this->isObject($def);
  }

  /**
   * Reads which of a container's keys a child's ports read.
   *
   * @param array $extension
   *   The site's `x-surface`.
   * @param \PortableSchema\Builder\Schema $def
   *   The child's def.
   * @param string $pointer
   *   Where `reads` stands.
   * @param string[] $keys
   *   The keys of the frame the child is attached in.
   *
   * @return array<string, string>
   *   Each port the def reads, mapped to the key wired to it.
   */
  protected function wiring(array $extension, Schema $def, string $pointer, array $keys): array {
    $reads = is_array($extension['reads'] ?? NULL) ? $extension['reads'] : [];
    $ports = static::extensionOf($def)['ports'] ?? NULL;
    $ports = is_array($ports) ? $ports : [];
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
   * @param \PortableSchema\Builder\Schema $property
   *   Its site: `$ref`, then what it says differently from the shape.
   * @param \PortableSchema\Builder\Schema $def
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
  protected function shapedKey(string $name, Schema $property, Schema $def, string $shape, bool $required, string $pointer, ResourceBlueprint $inputs): void {
    $definition = $inputs->add($name, $shape, is_string($property->get('title')) ? $property->get('title') : '');
    $site = array_diff_key($property->all(), ['$ref' => TRUE, 'title' => TRUE]);
    if (is_string($site['description'] ?? NULL)) {
      $definition->setDescription($site['description']);
    }
    if ($required) {
      $definition->setRequired(TRUE);
    }
    if (array_key_exists('default', $site) && $site['default'] !== NULL) {
      DefinitionMetadata::setDefaultValue($definition, $site['default']);
    }
    $narrowing = $site + ['type' => $def->get('type')];
    // A site that states no type is of its shape's.
    $types = array_values(array_diff((array) ($narrowing['type'] ?? []), ['null']));
    foreach ($this->constraints(Schema::from($narrowing), $pointer, $types, $foreign) as [$constraint, $options]) {
      // What the site narrows of a bound the shape holds is that bound,
      // tightened: the rest of it stays the shape's.
      $held = $definition->getConstraints()[$constraint] ?? [];
      if ($constraint === 'JsonSchema') {
        static::holdTo($definition, $options['schema']);
        continue;
      }
      $definition->addConstraint($constraint, in_array($constraint, ['Range', 'Length', 'Count'], TRUE) ? array_replace($held, $options) : $options);
    }
    static::holdTo($definition, static::fragment(Schema::from($foreign)));
    $this->settings($definition, Schemas::data($property->get(PortableKeywords::UI)) ?? []);
  }

  /**
   * Declares a collection: an array whose items are each a structure.
   *
   * @param string $name
   *   The key.
   * @param \PortableSchema\Builder\Schema $property
   *   Its schema.
   * @param bool $required
   *   Whether the frame requires it.
   * @param string $pointer
   *   Its pointer.
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $inputs
   *   The blueprint.
   * @param \PortableSchema\Builder\Schema $root
   *   The document.
   */
  protected function collection(string $name, Schema $property, bool $required, string $pointer, ResourceBlueprint $inputs, Schema $root): void {
    $items = $property->get('items');
    assert($items instanceof Schema);
    $reference = $this->siteReference($items, static::below($pointer, 'items'));
    $library = $reference === NULL ? $this->libraryOf($items, static::below($pointer, 'items')) : NULL;
    if ($reference !== NULL) {
      [$def_name] = $this->def($reference, static::below($pointer, 'items', '$ref'), $root);
      $child = $this->structureAt($inputs, $def_name, $this->document, static::below('', '$defs', $def_name));
    }
    elseif ($library !== NULL && $library['object']) {
      $child = $this->libraryStructure($library['id'], $inputs);
    }
    else {
      $child = $this->structureAt($inputs, $inputs->ownerId() . '.' . $name, $this->document, static::below($pointer, 'items'));
    }
    // How many items, and whether they differ: the table's Count and
    // UniqueItems rows, on an array. A collection holds no JsonSchema
    // constraint, so what they keep, and a bound that is no whole number,
    // is noted and not read.
    $bounds = [];
    $unread = [];
    $unique = FALSE;
    $counted = array_intersect_key($property->all(), array_flip(['minItems', 'maxItems', 'uniqueItems']));
    foreach (ConstraintKeywords::read($counted, ['array']) as $back) {
      foreach ($back->constraint === 'Count' ? $back->options : [] as $option => $value) {
        if (is_int($value)) {
          $bounds[$option] = $value;
        }
        else {
          $unread[] = $option === 'min' ? 'minItems' : 'maxItems';
        }
      }
      $unique = $unique || $back->constraint === 'UniqueItems';
      $unread = [...$unread, ...array_map('strval', array_keys($back->kept))];
    }
    foreach ($unread as $keyword) {
      $this->note(static::below($pointer, $keyword), 'Not read: a collection holds a whole number of items, and they differ or not.');
    }
    $list = $inputs->attachList($name, $child, static::title($property), $bounds['min'] ?? NULL, $bounds['max'] ?? NULL);
    if ($unique) {
      $list->addConstraint('UniqueItems', []);
    }
    if (is_string($property->get('description'))) {
      $list->setDescription($property->get('description'));
    }
    if ($required) {
      $list->setRequired(TRUE);
    }
    if (!in_array('null', (array) ($property->get('type') ?? []), TRUE) && !$required) {
      DefinitionMetadata::setAlwaysHeld($list);
    }
    foreach (array_diff_key($property->all(), array_flip(self::COLLECTION)) as $keyword => $value) {
      $this->note(static::below($pointer, (string) $keyword), 'Not read: a collection says its title, its description, how many items it holds and whether they differ.');
    }
  }

  /**
   * Answers whether an object is a plain map: keys and nothing more.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The object.
   *
   * @return bool
   *   TRUE when every property is a value, a list or a plain map, and the
   *   object says nothing a map cannot (a reference, a rule, variants).
   */
  protected function isPlainMap(Schema $schema): bool {
    if (array_diff(array_keys($schema->all()), self::MAP) !== []) {
      return FALSE;
    }
    if ($schema->has('additionalProperties') && Schemas::boolean($schema->get('additionalProperties')) !== FALSE) {
      return FALSE;
    }
    foreach ($schema->get('properties') as $property) {
      if (!Schemas::isObjectSchema($property) || $property->get('$ref') !== NULL || $property->get('allOf') !== NULL || isset(static::extensionOf($property)['by']) || $property->has(self::TYPE_BY)) {
        return FALSE;
      }
      $types = array_values(array_diff((array) ($property->get('type') ?? []), ['null']));
      $nested = $property->get('properties');
      if ($types === ['object'] && is_array($nested) && $nested !== [] && !$this->isPlainMap($property)) {
        return FALSE;
      }
      if ($types === ['array'] && Schemas::isObjectSchema($property->get('items')) && $this->isObject($property->get('items'))) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Reads a plain map.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The object.
   * @param string $pointer
   *   Its pointer.
   * @param \PortableSchema\Builder\Schema $root
   *   The document.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The map, each property a definition of its own.
   */
  protected function map(Schema $schema, string $pointer, Schema $root): MapDataDefinition {
    $map = MapDataDefinition::create();
    if (Schemas::boolean($schema->get('additionalProperties')) !== FALSE) {
      $this->note(static::below($pointer, 'additionalProperties'), 'Read closed: a map holds the keys it declares, and a key the schema leaves open is refused.');
    }
    $extension = static::extensionOf($schema);
    if (($extension['group'] ?? FALSE) === TRUE) {
      DefinitionMetadata::setGrouping($map);
    }
    if (is_string($extension['after'] ?? NULL)) {
      DefinitionMetadata::setPlacedAfter($map, $extension['after']);
    }
    $groups = Schemas::data((Schemas::data($schema->get(PortableKeywords::UI)) ?? [])['groups'] ?? NULL) ?? [];
    $required = array_map('strval', is_array($schema->get('required')) ? $schema->get('required') : []);
    foreach ($schema->get('properties') as $name => $property) {
      $name = (string) $name;
      assert($property instanceof Schema);
      $at = static::below($pointer, 'properties', $name);
      $types = $this->types($property, $at);
      $nested = $property->get('properties');
      $read = $property;
      if (array_values(array_diff($types, ['null'])) === ['object'] && is_array($nested) && $nested !== []) {
        $inner = $this->map($property, $at, $root);
      }
      else {
        [$inner, $default, $read] = $this->value($property, $at, $root, $types);
        if ($default !== NULL) {
          DefinitionMetadata::setDefaultValue($inner, $default);
        }
      }
      $this->droppedExtension(static::extensionOf($property), static::below($at, ContractEmitter::EXTENSION));
      $this->droppedLinks($property, $at);
      // Each key of the map is arranged in it, among the map's groups.
      $arrangement = $this->arranged($property, $groups, $at, $pointer);
      if ($arrangement !== NULL) {
        DefinitionMetadata::setArrangement($inner, $arrangement);
      }
      $map->setPropertyDefinition($name, $this->finished($inner, $read, in_array($name, $required, TRUE), in_array('null', $types, TRUE)));
    }
    return $map;
  }

  /**
   * Gives a definition what every key has: its label, and what it holds.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param \PortableSchema\Builder\Schema $property
   *   Its schema.
   * @param bool $required
   *   Whether its frame requires it.
   * @param bool $nullable
   *   Whether its type allows null.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The same.
   */
  protected function finished(DataDefinitionInterface $definition, Schema $property, bool $required, bool $nullable): DataDefinitionInterface {
    if ($definition instanceof DataDefinition) {
      if (is_string($property->get('title'))) {
        $definition->setLabel($property->get('title'));
      }
      if (is_string($property->get('description'))) {
        $definition->setDescription($property->get('description'));
      }
      if ($required) {
        $definition->setRequired(TRUE);
      }
    }
    if ($property->get('writeOnly') === TRUE) {
      DefinitionMetadata::setSecret($definition);
    }
    elseif (!$required && !$nullable && $property->has('default') && $property->get('default') !== NULL && $property->get('readOnly') !== TRUE) {
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
    if (($extension['deferred'] ?? NULL) === TRUE || is_array($extension['deferred'] ?? NULL)) {
      $this->note(static::below($pointer, 'deferred'), 'Not read: a list the site decides, left out of this document for its size, so the key is read open.');
    }
  }

  /**
   * Reads the JSON types a property allows.
   *
   * @param mixed $property
   *   The property: a schema, or anything a `properties` map holds.
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
  protected function types(mixed $property, string $pointer): array {
    if (!Schemas::isObjectSchema($property)) {
      return [];
    }
    if ($property->get('type') !== NULL) {
      $types = array_map(static fn (mixed $type): string => is_string($type) ? $type : json_encode($type), (array) $property->get('type'));
      foreach ($types as $type) {
        if (!in_array($type, self::TYPES, TRUE)) {
          throw new \InvalidArgumentException(sprintf('%s: "%s" is no JSON Schema type.', static::below($pointer, 'type'), $type));
        }
      }
      return $types;
    }
    $values = [];
    if (is_array($property->get('oneOf'))) {
      foreach ($property->get('oneOf') as $choice) {
        if (!Schemas::isObjectSchema($choice) || !$choice->has('const')) {
          return [];
        }
        $values[] = $choice->get('const');
      }
    }
    elseif (is_array($property->get('enum'))) {
      $values = $property->get('enum');
    }
    elseif ($property->has('const')) {
      $values = [$property->get('const')];
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
   * @param \PortableSchema\Builder\Schema $property
   *   The property.
   * @param string $pointer
   *   Its pointer.
   * @param \PortableSchema\Builder\Schema $root
   *   The document.
   * @param string[] $types
   *   Its types, as types() read them.
   *
   * @return array{0: \Drupal\Core\TypedData\DataDefinitionInterface, 1: mixed, 2: \PortableSchema\Builder\Schema}
   *   The definition, the default it starts from (NULL for none), and the
   *   property as read: a type of the library's keywords laid under the
   *   site's, so what finished() reads of it (`writeOnly`, `readOnly`,
   *   `default`) is the library's where the site says nothing.
   */
  protected function value(Schema $property, string $pointer, Schema $root, array $types): array {
    $property = $this->throughLibrary($property, $root);
    // A type of the library, read as a leaf type or where it stands.
    $leaf = NULL;
    $read = $property;
    // What the library says beyond what the definition reads, kept so the
    // key is no wider.
    $extra = [];
    $library = $this->libraryOf($property, $pointer);
    if ($library !== NULL && $library['leaf'] !== NULL) {
      $leaf = $library['leaf'];
      [$property, $extra] = $this->besideLeaf($property->without('$ref'), $library, $pointer);
      $types = $property->get('type') !== NULL ? $this->types($property, $pointer) : [LeafTypes::ROWS[$leaf]['type']];
      $read = Schema::from(array_replace($library['flat'], $property->all()));
    }
    elseif ($library !== NULL && !$library['object']) {
      $this->note(static::below($pointer, '$ref'), sprintf('Read in place: %s, from the library, is the key\'s own.', $library['id']));
      $property = Schema::from(array_replace($library['flat'], $property->without('$ref')->all()));
      $types = $this->types($property, $pointer);
      $read = $property;
      $format = $property->get('format');
      if (is_string($format) && array_values(array_diff($types, ['null'])) !== ['string']) {
        // A format no string reads (`int32`): kept for the server's check.
        $extra['format'] = $format;
        $property = $property->without('format');
      }
    }
    $not_null = array_values(array_diff($types, ['null']));
    $default = $read->get('default');
    if ($not_null === ['array'] && $property->get('prefixItems') === NULL) {
      $items = Schemas::isObjectSchema($property->get('items')) ? $this->throughLibrary($property->get('items'), $root) : Schema::any();
      $item_types = $this->types($items, static::below($pointer, 'items'));
      $item_library = $this->libraryOf($items, static::below($pointer, 'items'));
      if (array_values(array_diff($item_types, ['null'])) === ['array'] || ($items->all() !== [] && $this->isObject($items) && ($item_library === NULL || $item_library['object']))) {
        $item = $this->foreignValue($items, static::below($pointer, 'items'), $root);
      }
      else {
        [$item] = $this->value($items, static::below($pointer, 'items'), $root, $item_types === [] ? [] : array_values(array_diff($item_types, ['null'])));
        if ($item instanceof DataDefinition && is_string($items->get('title'))) {
          $item->setLabel($items->get('title'));
        }
      }
      $list = new ListDataDefinition(['type' => 'list'], $item);
      $foreign = [];
      foreach ($this->constraints($property->without('items'), $pointer, ['array'], $foreign) as [$constraint, $options]) {
        $list->addConstraint($constraint, $options);
      }
      static::holdTo($list, $this->withDefs($foreign, $root));
      $this->settings($list, Schemas::data($property->get(PortableKeywords::UI)) ?? []);
      $this->examples($list, $property);
      return [$list, $default, $read];
    }
    if (count($not_null) !== 1 || in_array($not_null[0], ['object', 'array', 'null'], TRUE)) {
      return [$this->foreignValue($property, $pointer, $root), $default, $read];
    }
    // The type table read backwards: the emitter's rows, one class.
    // Decision: see
    // docs/decisions.md#one-table-of-leaf-types-read-by-the-blueprint-the-emitter-and-the-reader.
    $type = $leaf ?? LeafTypes::read($not_null[0]) ?? 'string';
    $format = is_string($property->get('format')) ? $property->get('format') : NULL;
    if ($leaf === NULL && $type === 'string' && $format !== NULL) {
      if (LeafTypes::readsFormat($format)) {
        $type = (string) LeafTypes::read('string', $format);
        if ($format === 'idn-email') {
          $this->note(static::below($pointer, 'format'), 'Read as an email address: written back as email, the dialect\'s format.');
        }
      }
      elseif (!isset(self::FORMAT_PATTERNS[$format]) && !ConstraintKeywords::readsFormat($format)) {
        $this->note(static::below($pointer, 'format'), sprintf('Dropped: no constraint checks the "%s" format, an annotation.', $format));
      }
    }
    $definition = DataDefinition::create($type);
    $foreign = [];
    foreach ($this->constraints($property, $pointer, $not_null, $foreign) as [$constraint, $options]) {
      $definition->addConstraint($constraint, $options);
    }
    if ($type === 'string' && $format !== NULL && isset(self::FORMAT_PATTERNS[$format]) && $property->get('pattern') === NULL) {
      if (isset($definition->getConstraints()['Regex'])) {
        // A definition holds one Regex, and the table read one (a `not`):
        // the format's pattern is kept, as a `pattern`, which the
        // JsonSchema constraint checks where it checks no format.
        $foreign['pattern'] = PatternTranslation::ecma(self::FORMAT_PATTERNS[$format]);
        $this->note(static::below($pointer, 'format'), sprintf('Kept as a JsonSchema constraint, as the pattern of a "%s": a definition holds one Regex, and the pattern it must not match is that one.', $format));
      }
      else {
        $definition->addConstraint('Regex', ['pattern' => self::FORMAT_PATTERNS[$format]]);
      }
    }
    if ($type === 'string' && $format === 'date' && $property->get('oneOf') === NULL && $property->get('enum') === NULL) {
      // A date is drawn as a date input, which is what the emitter states
      // the format for; an `x-portable-ui.widget` read below still decides.
      $definition->setSetting('widget', 'date');
    }
    if ($extra !== []) {
      // What the library says beyond the type's row: the key is no wider.
      static::holdTo($definition, static::fragment(Schema::from($extra)));
    }
    static::holdTo($definition, $this->withDefs($foreign, $root));
    $this->settings($definition, Schemas::data($property->get(PortableKeywords::UI)) ?? []);
    $this->examples($definition, $property);
    return [$definition, $default, $read];
  }

  /**
   * Reads what a site says beside a `$ref` to a leaf type of the library.
   *
   * A `type` or a `format` that says otherwise than the type's row is
   * noted and not read: the row stands. A `format` the row states none of
   * is kept beside the type, as what the library says beyond the row is.
   *
   * @param \PortableSchema\Builder\Schema $site
   *   What the site says, its `$ref` left out.
   * @param array{id: string, leaf: string|null, kept: array<string, mixed>, flat: array<string, mixed>, object: bool} $library
   *   What libraryOf() read of the type.
   * @param string $pointer
   *   Where the site stands.
   *
   * @return array{0: \PortableSchema\Builder\Schema, 1: array<string, mixed>}
   *   What the site says that is read, and what is kept beside the type
   *   for a JsonSchema constraint.
   */
  protected function besideLeaf(Schema $site, array $library, string $pointer): array {
    $row = LeafTypes::ROWS[(string) $library['leaf']];
    $extra = $library['kept'];
    if ($site->get('type') !== NULL) {
      $stated = $this->types($site, $pointer);
      if (array_values(array_diff($stated, ['null'])) !== [$row['type']]) {
        $this->note(static::below($pointer, 'type'), sprintf('Not read: type %s beside %s, which states %s.', implode(', ', $stated), $library['id'], $row['type']));
        $site = $site->with('type', in_array('null', $stated, TRUE) ? [$row['type'], 'null'] : $row['type']);
      }
    }
    $format = $site->get('format');
    if (is_string($format) && isset($row['format']) && $format !== $row['format']) {
      $this->note(static::below($pointer, 'format'), sprintf('Not read: format %s beside %s, which states %s.', $format, $library['id'], $row['format']));
      $site = $site->without('format');
    }
    elseif (is_string($format) && !isset($row['format'])) {
      $extra['format'] = $format;
      $site = $site->without('format');
    }
    return [$site, $extra];
  }

  /**
   * Reads a schema that stands for a type of the library as a `$ref` to it.
   *
   * A local `$ref` to a def that is a `$ref` to the library, with keywords
   * of its own or not, is that `$ref`: the def's keywords, its title left
   * out, under the site's. An `anyOf` of a `$ref` to a type of the library
   * that is no object and `{"type": "null"}`, in either order, is that
   * `$ref`, its type allowing null.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema: a key's, or a list's items.
   * @param \PortableSchema\Builder\Schema $root
   *   The document.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema as a `$ref` to the library; the same value where it is
   *   neither.
   */
  protected function throughLibrary(Schema $schema, Schema $root): Schema {
    $reference = $schema->get('$ref');
    $name = is_string($reference) ? SchemaResolver::defName($reference) : NULL;
    if ($name !== NULL) {
      $defs = $root->get('$defs');
      $def = is_array($defs) ? ($defs[$name] ?? NULL) : NULL;
      if (Schemas::isObjectSchema($def) && static::isLibrary($def->get('$ref'))) {
        return Schema::from(array_replace(array_diff_key($def->all(), ['title' => TRUE]), $schema->without('$ref')->all()));
      }
      return $schema;
    }
    $any = $schema->get('anyOf');
    if ($reference !== NULL || $schema->has('type') || !is_array($any) || count($any) !== 2) {
      return $schema;
    }
    foreach ([[0, 1], [1, 0]] as [$one, $other]) {
      $type = $any[$one] ?? NULL;
      $null = $any[$other] ?? NULL;
      if (!Schemas::isObjectSchema($type) || !Schemas::isObjectSchema($null) || array_keys($type->all()) !== ['$ref'] || !static::isLibrary($type->get('$ref')) || array_keys($null->all()) !== ['type'] || (array) $null->get('type') !== ['null']) {
        continue;
      }
      $id = (string) $type->get('$ref');
      $flat = $this->libraryKeywords($id);
      $stated = $flat === NULL ? [] : array_map('strval', (array) ($flat['type'] ?? []));
      if ($flat === NULL || static::isLibraryObject($flat) || $stated === []) {
        return $schema;
      }
      return Schema::from(['$ref' => $id, 'type' => array_values(array_unique([...$stated, 'null']))] + $schema->without('anyOf')->all());
    }
    return $schema;
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
    return is_string($reference) && str_starts_with($reference, self::LIBRARY_PREFIX);
  }

  /**
   * Answers whether a type of the library is an object, read as a structure.
   *
   * @param array<string, mixed> $flat
   *   Its keywords, as libraryKeywords() read them.
   *
   * @return bool
   *   TRUE for a type whose `type` is `object`, or that states no type and
   *   declares properties.
   */
  protected static function isLibraryObject(array $flat): bool {
    $types = (array) ($flat['type'] ?? []);
    return in_array('object', $types, TRUE) || ($types === [] && isset($flat['properties']));
  }

  /**
   * Reads a value no data type of its own says: held whole to its schema.
   *
   * @param \PortableSchema\Builder\Schema $property
   *   The property.
   * @param string $pointer
   *   Its pointer.
   * @param \PortableSchema\Builder\Schema $root
   *   The document.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   An `any` definition, held to the property's schema.
   */
  protected function foreignValue(Schema $property, string $pointer, Schema $root): DataDefinition {
    $definition = DataDefinition::create('any');
    DefinitionMetadata::setHeldWhole($definition);
    $keywords = [];
    foreach (array_diff_key($property->all(), array_flip(self::WHOLE)) as $keyword => $value) {
      $keyword = (string) $keyword;
      $at = static::below($pointer, $keyword);
      if ($this->unread($keyword, $at)) {
        continue;
      }
      $note = static::keptNote($keyword);
      if ($note !== NULL && !isset($this->notes[$at])) {
        $this->note($at, $note);
      }
      $keywords[$keyword] = $value;
    }
    $kept = $this->keptSchema(Schema::from($keywords), $pointer);
    if ($kept->all() !== []) {
      $definition->addConstraint('JsonSchema', ['schema' => $this->withDefs($kept->all(), $root)]);
      $this->note($pointer, sprintf('Kept as a JsonSchema constraint: %s, which no data type says.', implode(', ', array_keys($kept->all()))));
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
   * @param \PortableSchema\Builder\Schema $property
   *   Its schema.
   */
  protected function examples(DataDefinitionInterface $definition, Schema $property): void {
    $examples = $property->get('examples');
    if (is_array($examples) && $examples !== []) {
      DefinitionMetadata::setExamples($definition, array_values($examples));
    }
  }

  /**
   * Reads the widget a key's `x-portable-ui` asks for.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param array $ui
   *   The key's `x-portable-ui`.
   */
  protected function settings(DataDefinitionInterface $definition, array $ui): void {
    if (!$definition instanceof DataDefinition) {
      return;
    }
    $widget = $ui['widget'] ?? NULL;
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
   * The table of constraints and keywords reads what it maps
   * (ConstraintKeywords::read()); a list of values and what a schema kept
   * are read here, and every other keyword that checks a value is kept in
   * the JsonSchema constraint.
   *
   * @param \PortableSchema\Builder\Schema $property
   *   The value's schema.
   * @param string $pointer
   *   Its pointer.
   * @param string[] $types
   *   Its types, null left out.
   * @param array|null $foreign
   *   Collects what no constraint says: the keywords kept for a
   *   JsonSchema constraint, each with its value (a Schema where it holds
   *   one).
   *
   * @param-out array $foreign
   *
   * @return array<int, array{0: string, 1: array}>
   *   Each constraint and its options, in the order the reader declares
   *   them (ConstraintKeywords::READ_ORDER).
   */
  protected function constraints(Schema $property, string $pointer, array $types, ?array &$foreign = []): array {
    $foreign = [];
    // The table reads only its own keywords, as JSON decodes them: the
    // rest of the schema, and the schemas it holds, is not decoded.
    $table = [];
    foreach (array_intersect_key($property->all(), ConstraintKeywords::keywords()) as $keyword => $value) {
      $table[$keyword] = is_array($value) || $value instanceof Schema || $value instanceof \stdClass ? Schemas::decoded($value) : $value;
    }
    $read = ConstraintKeywords::read($table, $types);
    $taken = [];
    foreach ($read as $back) {
      foreach ($back->consumed as $keyword) {
        $taken[$keyword] = TRUE;
      }
      foreach (array_keys($back->kept) as $keyword) {
        $taken[$keyword] = TRUE;
        // What the row keeps is the keyword's value: read from the schema.
        $foreign[$keyword] = $this->kept($property->get((string) $keyword), static::below($pointer, (string) $keyword));
        if ($back->note !== NULL) {
          $this->note(static::below($pointer, (string) $keyword), $back->note);
        }
      }
    }
    foreach ($property->all() as $keyword => $value) {
      $keyword = (string) $keyword;
      $at = static::below($pointer, $keyword);
      if (isset($taken[$keyword])) {
        continue;
      }
      switch ($keyword) {
        case 'oneOf':
        case 'enum':
        case 'const':
          if ($keyword === 'oneOf' && $this->referenceOptions($property) !== NULL) {
            // A reference's candidates are the site's, listed from its
            // check under the limit.
            $this->note($at, 'Read from the reference: the site lists its candidates.');
            break;
          }
          $choice = $this->choice($property, $keyword);
          if ($choice === NULL) {
            if ($keyword !== 'const' || $property->get('readOnly') !== TRUE) {
              $foreign[$keyword] = $this->kept($value, $at);
            }
            break;
          }
          if ($choice !== []) {
            $read[] = new ReadBack($choice[0], $choice[1], [$keyword]);
          }
          break;

        case ContractEmitter::EXTENSION:
          break;

        case PortableKeywords::CHECK:
          foreach (PortableKeywords::checks($property->all()) as $check) {
            $why = NULL;
            if ($check['constraint'] === 'JsonSchema' && is_array($check['options']['schema'] ?? NULL)) {
              $held_at = static::below($at, PortableKeywords::PROVIDER . 'JsonSchema', 'schema');
              $read[] = new ReadBack('JsonSchema', ['schema' => static::fragment($this->keptSchema(static::checked($check['options']['schema'], $held_at), $held_at))], [$keyword]);
            }
            elseif ($check['constraint'] === References::CONSTRAINT && ($refers = $this->referenceOptions($property, $why)) !== NULL) {
              // What a key refers to, as its author declares it.
              // Decision: see
              // docs/decisions.md#a-reference-and-a-deferred-list-are-the-dialects-check-options-and-links.
              $read[] = new ReadBack(References::CONSTRAINT, $refers, [$keyword]);
            }
            elseif ($check['constraint'] === References::CONSTRAINT && $why !== NULL) {
              $this->note(static::below($at, PortableKeywords::PROVIDER . $check['constraint']), 'Not read: ' . $why . ', so the constraint the server checks is known by its plugin alone.');
            }
            else {
              $this->note(static::below($at, PortableKeywords::PROVIDER . $check['constraint']), 'Not read: a constraint the server checks is known by its plugin alone.');
            }
          }
          break;

        case '$id':
        case '$schema':
          $this->note($at, self::IDENTIFIER_DROPPED);
          break;

        default:
          if (in_array($keyword, self::VALUE, TRUE) || in_array($keyword, ['items', '$ref'], TRUE)) {
            break;
          }
          if ($this->unread($keyword, $at)) {
            break;
          }
          if (in_array($keyword, self::ANNOTATIONS, TRUE) || str_starts_with($keyword, 'x-')) {
            $this->note($at, 'Dropped: an annotation, which checks nothing.');
            break;
          }
          $foreign[$keyword] = $this->kept($value, $at);
          $note = static::keptNote($keyword);
          if ($note === NULL || !isset($this->notes[$at])) {
            $this->note($at, $note ?? 'Kept as a JsonSchema constraint: no constraint of the vocabulary says it.');
          }
      }
    }
    $constraints = [];
    foreach (ConstraintKeywords::ordered($read, $property->all()) as $back) {
      if ($back->constraint !== NULL) {
        $constraints[] = [$back->constraint, $back->options];
      }
    }
    return $constraints;
  }

  /**
   * Reads a list of allowed values.
   *
   * @param \PortableSchema\Builder\Schema $property
   *   The value's schema.
   * @param string $keyword
   *   `oneOf`, `enum` or `const`.
   *
   * @return array|null
   *   The constraint and its options; an empty array where the list only
   *   allows null; NULL when the keyword is no list of values.
   */
  protected function choice(Schema $property, string $keyword): ?array {
    $values = [];
    $labels = [];
    $descriptions = [];
    if ($keyword === 'oneOf') {
      $all = $property->get('oneOf');
      if (!is_array($all) || $all === []) {
        return NULL;
      }
      foreach ($all as $choice) {
        $choosing = Schemas::isObjectSchema($choice) && $choice->has('const') && array_diff(array_keys($choice->all()), self::CHOICE) === [];
        if (!$choosing || (!is_scalar($choice->get('const')) && $choice->get('const') !== NULL)) {
          return NULL;
        }
        if ($choice->get('const') === NULL) {
          continue;
        }
        $values[] = $choice->get('const');
        $labels[] = is_string($choice->get('title')) ? $choice->get('title') : (string) $choice->get('const');
        $descriptions[] = is_string($choice->get('description')) ? $choice->get('description') : NULL;
      }
    }
    elseif ($keyword === 'enum') {
      $enum = $property->get('enum');
      if (!is_array($enum) || $enum === [] || array_filter($enum, static fn (mixed $value): bool => !is_scalar($value) && $value !== NULL) !== []) {
        return NULL;
      }
      $named = is_array($property->get('meta:enum')) ? $property->get('meta:enum') : [];
      foreach ($enum as $value) {
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
      if ($property->get('readOnly') === TRUE || !is_scalar($property->get('const'))) {
        return NULL;
      }
      if (is_string(static::extensionOf($property)['fixed'] ?? NULL)) {
        // A deciding key whose variants offer one value: the list is the
        // variants', so the key declares none of its own.
        // Decision: see
        // docs/decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter.
        return [];
      }
      return ['Choice', ['choices' => [$property->get('const')]]];
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
   * Reads the conditionals of a frame: rules, and what is left over.
   *
   * @param array<int, \PortableSchema\Builder\Schema> $conditionals
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
      $then = $conditional->get('then');
      $said = $then->get('properties');
      if (array_keys($then->all()) === ['properties'] && is_array($said)) {
        foreach ($said as $key => $narrowing) {
          if (Schemas::boolean($narrowing) === FALSE && count($said) === 1) {
            $ruling[(string) $key][] = static::plain($conditional->get('if'));
          }
        }
      }
    }
    $unless = [];
    foreach ($ruling as $key => $ifs) {
      $any = count($ifs) === 1 ? $ifs[0] : Schema::any()->with('anyOf', $ifs);
      $unless[$key] = array_keys($any->all()) === ['not'] && Schemas::isObjectSchema($any->get('not')) ? $any->get('not') : Schema::any()->with('not', $any);
    }

    foreach ($conditionals as $i => $conditional) {
      $at = static::below($pointer, 'allOf', $i);
      $if = static::plain($conditional->get('if'));
      assert($if instanceof Schema);
      $then = $conditional->get('then');
      $required = is_array($then->get('required')) ? array_map('strval', $then->get('required')) : [];
      if (array_diff(array_keys($then->all()), ['properties', 'required']) === [] && $then->get('properties') === NULL && count($required) === 1 && isset($unless[$required[0]]) && Schemas::same($if, $unless[$required[0]])) {
        // Required wherever no rule rules it out: the key is required.
        $this->requireKey($inputs, $required[0]);
        continue;
      }
      // A rule requiring a key another rule may rule out, guarded so.
      $guarded = $if->get('allOf');
      if (array_keys($if->all()) === ['allOf'] && is_array($guarded) && count($guarded) === 2 && count($required) === 1 && isset($unless[$required[0]]) && Schemas::same($guarded[1], $unless[$required[0]]) && $guarded[0] instanceof Schema) {
        $if = $guarded[0];
      }
      $condition = static::conditionOf($if);
      $effects = $condition === NULL ? NULL : $this->effects($then, $properties);
      if ($condition === NULL || $effects === NULL) {
        $left['allOf'][] = $this->kept(Schema::any()->with('if', $conditional->get('if'))->with('then', $then), $at);
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
   * @param \PortableSchema\Builder\Schema $then
   *   The `then`.
   * @param array $properties
   *   The frame's properties, each key as declared.
   *
   * @return array<string, array<int, array{0: string, 1?: mixed}>>|null
   *   Each key's effects, in order: a constraint and its options,
   *   `require`, `doesNotApply`, `because` and its text; NULL when the
   *   `then` does anything a rule cannot.
   */
  protected function effects(Schema $then, array $properties): ?array {
    if (array_diff(array_keys($then->all()), ['properties', 'required']) !== []) {
      return NULL;
    }
    $required = is_array($then->get('required')) ? $then->get('required') : [];
    $effects = [];
    foreach (is_array($then->get('properties')) ? $then->get('properties') : [] as $key => $narrowing) {
      $key = (string) $key;
      $declared = $properties[$key] ?? NULL;
      if (!Schemas::isObjectSchema($declared)) {
        return NULL;
      }
      if (Schemas::boolean($narrowing) === FALSE) {
        $effects[$key][] = ['doesNotApply'];
        continue;
      }
      if (!Schemas::isObjectSchema($narrowing)) {
        return NULL;
      }
      $said = $this->narrowed($narrowing, $declared, in_array($key, $required, TRUE));
      if ($said === NULL) {
        return NULL;
      }
      $effects[$key] = $said;
    }
    foreach ($required as $key) {
      $key = (string) $key;
      if (!Schemas::isObjectSchema($properties[$key] ?? NULL)) {
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
   * @param \PortableSchema\Builder\Schema $narrowing
   *   What the `then` says of the key.
   * @param \PortableSchema\Builder\Schema $declared
   *   The key as declared.
   * @param bool $requires
   *   Whether the `then` requires it.
   *
   * @return array<int, array{0: string, 1?: mixed}>|null
   *   The effects, or NULL for a keyword no effect says.
   */
  protected function narrowed(Schema $narrowing, Schema $declared, bool $requires): ?array {
    $effects = [];
    $bounds = [];
    $declared_types = (array) ($declared->get('type') ?? []);
    $keywords = $narrowing->all();
    // A rule's bound is `minimum`, `maxLength` and the like, never an
    // exclusive one, which the table also reads on an integer.
    if (array_intersect_key($keywords, ['exclusiveMinimum' => TRUE, 'exclusiveMaximum' => TRUE]) !== []) {
      return NULL;
    }
    // The table reads the constraints; a rule takes those of
    // Rule::CONSTRAINTS, with their options. A key that states no type
    // here (a shape's site, by `$ref`) is read as of any type a row
    // applies to: the build refuses a rule its key cannot take.
    $types = array_values(array_diff($declared_types, ['null'])) ?: ['integer', 'number', 'string', 'array'];
    $taken = [];
    $decoded = Schemas::decoded($narrowing);
    foreach (ConstraintKeywords::read(is_array($decoded) ? $decoded : [], $types) as $back) {
      $options = Rule::CONSTRAINTS[$back->constraint ?? ''] ?? NULL;
      if ($back->kept !== [] || $options === NULL || array_diff_key($back->options, array_flip($options)) !== []) {
        return NULL;
      }
      foreach ($back->consumed as $keyword) {
        $taken[$keyword] = $back;
      }
    }
    foreach ($keywords as $keyword => $value) {
      if (isset($taken[$keyword])) {
        $bounds[$taken[$keyword]->constraint] ??= $taken[$keyword]->options;
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
          if ($value !== $declared->get('title')) {
            return NULL;
          }
          break;

        case 'oneOf':
          $values = [];
          foreach (is_array($value) ? $value : [] as $choice) {
            if (!Schemas::isObjectSchema($choice) || !$choice->has('const')) {
              return NULL;
            }
            if ($choice->get('const') !== NULL) {
              $values[] = $choice->get('const');
            }
          }
          $bounds['Choice']['choices'] = $values;
          break;

        case 'items':
          if (!Schemas::isObjectSchema($value) || array_keys($value->all()) !== ['oneOf']) {
            return NULL;
          }
          $values = [];
          foreach (is_array($value->get('oneOf')) ? $value->get('oneOf') : [] as $choice) {
            if (!Schemas::isObjectSchema($choice) || !$choice->has('const')) {
              return NULL;
            }
            $values[] = $choice->get('const');
          }
          $bounds['Choice']['choices'] = $values;
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
   * Holds a definition to a schema, beside a schema it is held to already.
   *
   * A definition holds one JsonSchema constraint. One read from
   * `portable-check`, or a shape's, and the keywords kept for
   * the value are both checked: one constraint whose schema is the
   * `allOf` of the two, their defs beside it.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param array $schema
   *   The schema; nothing is added for an empty one.
   */
  protected static function holdTo(DataDefinitionInterface $definition, array $schema): void {
    if ($schema === []) {
      return;
    }
    $held = $definition->getConstraints()['JsonSchema']['schema'] ?? NULL;
    if (is_array($held) && $held !== [] && !Schemas::same($held, $schema)) {
      $defs = (is_array($held['$defs'] ?? NULL) ? $held['$defs'] : []) + (is_array($schema['$defs'] ?? NULL) ? $schema['$defs'] : []);
      unset($held['$defs'], $schema['$defs']);
      $schema = ['allOf' => [$held, $schema]] + ($defs === [] ? [] : ['$defs' => $defs]);
    }
    $definition->addConstraint('JsonSchema', ['schema' => $schema]);
  }

  /**
   * Gives a schema left over the defs it refers to.
   *
   * @param array<string, mixed> $schema
   *   The keywords left over, each with its value.
   * @param \PortableSchema\Builder\Schema $root
   *   The document.
   *
   * @return array
   *   The schema they are, with `$defs` holding each def it refers to, at
   *   any depth, and each `$ref` to a type of the library the registry
   *   holds replaced by what the type says (libraryInlined()), in the
   *   array form (fragment()); empty for none.
   */
  protected function withDefs(array $schema, Schema $root): array {
    if ($schema === []) {
      return [];
    }
    $kept = Schema::from($schema);
    $needed = [];
    $this->referred($kept, $root, $needed);
    if ($needed !== []) {
      $defs = [];
      $all = $root->get('$defs');
      foreach ($needed as $name) {
        $defs[$name] = static::plain($all[$name]);
      }
      $kept = Schemas::defaults($kept, ['$defs' => $defs]);
    }
    return static::fragment($this->libraryInlined($kept));
  }

  /**
   * Replaces each `$ref` to a type of the library with what the type says.
   *
   * At any depth a schema is held, so a schema kept for the server's
   * JsonSchema check refers to nothing outside it: the type's keywords
   * (libraryKeywords()), the site's laid over them. A `$ref` to an id the
   * registry does not hold stays, and one inside the type it leads to is
   * left as it is.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   * @param string[] $seen
   *   The ids replaced on the way here.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema; the same value where it refers to no type of the
   *   library.
   */
  protected function libraryInlined(Schema $schema, array $seen = []): Schema {
    if ($schema->isBoolean()) {
      return $schema;
    }
    $reference = $schema->get('$ref');
    if (static::isLibrary($reference) && !in_array($reference, $seen, TRUE)) {
      $flat = $this->libraryKeywords((string) $reference);
      if ($flat !== NULL) {
        $seen[] = (string) $reference;
        $schema = Schema::from(array_replace($flat, $schema->without('$ref')->all()));
      }
    }
    $keywords = $schema->all();
    $changed = FALSE;
    foreach ($keywords as $keyword => $value) {
      $shape = Keywords::shape((string) $keyword);
      $inlined = match (TRUE) {
        $shape === Keywords::SCHEMA && $value instanceof Schema => $this->libraryInlined($value, $seen),
        ($shape === Keywords::SCHEMA_MAP || $shape === Keywords::SCHEMA_LIST) && is_array($value) => array_map(fn (mixed $member): mixed => $member instanceof Schema ? $this->libraryInlined($member, $seen) : $member, $value),
        default => $value,
      };
      if ($inlined !== $value) {
        $keywords[$keyword] = $inlined;
        $changed = TRUE;
      }
    }
    return $changed ? Schema::from($keywords) : $schema;
  }

  /**
   * Writes a schema kept for a JsonSchema constraint in the array form.
   *
   * The constraint's options are arrays, which leave the reader here
   * (Schemas::arrayed()).
   *
   * @param \PortableSchema\Builder\Schema $kept
   *   The schema.
   *
   * @return array
   *   The schema in the array form; empty where it says nothing.
   */
  protected static function fragment(Schema $kept): array {
    if ($kept->isBoolean() || $kept->all() === []) {
      return [];
    }
    $arrayed = Schemas::arrayed($kept);
    assert(is_array($arrayed));
    return $arrayed;
  }

  /**
   * Collects the defs a schema refers to, through the defs themselves.
   *
   * @param mixed $schema
   *   A schema, or a list or a map of them.
   * @param \PortableSchema\Builder\Schema $root
   *   The document.
   * @param string[] $needed
   *   Collects the defs' keys.
   */
  protected function referred(mixed $schema, Schema $root, array &$needed): void {
    if (is_array($schema)) {
      foreach ($schema as $member) {
        $this->referred($member, $root, $needed);
      }
      return;
    }
    if (!Schemas::isObjectSchema($schema)) {
      return;
    }
    $defs = $root->get('$defs');
    foreach ($schema->all() as $keyword => $value) {
      if ($keyword === '$ref' && is_string($value)) {
        $name = SchemaResolver::defName($value);
        if ($name !== NULL && !in_array($name, $needed, TRUE) && is_array($defs) && Schemas::isObjectSchema($defs[$name] ?? NULL)) {
          $needed[] = $name;
          $this->referred($defs[$name], $root, $needed);
        }
        continue;
      }
      // Only what holds a schema: a keyword that holds data says no
      // reference.
      if (in_array(Keywords::shape((string) $keyword), [Keywords::SCHEMA, Keywords::SCHEMA_MAP, Keywords::SCHEMA_LIST], TRUE)) {
        $this->referred($value, $root, $needed);
      }
    }
  }

  /**
   * Strips what only this module says from a schema left over.
   *
   * And what identifies a schema (IDENTIFIERS): what is left over is
   * served again under `portable-check`, below the document's root, where
   * the dialect allows no `$id` and no `$schema` (Portable Schema's
   * Registry refuses the document).
   *
   * @param mixed $schema
   *   A schema, a list or a map of them, or a value, left as it is.
   * @param string[]|null $dropped
   *   Collects the pointer of each identifier dropped.
   * @param string $pointer
   *   The schema's pointer, for those.
   *
   * @return mixed
   *   The same, with no `x-surface`, `portable-check`,
   *   `x-portable-messages`, `x-portable-ui`, `x-portable-links` or
   *   identifier at any depth.
   */
  protected static function plain(mixed $schema, ?array &$dropped = NULL, string $pointer = ''): mixed {
    if (is_array($schema)) {
      foreach ($schema as $name => $member) {
        $schema[$name] = static::plain($member, $dropped, static::below($pointer, $name));
      }
      return $schema;
    }
    if (!Schemas::isObjectSchema($schema)) {
      return $schema;
    }
    $keywords = $schema->all();
    unset($keywords[ContractEmitter::EXTENSION], $keywords[PortableKeywords::CHECK], $keywords[PortableKeywords::MESSAGES], $keywords[PortableKeywords::UI], $keywords[PortableKeywords::LINKS]);
    foreach (self::IDENTIFIERS as $identifier) {
      if (is_string($keywords[$identifier] ?? NULL)) {
        unset($keywords[$identifier]);
        $dropped[] = static::below($pointer, $identifier);
      }
    }
    foreach ($keywords as $keyword => $value) {
      if (in_array(Keywords::shape((string) $keyword), [Keywords::SCHEMA, Keywords::SCHEMA_MAP, Keywords::SCHEMA_LIST], TRUE)) {
        $keywords[$keyword] = static::plain($value, $dropped, static::below($pointer, (string) $keyword));
      }
    }
    return Schema::from($keywords);
  }

  /**
   * Strips a schema left over as plain() does, noting each identifier.
   *
   * @param mixed $schema
   *   A schema, a list or a map of them, or a value, left as it is.
   * @param string $pointer
   *   Its pointer.
   *
   * @return mixed
   *   The same, plain.
   */
  protected function kept(mixed $schema, string $pointer): mixed {
    $dropped = [];
    $schema = static::plain($schema, $dropped, $pointer);
    foreach ($dropped as $at) {
      $this->note($at, self::IDENTIFIER_DROPPED);
    }
    return $schema;
  }

  /**
   * Strips a schema left over as kept() does: a schema in, a schema out.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   * @param string $pointer
   *   Its pointer.
   *
   * @return \PortableSchema\Builder\Schema
   *   The same, plain.
   */
  protected function keptSchema(Schema $schema, string $pointer): Schema {
    $kept = $this->kept($schema, $pointer);
    assert($kept instanceof Schema);
    return $kept;
  }

  /**
   * Reads every frame of a document for its notes, declaring into nothing.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A frame.
   * @param string $pointer
   *   Its pointer.
   * @param \PortableSchema\Builder\Schema $root
   *   The document.
   * @param string[] $seen
   *   The pointers read on the way.
   */
  protected function survey(Schema $schema, string $pointer, Schema $root, array $seen): void {
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
   * @param array $document
   *   The document, as prepared(): $this->document, or one a key's
   *   reading changed (key()).
   * @param string $pointer
   *   Its pointer.
   *
   * @return string
   *   The name it is built by.
   */
  protected function structureAt(ResourceBlueprint $inputs, string $id, array $document, string $pointer): string {
    $this->frames[] = $pointer;
    if ($this->hashed === NULL || $this->hashed[0] !== $document) {
      $this->hashed = [$document, ResourceBlueprint::documentHash($document)];
    }
    return $inputs->readStructure($id, $document, $pointer, $this->hashed[1]);
  }

}
