<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\Core\Validation\ConstraintManager;
use Drupal\surface\Contract\Keywords\ReadBack;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Plugin\SurfaceWidget\StringWidget;
use Drupal\surface\Reference\References;
use Drupal\surface\Resource\Arrangement;
use Drupal\surface\ResourceBuild\Shapes;
use Drupal\surface\Widget\SurfaceWidgetBase;
use Drupal\surface\Widget\SurfaceWidgetManager;
use PortableSchema\Builder\Keywords;
use PortableSchema\Builder\Schema;
use PortableSchema\Registry;

/**
 * Reads a value key an author states as a Schema by its keywords.
 *
 * The path SchemaReader::authored() reads a value key by: a key of one
 * value, or a list of values, read from the author's Schema itself into
 * the key's schema, with no document written, prepared or read back and
 * no definition made; the builder holds the schema
 * (SurfaceBuilder::setSchema()). Each step here is the step SchemaReader
 * takes for a value of a document (value(), constraints(), choice(),
 * settings(), finished() and the helpers they call), writing the facts a
 * definition would hold, and ValueSchema writes the schema of those facts.
 * Three readings are the typed verbs' rather than the document reader's,
 * so a key converted from add() keeps its schema: a labeled list is a
 * LabeledChoice of its labels by value, the constraints are declared in
 * the order the author wrote them, and a check handed no options holds
 * none. A key is required where its schema is marked `required()`, and
 * only there. A `$ref` to `drupal:config/<type>` is that config schema
 * type, and SETTINGS says a key's settings no keyword says.
 *
 * What it does not read it says so by answering NULL, before it reads
 * anything, and the caller reads the key with SchemaReader: a key that
 * needs a frame or a structure (an object of properties, a collection,
 * a `$ref` into `$defs`, a shape, variants, `portable-typeBy`), a key
 * whose schema holds what preparing a document rewrites (a draft's
 * spelling, `x-surface`), and a key whose schema holds a keyword that
 * holds a schema and is handed something else.
 *
 * Decision: see docs/decisions.md#authoring-takes-a-schema.
 *
 * @internal
 */
final class KeywordReader {

  /**
   * The JSON Schema types.
   */
  protected const TYPES = ['string', 'integer', 'number', 'boolean', 'object', 'array', 'null'];

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
   */
  protected const IDENTIFIERS = ['$id', '$schema', '$anchor', '$dynamicAnchor'];

  /**
   * The note on an identifier dropped below the root.
   */
  protected const IDENTIFIER_DROPPED = 'Dropped: an identifier, which a Portable Schema document holds at its root alone.';

  /**
   * The keywords of the dialect the server's validator checks, kept.
   */
  protected const CHECKED_BY_VALIDATOR = ['portable-refine', 'portable-compare', 'portable-typeBy'];

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
    self::SETTINGS,
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
    self::SETTINGS,
  ];

  /**
   * The annotation keyword that says the key's settings no keyword says.
   *
   * Surface's own facts of a key that no keyword of the dialect states: a
   * setting by its name, as the key's definition holds it, such as
   * References::TARGETS, References::STORED_AS, a widget's own setting
   * (`format`, CommaSeparatedListWidget::SETTING). Read by this reader
   * alone, for an author's schema; never served, and no member of MODEL.
   *
   * Decision: see docs/decisions.md#authoring-takes-a-schema.
   */
  public const SETTINGS = 'x-surface-settings';

  /**
   * The settings a keyword of the dialect says, by name, with its spelling.
   *
   * Refused under SETTINGS: one fact, one spelling.
   */
  protected const SPELLED_SETTINGS = [
    'widget' => 'x-portable-ui `widget`',
    'multiline' => 'x-portable-ui `widget: textarea`',
    'size' => 'x-portable-ui `options.size`',
    'rows' => 'x-portable-ui `options.rows`',
  ];

  /**
   * What starts a `$ref` to a config schema type: `drupal:config/<type>`.
   *
   * Decision: see docs/decisions.md#authoring-takes-a-schema.
   */
  public const CONFIG_TYPE = PortableKeywords::PROVIDER . 'config/';

  /**
   * The keywords a draft spells that preparing a document rewrites.
   *
   * A schema that holds one at any depth is read by SchemaReader, which
   * converts a draft's spellings first.
   */
  protected const PREPARED = [
    'definitions',
    'dependencies',
    'nullable',
    'additionalItems',
    'id',
    '$defs',
    ContractEmitter::EXTENSION,
  ];

  /**
   * What was noted while reading, by JSON pointer.
   *
   * @var array<string, string>
   */
  protected array $notes = [];

  /**
   * The notes that say what was done, by pointer.
   *
   * @var array<string, true>
   */
  protected array $accepted = [];

  /**
   * The registry the reader reads without a container, the type library.
   */
  private static ?Registry $types = NULL;

  /**
   * Constructs a KeywordReader.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface|null $entityTypeManager
   *   The entity type manager, which says whether an entity type a
   *   reference's check names is one the site has; NULL to read it as
   *   named.
   * @param \PortableSchema\Registry|null $library
   *   The registry a `$ref` to a `portable:` id is read through; NULL for
   *   the site's, or the type library alone.
   * @param \Drupal\surface\Widget\SurfaceWidgetManager|null $widgets
   *   The widget manager, which says which settings the widget a word
   *   names publishes; NULL to note every option of a module's widget.
   * @param \Drupal\surface\ResourceBuild\Shapes|null $shapes
   *   What reads a config schema type (Shapes::configType()); NULL for a
   *   reader that reads none.
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface|null $typedDataManager
   *   The typed data manager a config schema type is read with.
   */
  public function __construct(
    protected readonly ?EntityTypeManagerInterface $entityTypeManager = NULL,
    protected readonly ?Registry $library = NULL,
    protected readonly ?SurfaceWidgetManager $widgets = NULL,
    protected readonly ?Shapes $shapes = NULL,
    protected readonly ?TypedDataManagerInterface $typedDataManager = NULL,
  ) {}

  /**
   * Makes the reader the build reads with: the services the site has.
   *
   * @param \Drupal\surface\ResourceBuild\Shapes|null $shapes
   *   What reads a config schema type; NULL for none.
   *
   * @return static
   *   The reader, as SchemaReader::authored() makes its own.
   */
  public static function forBuild(?Shapes $shapes = NULL): static {
    $widgets = \Drupal::hasService('plugin.manager.surface_widget') ? \Drupal::service('plugin.manager.surface_widget') : NULL;
    $typed = \Drupal::hasService('typed_data_manager') ? \Drupal::service('typed_data_manager') : NULL;
    return new static(
      \Drupal::hasService('entity_type.manager') ? \Drupal::entityTypeManager() : NULL,
      static::siteLibrary(),
      $widgets instanceof SurfaceWidgetManager ? $widgets : NULL,
      $shapes,
      $typed instanceof TypedDataManagerInterface ? $typed : NULL,
    );
  }

  /**
   * Reads one value key an author states as a Schema.
   *
   * What SchemaReader::authored() does for the key, but for the frame
   * document: the same refusals in the same words, the same notes, and
   * the key's schema as the builder would hold it once authored() has
   * declared the key (its default and its arrangement on it).
   *
   * @param string $key
   *   The key.
   * @param \PortableSchema\Builder\Schema $schema
   *   What it holds.
   * @param bool|null $required
   *   TRUE to require it, FALSE to leave it optional; NULL to require it
   *   where its schema is marked `required()`.
   * @param string|null $what
   *   Who was handed the schema, for the messages; NULL for
   *   `addSchema("<key>")`.
   *
   * @return \Drupal\surface\Contract\KeywordRead|null
   *   The key's schema and the notes; NULL for a key this reader does not
   *   read, which SchemaReader reads.
   *
   * @throws \InvalidArgumentException
   *   For a boolean schema, for a property named by digits alone, for a
   *   key holding a dot, and for every part of the schema the reader does
   *   not read as written and does not accept.
   */
  public function read(string $key, Schema $schema, ?bool $required = NULL, ?string $what = NULL): ?KeywordRead {
    $what ??= sprintf('addSchema("%s")', $key);
    if ($schema->isBoolean()) {
      throw new \InvalidArgumentException(sprintf('%s was handed the boolean schema %s, which says only whether a value is allowed: a key is the schema of what it holds, Schema::string(), Schema::integer(), Schema::of(\'portable:email\') and the like.', $what, $schema->toDocument() === TRUE ? 'true' : 'false'));
    }
    $pointer = static::below('', 'properties', $key);
    static::assertNamed($schema, $what, $pointer);
    // A key is required where its schema is marked so, and only there.
    // Decision: see docs/decisions.md#authoring-takes-a-schema.
    $required ??= $schema->isRequired();
    $reference = $schema->get('$ref');
    if (is_string($reference) && str_starts_with($reference, self::CONFIG_TYPE)) {
      return $this->configTypeKey($key, $schema->without('$ref'), substr($reference, strlen(self::CONFIG_TYPE)), $required, $what, $pointer);
    }
    if (!static::readsDirectly($schema)) {
      return NULL;
    }
    // The author's own values, every object an array but a text, as the
    // frame document authored() reads holds them.
    $own = static::withTexts($schema);
    assert(is_array($own));
    $property = Schema::from($own);
    if (!$this->readsAsValue($property)) {
      return NULL;
    }
    $this->notes = [];
    $this->accepted = [];
    if (str_contains($key, '.')) {
      throw new \InvalidArgumentException(sprintf('%s: "%s" cannot be a key, which holds no dot: a dot is how a path names a key inside another.', $pointer, $key));
    }
    [$facts, $item, $locked] = $this->key($property, $required, $pointer);
    $arrangement = $this->arranged($property, $pointer);
    if ($arrangement !== NULL) {
      $facts['surface_arrangement'] = $arrangement;
    }
    $this->refuseUnread($what);
    return new KeywordRead($item === NULL ? ValueSchema::value($facts) : ValueSchema::list($facts, $item), $this->notes, $locked);
  }

  /**
   * Answers whether a schema says nothing preparing a document rewrites.
   *
   * At any depth: no draft spelling (PREPARED, a boolean
   * `exclusiveMinimum` or `exclusiveMaximum`, an `items` list), no `$ref`
   * but to a type of the library, no keyword that holds a schema handed
   * something else, no keyword that is a number.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   *
   * @return bool
   *   TRUE where it does not.
   */
  protected static function readsDirectly(Schema $schema): bool {
    if ($schema->isBoolean()) {
      return TRUE;
    }
    foreach ($schema->all() as $keyword => $value) {
      $keyword = (string) $keyword;
      if (ctype_digit($keyword) || in_array($keyword, self::PREPARED, TRUE)) {
        return FALSE;
      }
      if (in_array($keyword, ['exclusiveMinimum', 'exclusiveMaximum'], TRUE) && is_bool($value)) {
        return FALSE;
      }
      if ($keyword === '$ref' && !static::isLibrary($value)) {
        return FALSE;
      }
      $members = match (Keywords::TABLE[$keyword] ?? NULL) {
        Keywords::SCHEMA => [$value],
        Keywords::SCHEMA_MAP, Keywords::SCHEMA_LIST => is_array($value) ? $value : NULL,
        default => [],
      };
      if ($members === NULL) {
        return FALSE;
      }
      foreach ($members as $member) {
        if (!$member instanceof Schema || !static::readsDirectly($member)) {
          return FALSE;
        }
      }
    }
    return TRUE;
  }

  /**
   * Answers whether a property is one value or a list of values, read here.
   *
   * @param \PortableSchema\Builder\Schema $property
   *   The key's schema, as the frame holds it.
   *
   * @return bool
   *   FALSE for a type of the library that is an object, an object of
   *   properties and a list of objects.
   */
  protected function readsAsValue(Schema $property): bool {
    $property = $this->throughLibrary($property);
    [$property] = $this->withNull($property, '');
    $id = $property->get('$ref');
    if (static::isLibrary($id)) {
      $flat = $this->libraryKeywords((string) $id);
      if ($flat !== NULL && static::isLibraryObject($flat)) {
        return FALSE;
      }
    }
    $types = (array) ($property->get('type') ?? []);
    $nested = $property->get('properties');
    if (in_array('object', $types, TRUE) && is_array($nested) && $nested !== []) {
      return FALSE;
    }
    $items = $property->get('items');
    if (in_array('array', $types, TRUE) && $items instanceof Schema && !$items->isBoolean()) {
      $items = $this->throughLibrary($items);
      if ($this->isObject($items)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Reads one property into the facts of its key, as SchemaReader::key().
   *
   * @param \PortableSchema\Builder\Schema $property
   *   Its schema.
   * @param bool $required
   *   Whether the frame requires it.
   * @param string $pointer
   *   Its pointer.
   *
   * @return array{0: array, 1: array|null, 2: bool}
   *   What its definition would hold, for a list its item's, and whether
   *   `readOnly` locks it: then it starts from its `const`, where it has
   *   one.
   */
  protected function key(Schema $property, bool $required, string $pointer): array {
    $property = $this->throughLibrary($property);
    [$property, $pointer] = $this->withNull($property, $pointer);
    $this->droppedLinks($property, $pointer);
    $this->libraryOf($property, $pointer);
    $types = $this->types($property, $pointer);
    [$facts, $item, $default, $property] = $this->value($property, $pointer, $types);
    $locked = $property->get('readOnly') === TRUE;
    if ($locked && $property->has('const')) {
      $default = $property->get('const');
    }
    $facts = $this->finished($facts, $item !== NULL, $property, $required, in_array('null', $types, TRUE));
    if ($default !== NULL) {
      $facts['default_value'] = $default;
    }
    return [$facts, $item, $locked];
  }

  /**
   * Reads a value: a scalar, a list of scalars, or anything else held whole.
   *
   * @param \PortableSchema\Builder\Schema $property
   *   The property.
   * @param string $pointer
   *   Its pointer.
   * @param string[] $types
   *   Its types, as types() read them.
   *
   * @return array{0: array, 1: array|null, 2: mixed, 3: \PortableSchema\Builder\Schema}
   *   The facts of its definition, its item's for a list (NULL for one
   *   value), the default it starts from (NULL for none), and the
   *   property as read, a type of the library's keywords laid in.
   */
  protected function value(Schema $property, string $pointer, array $types): array {
    $property = $this->throughLibrary($property);
    $leaf = NULL;
    $read = $property;
    $extra = [];
    $library = $this->libraryOf($property, $pointer);
    if ($library !== NULL && $library['leaf'] !== NULL) {
      $leaf = $library['leaf'];
      [$property, $extra] = $this->besideLeaf($property->without('$ref'), $library, $pointer);
      $types = $property->get('type') !== NULL ? $this->types($property, $pointer) : [LeafTypes::ROWS[$leaf]['type']];
      $read = Schema::from(array_replace($library['flat'], $property->all()));
    }
    elseif ($library !== NULL && !$library['object']) {
      $this->accept(static::below($pointer, '$ref'), sprintf('Read in place: %s, from the library, is the key\'s own.', $library['id']));
      $property = Schema::from(array_replace($library['flat'], $property->without('$ref')->all()));
      $types = $this->types($property, $pointer);
      $read = $property;
      $format = $property->get('format');
      if (is_string($format) && array_values(array_diff($types, ['null'])) !== ['string']) {
        $extra['format'] = $format;
        $property = $property->without('format');
      }
    }
    $not_null = array_values(array_diff($types, ['null']));
    $default = $read->get('default');
    if ($not_null === ['array'] && $property->get('prefixItems') === NULL) {
      $items = Schemas::isObjectSchema($property->get('items')) ? $this->throughLibrary($property->get('items')) : Schema::any();
      $item_types = $this->types($items, static::below($pointer, 'items'));
      $item_library = $this->libraryOf($items, static::below($pointer, 'items'));
      if (array_values(array_diff($item_types, ['null'])) === ['array'] || ($items->all() !== [] && $this->isObject($items) && ($item_library === NULL || $item_library['object']))) {
        $item = $this->foreignValue($items, static::below($pointer, 'items'));
      }
      else {
        [$item] = $this->value($items, static::below($pointer, 'items'), $item_types === [] ? [] : array_values(array_diff($item_types, ['null'])));
        if (static::text($items->get('title')) !== NULL) {
          $item['label'] = $items->get('title');
        }
      }
      $list = ['type' => 'list'];
      $foreign = [];
      foreach ($this->constraints($property->without('items'), $pointer, ['array'], $foreign) as [$constraint, $options]) {
        $list['constraints'][$constraint] = $options;
      }
      $list = static::holdTo($list, $this->withDefs($foreign));
      $list = $this->settings($list, Schemas::data($property->get(PortableKeywords::UI)) ?? [], $pointer);
      $list = $this->ownSettings($list, $property, $pointer);
      $list = static::examples($list, $property);
      return [$list, $item, $default, $read];
    }
    if (count($not_null) !== 1 || in_array($not_null[0], ['object', 'array', 'null'], TRUE)) {
      return [$this->foreignValue($property, $pointer), NULL, $default, $read];
    }
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
    elseif ($leaf === NULL && $format !== NULL && $type !== 'string') {
      $this->note(static::below($pointer, 'format'), sprintf('Not read: the "%s" format is read on a string alone, and this key is %s.', $format, $not_null[0]));
    }
    $facts = ['type' => $type];
    $foreign = [];
    foreach ($this->constraints($property, $pointer, $not_null, $foreign) as [$constraint, $options]) {
      $facts['constraints'][$constraint] = $options;
    }
    if ($type === 'string' && $format !== NULL && isset(self::FORMAT_PATTERNS[$format]) && $property->get('pattern') === NULL) {
      if (isset($facts['constraints']['Regex'])) {
        $foreign['pattern'] = PatternTranslation::ecma(self::FORMAT_PATTERNS[$format]);
        $this->note(static::below($pointer, 'format'), sprintf('Kept as a JsonSchema constraint, as the pattern of a "%s": a definition holds one Regex, and the pattern it must not match is that one.', $format));
      }
      else {
        $facts['constraints']['Regex'] = ['pattern' => self::FORMAT_PATTERNS[$format]];
      }
    }
    if ($type === 'string' && $format === 'date' && $property->get('oneOf') === NULL && $property->get('enum') === NULL) {
      $facts['settings']['widget'] = 'date';
    }
    if ($extra !== []) {
      $facts = static::holdTo($facts, static::fragment(Schema::from($extra)));
    }
    $facts = static::holdTo($facts, $this->withDefs($foreign));
    $facts = $this->settings($facts, Schemas::data($property->get(PortableKeywords::UI)) ?? [], $pointer);
    $facts = $this->ownSettings($facts, $property, $pointer);
    $facts = static::examples($facts, $property);
    return [$facts, NULL, $default, $read];
  }

  /**
   * Writes what a key's schema says of the key itself, as finished().
   *
   * @param array $facts
   *   The facts so far.
   * @param bool $list
   *   Whether they are a list's.
   * @param \PortableSchema\Builder\Schema $property
   *   The key's schema, as read.
   * @param bool $required
   *   Whether the frame requires it.
   * @param bool $nullable
   *   Whether its type allows null.
   *
   * @return array
   *   The facts.
   *
   * @throws \InvalidArgumentException
   *   For a list marked secret, as DefinitionMetadata::setSecret() refuses
   *   it.
   */
  protected function finished(array $facts, bool $list, Schema $property, bool $required, bool $nullable): array {
    if (static::text($property->get('title')) !== NULL) {
      $facts['label'] = $property->get('title');
    }
    if (static::text($property->get('description')) !== NULL) {
      $facts['description'] = $property->get('description');
    }
    if ($required) {
      $facts['required'] = TRUE;
    }
    if (Secrets::marked($property)) {
      if ($list) {
        throw new \InvalidArgumentException('A secret holds one scalar value, so the list definition "list" cannot be marked secret. Declare the secret as a key of its own.');
      }
      $facts['secret'] = TRUE;
    }
    return $facts;
  }

  /**
   * Reads what a site says beside a `$ref` to a leaf type of the library.
   *
   * @param \PortableSchema\Builder\Schema $site
   *   What the site says, its `$ref` left out.
   * @param array{id: string, leaf: string|null, kept: array<string, mixed>, flat: array<string, mixed>, object: bool} $library
   *   What libraryOf() read of the type.
   * @param string $pointer
   *   Where the site stands.
   *
   * @return array{0: \PortableSchema\Builder\Schema, 1: array<string, mixed>}
   *   What the site says that is read, and what is kept beside the type.
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
    $unstated = array_diff_key(array_intersect_key($extra, ConstraintKeywords::keywords()), $site->all());
    foreach (ConstraintKeywords::read($unstated, [$row['type']]) as $back) {
      if ($back->constraint === NULL || $back->kept !== [] || $back->consumed === []) {
        continue;
      }
      foreach ($back->consumed as $keyword) {
        $site = $site->with($keyword, $extra[$keyword]);
        unset($extra[$keyword]);
      }
    }
    return [$site, $extra];
  }

  /**
   * Reads a value no data type of its own says: held whole to its schema.
   *
   * @param \PortableSchema\Builder\Schema $property
   *   The property.
   * @param string $pointer
   *   Its pointer.
   *
   * @return array
   *   The facts of an `any` definition, held to the property's schema.
   */
  protected function foreignValue(Schema $property, string $pointer): array {
    $facts = ['type' => LeafTypes::ANY, 'surface_held_whole' => TRUE];
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
      if ($keyword === 'oneOf' && $value === [] && !$property->has('enum')) {
        // The empty list, Schema::options([]): kept as the dialect says
        // it, `enum: []`, since a `oneOf` holds one schema at least.
        $keyword = 'enum';
      }
      $keywords[$keyword] = $value;
    }
    $kept = $this->keptSchema(Schema::from($keywords), $pointer);
    if ($kept->all() !== []) {
      $facts['constraints']['JsonSchema'] = ['schema' => $this->withDefs($kept->all())];
      $this->accept($pointer, sprintf('Kept as a JsonSchema constraint: %s, which no data type says.', implode(', ', array_keys($kept->all()))));
    }
    $facts = $this->ownSettings($facts, $property, $pointer);
    return static::examples($facts, $property);
  }

  /**
   * Reads a value's examples.
   *
   * @param array $facts
   *   The facts.
   * @param \PortableSchema\Builder\Schema $property
   *   Its schema.
   *
   * @return array
   *   The facts.
   */
  protected static function examples(array $facts, Schema $property): array {
    $examples = $property->get('examples');
    if (is_array($examples) && $examples !== []) {
      $facts['examples'] = array_values($examples);
    }
    return $facts;
  }

  /**
   * Reads the widget a key's `x-portable-ui` asks for, and its settings.
   *
   * @param array $facts
   *   The facts.
   * @param array $ui
   *   The key's `x-portable-ui`.
   * @param string $pointer
   *   The key's pointer.
   *
   * @return array
   *   The facts.
   */
  protected function settings(array $facts, array $ui, string $pointer): array {
    $widget = $ui['widget'] ?? NULL;
    if (in_array($widget, ['radios', 'time', 'date', 'checkboxes'], TRUE)) {
      $facts['settings']['widget'] = $widget;
    }
    elseif ($widget === 'textarea') {
      $facts['settings']['multiline'] = TRUE;
    }
    $options = Schemas::data($ui['options'] ?? NULL) ?? [];
    foreach (StringWidget::SETTINGS as $setting) {
      if (is_int($options[$setting] ?? NULL) && $options[$setting] > 0) {
        $facts['settings'][$setting] = $options[$setting];
      }
    }
    $published = [];
    $plugin = NULL;
    if (is_string($widget) && $this->widgets !== NULL) {
      $plugin_id = $this->widgets->words()[$widget] ?? NULL;
      $plugin = $plugin_id === NULL ? NULL : $this->widgets->widget($plugin_id);
      $own = ['radios', 'time', 'date', 'checkboxes', 'textarea'];
      if ($plugin_id === NULL && !in_array($widget, $own, TRUE)) {
        $this->note(static::below($pointer, PortableKeywords::UI, 'widget'), sprintf('Not read: no widget of this site is named "%s", so the key is drawn with the one its type is.', $widget));
      }
    }
    foreach ($plugin instanceof SurfaceWidgetBase ? $plugin->publishedSettings() : [] as $setting) {
      $published[PortableUi::optionName($setting)] = $setting;
    }
    foreach (array_diff_key($options, array_flip(PortableUi::OPTIONS)) as $option => $value) {
      if (isset($published[$option])) {
        $facts['settings'][$published[$option]] = $value;
        continue;
      }
      $this->note(static::below($pointer, PortableKeywords::UI, 'options', (string) $option), 'Not read: no widget the word names publishes a setting of that name.');
    }
    return $facts;
  }

  /**
   * Reads the settings an author says under SETTINGS.
   *
   * Each by its name, as the key's definition holds it. A setting a
   * keyword of the dialect says (SPELLED_SETTINGS) and the one the build
   * writes itself (References::CONTENT) are noted, and so refused.
   *
   * @param array $facts
   *   The facts.
   * @param \PortableSchema\Builder\Schema $property
   *   The key's schema.
   * @param string $pointer
   *   The key's pointer.
   *
   * @return array
   *   The facts.
   */
  protected function ownSettings(array $facts, Schema $property, string $pointer): array {
    if (!$property->has(self::SETTINGS)) {
      return $facts;
    }
    $at = static::below($pointer, self::SETTINGS);
    $settings = Schemas::data($property->get(self::SETTINGS));
    if ($settings === NULL || ($settings !== [] && array_is_list($settings))) {
      $this->note($at, 'Not read: the settings are a map of each setting\'s value by its name.');
      return $facts;
    }
    foreach ($settings as $name => $value) {
      $name = (string) $name;
      if (isset(self::SPELLED_SETTINGS[$name]) && !($name === 'multiline' && $value === FALSE)) {
        $this->note(static::below($at, $name), sprintf('Not read: %s says this setting.', self::SPELLED_SETTINGS[$name]));
        continue;
      }
      if ($name === References::CONTENT) {
        $this->note(static::below($at, $name), 'Not read: the build writes this setting on a key that refers to a content entity.');
        continue;
      }
      $facts['settings'][$name] = $value;
    }
    return $facts;
  }

  /**
   * Reads a key whose `$ref` names a config schema type.
   *
   * `drupal:config/<type>`: the key is the value the type is, with the
   * type's constraints, as add() declares a key of the type
   * (Shapes::configType()), and what the site says beside the `$ref` lays
   * on it as a site laid on a shape does: its title, description, default,
   * hints and settings, and its keywords, which narrow the type's bounds
   * and add their constraints. The type's own schema is read from the
   * site's config schema once, where the type is read.
   *
   * @param string $key
   *   The key.
   * @param \PortableSchema\Builder\Schema $site
   *   What the schema says beside its `$ref`.
   * @param string $type
   *   The config schema type.
   * @param bool $required
   *   Whether the key is required.
   * @param string $what
   *   Who was handed the schema, for the messages.
   * @param string $pointer
   *   The key's pointer.
   *
   * @return \Drupal\surface\Contract\KeywordRead|null
   *   The key's schema and the notes; NULL where what the site says is not
   *   one this reader reads.
   *
   * @throws \InvalidArgumentException
   *   For a type the site has no config schema type of one value of, and
   *   for every part of the site not read as written.
   */
  protected function configTypeKey(string $key, Schema $site, string $type, bool $required, string $what, string $pointer): ?KeywordRead {
    if (!static::readsDirectly($site)) {
      return NULL;
    }
    $definition = $this->shapes !== NULL && $this->typedDataManager !== NULL ? $this->shapes->configType($type, $this->typedDataManager) : NULL;
    $declared = $definition === NULL ? NULL : DefinitionMetadata::configTypeDefinition($definition);
    if ($definition === NULL || $declared === NULL) {
      throw new \InvalidArgumentException(sprintf('%s was handed a schema that refers to %s%s at %s, and this site has no config schema type "%s" that is one value: a key may be of a config schema type whose data type is one value, as machine_name is.', $what, self::CONFIG_TYPE, $type, static::below($pointer, '$ref'), $type));
    }
    $this->notes = [];
    $this->accepted = [];
    if (str_contains($key, '.')) {
      throw new \InvalidArgumentException(sprintf('%s: "%s" cannot be a key, which holds no dot: a dot is how a path names a key inside another.', $pointer, $key));
    }
    $own = static::withTexts($site);
    assert(is_array($own));
    $property = Schema::from($own);
    // The type as the site's config schema says it, read at the edge.
    $facts = $definition->toArray();
    $facts['surface_config_type'] = ['type' => $type, 'schema' => DefinitionSchema::of($declared)];
    $json = LeafTypes::ROWS[$definition->getDataType()]['type'] ?? 'string';
    $types = $property->get('type') !== NULL ? $this->types($property, $pointer) : [$json];
    $foreign = [];
    $narrowing = Schema::from($property->all() + ['type' => $json]);
    $not_null = array_values(array_diff($types, ['null']));
    foreach ($this->constraints($narrowing, $pointer, $not_null, $foreign) as [$constraint, $options]) {
      if ($constraint === 'JsonSchema') {
        $facts = static::holdTo($facts, $options['schema']);
        continue;
      }
      $held = $facts['constraints'][$constraint] ?? [];
      $facts['constraints'][$constraint] = in_array($constraint, ['Range', 'Length', 'Count'], TRUE) && is_array($held) ? array_replace($held, $options) : $options;
    }
    $facts = static::holdTo($facts, $this->withDefs($foreign));
    $facts = $this->settings($facts, Schemas::data($property->get(PortableKeywords::UI)) ?? [], $pointer);
    $facts = $this->ownSettings($facts, $property, $pointer);
    $facts = static::examples($facts, $property);
    $facts = $this->finished($facts, FALSE, $property, $required, in_array('null', $types, TRUE));
    $default = $property->get('default');
    if ($default !== NULL) {
      $facts['default_value'] = $default;
    }
    $arrangement = $this->arranged($property, $pointer);
    if ($arrangement !== NULL) {
      $facts['surface_arrangement'] = $arrangement;
    }
    $this->refuseUnread($what);
    return new KeywordRead(ValueSchema::value($facts), $this->notes);
  }

  /**
   * Reads the constraints a value's keywords say, as SchemaReader does.
   *
   * @param \PortableSchema\Builder\Schema $property
   *   The value's schema.
   * @param string $pointer
   *   Its pointer.
   * @param string[] $types
   *   Its types, null left out.
   * @param array|null $foreign
   *   Collects what no constraint says, by keyword.
   *
   * @param-out array $foreign
   *
   * @return array<int, array{0: string, 1: array|null}>
   *   Each constraint and its options, in the order they are declared.
   */
  protected function constraints(Schema $property, string $pointer, array $types, ?array &$foreign = []): array {
    $foreign = [];
    $table = [];
    foreach (array_intersect_key($property->all(), ConstraintKeywords::keywords()) as $keyword => $value) {
      $table[$keyword] = match (TRUE) {
        $keyword === PortableKeywords::MESSAGES => static::withTexts($value),
        is_array($value) || $value instanceof Schema || $value instanceof \stdClass => static::decodedCopy($value),
        default => $value,
      };
    }
    // No keyword of the table: no row reads anything.
    $read = $table === [] ? [] : ConstraintKeywords::read($table, $types);
    $taken = [];
    $messaged = [];
    $checked = [];
    foreach ($read as $back) {
      foreach ($back->consumed as $keyword) {
        $taken[$keyword] = TRUE;
      }
      foreach (array_keys($back->kept) as $keyword) {
        $taken[$keyword] = TRUE;
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
            if ($check['id'] === PortableKeywords::PROVIDER . $check['constraint'] && $check['options'] === [] && $check['message'] === NULL && ConstraintKeywords::isFormatTwin($check['constraint'], $property->get('format'))) {
              continue;
            }
            if ($check['constraint'] === 'JsonSchema' && is_array($check['options']['schema'] ?? NULL)) {
              $held_at = static::below($at, PortableKeywords::PROVIDER . 'JsonSchema', 'schema');
              $read[] = new ReadBack('JsonSchema', ['schema' => static::fragment($this->keptSchema(static::checked($check['options']['schema'], $held_at), $held_at))], [$keyword]);
            }
            elseif ($check['constraint'] === References::CONSTRAINT && ($refers = $this->referenceOptions($property, $why)) !== NULL) {
              $read[] = new ReadBack(References::CONSTRAINT, $refers, [$keyword]);
            }
            elseif ($check['constraint'] === References::CONSTRAINT && $why !== NULL) {
              $this->note(static::below($at, PortableKeywords::PROVIDER . $check['constraint']), 'Not read: ' . $why . ', so the constraint the server checks is known by its plugin alone.');
            }
            else {
              $why = $this->unconstrained($check);
              if ($why === NULL) {
                $options = $this->withMessage($check);
                $back = new ReadBack($check['constraint'], $options, [$keyword]);
                $checked[] = [$back, static::below($at, $check['id'])];
                if ($check['message'] !== NULL && ($options['message'] ?? NULL) === $check['message']) {
                  $messaged[PortableKeywords::CHECK_CODE . $check['id']] = TRUE;
                }
              }
              else {
                $foreign[PortableKeywords::CHECK][$check['id']] = $check['options'] === [] ? new \stdClass() : static::copiedTexts($check['options']);
                $this->accept(static::below($at, $check['id']), 'Kept as a JsonSchema constraint: ' . $why . ', so the server answers it as a check it cannot run.');
              }
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
    foreach ($checked as [$back, $at]) {
      $read = $this->besideKeywords($read, $back, $property, $pointer, $at, $foreign);
    }
    foreach (array_keys(Schemas::data($property->get(PortableKeywords::MESSAGES)) ?? []) as $code) {
      $code = (string) $code;
      if (!isset($messaged[$code]) && !in_array($code, ['pattern', 'not'], TRUE)) {
        $this->note(static::below($pointer, PortableKeywords::MESSAGES, $code), 'Not read: a message is read for a check read as its constraint, and for a pattern; this code names neither.');
      }
    }
    $constraints = [];
    // In the order the author wrote them, as the verbs declare constraints.
    foreach (static::asWritten($read, $property->all()) as $back) {
      if ($back->constraint !== NULL) {
        // A check handed no options is a constraint added with none.
        $none = $back->options === [] && $back->consumed === [PortableKeywords::CHECK];
        $constraints[] = [$back->constraint, $none ? NULL : $back->options];
      }
    }
    return $constraints;
  }

  /**
   * Orders what was read by where the author wrote it.
   *
   * Each constraint by the place of the first keyword it took or kept;
   * checks, all under one keyword, in the order written.
   *
   * @param \Drupal\surface\Contract\Keywords\ReadBack[] $read
   *   What was read.
   * @param array $schema
   *   The schema it was read from.
   *
   * @return \Drupal\surface\Contract\Keywords\ReadBack[]
   *   The same, in the author's order.
   */
  protected static function asWritten(array $read, array $schema): array {
    $at = array_flip(array_map('strval', array_keys($schema)));
    $place = static function (ReadBack $back) use ($at): int {
      $keywords = [...$back->consumed, ...array_map('strval', array_keys($back->kept))];
      $positions = array_map(static fn (string $keyword): int => $at[$keyword] ?? PHP_INT_MAX, $keywords);
      return $positions === [] ? PHP_INT_MAX : min($positions);
    };
    usort($read, static fn (ReadBack $a, ReadBack $b): int => $place($a) <=> $place($b));
    return $read;
  }

  /**
   * Lays an author's check, read as its constraint, beside the keywords'.
   *
   * @param \Drupal\surface\Contract\Keywords\ReadBack[] $read
   *   What the keywords read.
   * @param \Drupal\surface\Contract\Keywords\ReadBack $back
   *   The check, read as its constraint.
   * @param \PortableSchema\Builder\Schema $property
   *   The value's schema.
   * @param string $pointer
   *   Its pointer.
   * @param string $at
   *   The check's pointer.
   * @param array $foreign
   *   Collects the keywords kept for the JsonSchema constraint.
   *
   * @return \Drupal\surface\Contract\Keywords\ReadBack[]
   *   What is read, the check among it.
   */
  protected function besideKeywords(array $read, ReadBack $back, Schema $property, string $pointer, string $at, array &$foreign): array {
    foreach ($read as $i => $held) {
      if ($held->constraint !== $back->constraint) {
        continue;
      }
      if (in_array($back->constraint, ['Range', 'Length', 'Count'], TRUE)) {
        $options = $held->options;
        $said = [];
        foreach ($back->options as $option => $value) {
          $mine = $options[$option] ?? NULL;
          if (in_array($option, ['min', 'max'], TRUE) && is_numeric($mine) && is_numeric($value) && $mine != $value) {
            $value = $option === 'min' ? max($mine, $value) : min($mine, $value);
            $said[] = sprintf('%s %s is kept, the stricter of the keyword\'s %s and the check\'s %s', $option, json_encode($value), json_encode($mine), json_encode($back->options[$option]));
          }
          $options[$option] = $value;
        }
        $read[$i] = new ReadBack($held->constraint, $options, [...$held->consumed, ...$back->consumed], $held->kept, $held->note);
        $this->accept($at, sprintf('Merged with the %s the keywords say%s.', $held->constraint, $said === [] ? '' : ': ' . implode('; ', $said)));
        return $read;
      }
      foreach ($held->consumed as $keyword) {
        $foreign[$keyword] = $this->kept($property->get($keyword), static::below($pointer, $keyword));
      }
      $read[$i] = $back;
      $this->accept($at, sprintf('Read as the %s constraint; %s, which says one too, is kept in the key\'s JsonSchema constraint, so both hold.', $held->constraint, implode(', ', $held->consumed)));
      return $read;
    }
    $read[] = $back;
    return $read;
  }

  /**
   * Says why an author's check is not read as its constraint, if it is.
   *
   * @param array{id: string, constraint: string, options: array, message: string|\Stringable|null} $check
   *   The check, as PortableKeywords::checks() read it.
   *
   * @return string|null
   *   Why not, or NULL where it is read.
   */
  protected function unconstrained(array $check): ?string {
    if (!str_starts_with($check['id'], PortableKeywords::PROVIDER)) {
      return sprintf('%s is no check of this site, whose checks are named %s<plugin id>', $check['id'], PortableKeywords::PROVIDER);
    }
    $manager = static::constraintManager();
    if ($manager === NULL || !$manager->hasDefinition($check['constraint'])) {
      return sprintf('the site has no constraint %s', $check['constraint']);
    }
    try {
      $manager->create($check['constraint'], static::copiedTexts($this->withMessage($check)));
    }
    catch (\Throwable $refused) {
      return sprintf('the constraint %s refuses these options (%s)', $check['constraint'], rtrim($refused->getMessage(), '.'));
    }
    return NULL;
  }

  /**
   * Gets the site's constraint plugins, which an author's check names.
   *
   * @return \Drupal\Core\Validation\ConstraintManager|null
   *   The manager, or NULL with no container.
   */
  protected static function constraintManager(): ?ConstraintManager {
    $manager = \Drupal::hasService('validation.constraint') ? \Drupal::service('validation.constraint') : NULL;
    return $manager instanceof ConstraintManager ? $manager : NULL;
  }

  /**
   * Gives an author's check's options its message, where it has one.
   *
   * @param array{id: string, constraint: string, options: array, message: string|\Stringable|null} $check
   *   The check, as PortableKeywords::checks() read it.
   *
   * @return array
   *   Its options.
   */
  protected function withMessage(array $check): array {
    $options = $check['options'];
    $manager = static::constraintManager();
    $class = $manager !== NULL && $manager->hasDefinition($check['constraint']) ? ($manager->getDefinition($check['constraint'])['class'] ?? NULL) : NULL;
    if ($check['message'] !== NULL && !isset($options['message']) && is_string($class) && property_exists($class, 'message')) {
      $options['message'] = $check['message'];
    }
    return $options;
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
      if ($all === []) {
        // The empty list, Schema::options([]): no value is allowed.
        return ['Choice', ['choices' => []]];
      }
      if (!is_array($all)) {
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
        $labels[] = static::text($choice->get('title')) ?? (string) $choice->get('const');
        $descriptions[] = static::text($choice->get('description'));
      }
    }
    elseif ($keyword === 'enum') {
      $enum = $property->get('enum');
      if ($enum === []) {
        // The empty list, Schema::enum([]): no value is allowed.
        return ['Choice', ['choices' => []]];
      }
      if (!is_array($enum) || array_filter($enum, static fn (mixed $value): bool => !is_scalar($value) && $value !== NULL) !== []) {
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
      return ['Choice', ['choices' => [$property->get('const')]]];
    }
    if ($values === []) {
      return [];
    }
    $options = [
      'choices' => $values,
      'labels' => array_combine(array_map('strval', $values), array_map(static fn (string|\Stringable|null $label, mixed $value): string|\Stringable => $label ?? (string) $value, $labels, $values)),
    ];
    $described = array_filter(array_combine(array_map('strval', $values), $descriptions), static fn (string|\Stringable|null $description): bool => $description !== NULL);
    if ($described === [] && array_filter($values, static fn (mixed $value): bool => !is_string($value) && !is_int($value)) === []) {
      // As add() declares a labeled list: the labels by value.
      return ['LabeledChoice', ['choices' => array_combine($values, $options['labels'])]];
    }
    if ($described !== []) {
      $options['descriptions'] = $described;
    }
    return ['LabeledChoice', $options];
  }

  /**
   * Reads what a key's reference check says it refers to.
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
   * Notes the links a key carries, which are not read.
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
        $this->note($at, 'Not read: not a link.');
        continue;
      }
      if ($link['rel'] === PortableLinks::LOOKUP && isset($link['source']) && $this->referenceOptions($property) === NULL) {
        $this->accept($at, 'Not read: a link names where a client asks, and an id whose entity type its sibling names is read as a value of its type alone.');
        continue;
      }
      $this->accept($at, 'Not read: a link names where a client asks.');
    }
  }

  /**
   * Reads how a key is arranged where it is drawn, from its hints.
   *
   * As SchemaReader::arranged() reads it for a key of a frame that
   * declares no group: a key's group is then declared nowhere.
   *
   * @param \PortableSchema\Builder\Schema $property
   *   The key's schema.
   * @param string $pointer
   *   The key's pointer.
   *
   * @return \Drupal\surface\Resource\Arrangement|null
   *   The arrangement, as describe() lays it on a key that has none; NULL
   *   where the hints say none.
   */
  protected function arranged(Schema $property, string $pointer): ?Arrangement {
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
    if (is_string($ui['group'] ?? NULL)) {
      $this->note(static::below($at, 'group'), 'Not read: the group is declared nowhere in the frame, or cannot be named so.');
    }
    if ($weight === NULL && $texts === []) {
      return NULL;
    }
    return new Arrangement(NULL, $weight, $texts['placeholder'] ?? NULL, $texts['prefix'] ?? NULL, $texts['suffix'] ?? NULL, $texts['help'] ?? NULL);
  }

  /**
   * Reads the weight of a key, noting one that is no whole number.
   *
   * @param array $hints
   *   The key's `x-portable-ui`.
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
    $weight = match (TRUE) {
      is_int($hints['weight']) => $hints['weight'],
      is_float($hints['weight']) && floor($hints['weight']) === $hints['weight'] && abs($hints['weight']) < PHP_INT_MAX => (int) $hints['weight'],
      default => NULL,
    };
    if ($weight === NULL) {
      $this->note(static::below($pointer, 'weight'), 'Not read: a weight is a whole number.');
    }
    return $weight;
  }

  /**
   * Reads the JSON types a property allows.
   *
   * @param mixed $property
   *   The property.
   * @param string $pointer
   *   Its pointer.
   *
   * @return string[]
   *   The types, `null` among them where it is allowed.
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
   * Reads an author's `anyOf` or `oneOf` of one schema and null as it.
   *
   * @param \PortableSchema\Builder\Schema $property
   *   The key's schema.
   * @param string $pointer
   *   Its pointer.
   *
   * @return array{0: \PortableSchema\Builder\Schema, 1: string}
   *   The schema as read, and the pointer of the member it was read from.
   */
  protected function withNull(Schema $property, string $pointer): array {
    if ($property->has('type') || $property->has('$ref') || $property->has(ContractEmitter::EXTENSION)) {
      return [$property, $pointer];
    }
    $null = static fn (mixed $member): bool => Schemas::isObjectSchema($member) && (static::says($member, ['type' => 'null']) || static::says($member, ['type' => ['null']]) || (array_keys($member->all()) === ['const'] && $member->get('const') === NULL));
    foreach (['anyOf', 'oneOf'] as $keyword) {
      $members = $property->get($keyword);
      if (!is_array($members) || count($members) !== 2 || $property->has($keyword === 'anyOf' ? 'oneOf' : 'anyOf')) {
        continue;
      }
      foreach ([[0, 1], [1, 0]] as [$one, $other]) {
        $member = $members[$one] ?? NULL;
        if (!Schemas::isObjectSchema($member) || !$null($members[$other] ?? NULL) || $null($member)) {
          continue;
        }
        $outer = $property->without($keyword)->all();
        if (array_intersect_key($outer, $member->all()) !== []) {
          return [$property, $pointer];
        }
        $at = static::below($pointer, $keyword, (string) $one);
        $types = $this->types($member, $at);
        $read = Schema::from($outer + $member->all());
        if ($types !== [] && !in_array('null', $types, TRUE)) {
          $read = $read->with('type', [...$types, 'null']);
        }
        return [$read, $at];
      }
    }
    return [$property, $pointer];
  }

  /**
   * Answers whether a schema says exactly what a plain value says.
   *
   * @param mixed $schema
   *   A schema, or a value.
   * @param mixed $plain
   *   What it is compared with.
   *
   * @return bool
   *   TRUE where it says that and nothing else.
   */
  protected static function says(mixed $schema, mixed $plain): bool {
    if (!is_array($plain)) {
      return $schema === $plain;
    }
    $members = match (TRUE) {
      Schemas::isObjectSchema($schema) => $schema->all(),
      is_array($schema) => $schema,
      default => NULL,
    };
    if ($members === NULL || array_keys($members) !== array_keys($plain)) {
      return FALSE;
    }
    foreach ($plain as $name => $member) {
      if (!static::says($members[$name], $member)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Reads a schema that stands for a type of the library as a `$ref` to it.
   *
   * An `anyOf` of a `$ref` to a type of the library that is no object and
   * `{"type": "null"}`, in either order: that `$ref`, its type allowing
   * null. A `$ref` into `$defs` is SchemaReader's.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema: a key's, or a list's items.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema as a `$ref` to the library; the same value where it is
   *   none.
   */
  protected function throughLibrary(Schema $schema): Schema {
    $reference = $schema->get('$ref');
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
   * Reads which id of the library a schema refers to, where it does.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema: a key's, or a list's items.
   * @param string $pointer
   *   Where it stands.
   *
   * @return array{id: string, leaf: string|null, kept: array<string, mixed>, flat: array<string, mixed>, object: bool}|null
   *   As SchemaReader::libraryOf() answers.
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
    $registry = $this->library ?? static::siteLibrary();
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
   * Gets the registry a reader the build makes reads through.
   *
   * @return \PortableSchema\Registry
   *   The site's (surface.portable_registry), or, with no container, the
   *   type library.
   */
  protected static function siteLibrary(): Registry {
    if (\Drupal::hasService('surface.portable_registry')) {
      $library = \Drupal::service('surface.portable_registry');
      assert($library instanceof Registry);
      return $library;
    }
    return self::$types ??= Registry::withTypes();
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
   * Answers whether a type of the library is an object.
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
    $flat = $schema->get('properties') === NULL && static::isLibrary($schema->get('$ref')) ? $this->libraryKeywords((string) $schema->get('$ref')) : NULL;
    return $flat === NULL || static::isLibraryObject($flat);
  }

  /**
   * Notes a keyword of the dialect that is not read, as dropped.
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
   *   The note for the keywords CHECKED_BY_VALIDATOR names; NULL else.
   */
  protected static function keptNote(string $keyword): ?string {
    return in_array($keyword, self::CHECKED_BY_VALIDATOR, TRUE)
      ? sprintf('Kept as a JsonSchema constraint: %s is checked by the server\'s validator, not read into the structure.', $keyword)
      : NULL;
  }

  /**
   * Holds a value to a schema, beside what it is held to already.
   *
   * @param array $facts
   *   The facts.
   * @param array $schema
   *   The schema, in the array form; empty for none.
   *
   * @return array
   *   The facts, with their JsonSchema constraint.
   */
  protected static function holdTo(array $facts, array $schema): array {
    if ($schema === []) {
      return $facts;
    }
    $held = $facts['constraints']['JsonSchema']['schema'] ?? NULL;
    if (is_array($held) && $held !== [] && !Schemas::same($held, $schema)) {
      $defs = (is_array($held['$defs'] ?? NULL) ? $held['$defs'] : []) + (is_array($schema['$defs'] ?? NULL) ? $schema['$defs'] : []);
      unset($held['$defs'], $schema['$defs']);
      $schema = ['allOf' => [$held, $schema]] + ($defs === [] ? [] : ['$defs' => $defs]);
    }
    $facts['constraints']['JsonSchema'] = ['schema' => $schema];
    return $facts;
  }

  /**
   * Writes a schema left over in the array form, every library type inlined.
   *
   * What SchemaReader::withDefs() writes of a schema that refers to no
   * def: a key read here refers to none.
   *
   * @param array<string, mixed> $schema
   *   The keywords left over, each with its value.
   *
   * @return array
   *   The schema, in the array form; empty for none.
   */
  protected function withDefs(array $schema): array {
    if ($schema === []) {
      return [];
    }
    return static::fragment($this->libraryInlined(Schema::from($schema)));
  }

  /**
   * Replaces each `$ref` to a type of the library with what the type says.
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
   * Reads the schema a JsonSchema check holds, refusing what is no schema.
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
   * Refuses a subschema that is no schema, at any depth.
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
    $members_of = [
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
    foreach ($schema as $keyword => $value) {
      if (is_int($keyword)) {
        throw new \InvalidArgumentException(sprintf('%s: "%d" is no keyword: a schema\'s keywords are names.', $pointer === '' ? '/' : $pointer, $keyword));
      }
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
          throw new \InvalidArgumentException(sprintf('%s: %s is a schema, an object or a boolean.', static::below($at, $name), $members_of[$keyword] ?? 'an entry of ' . $keyword));
        }
      }
    }
  }

  /**
   * Strips what only this module says from a schema left over.
   *
   * @param mixed $schema
   *   A schema, a list or a map of them, or a value, left as it is.
   * @param string[]|null $dropped
   *   Collects the pointer of each identifier dropped.
   * @param string $pointer
   *   The schema's pointer, for those.
   *
   * @return mixed
   *   The same, with none of the module's keywords or an identifier at
   *   any depth.
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
   *   A schema, a list or a map of them, or a value.
   * @param string $pointer
   *   Its pointer.
   *
   * @return mixed
   *   The same, plain, its texts copies.
   */
  protected function kept(mixed $schema, string $pointer): mixed {
    $dropped = [];
    $schema = static::plain(static::copied($schema), $dropped, $pointer);
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
   * Writes an author's Schema as JSON decodes it, each text kept.
   *
   * @param mixed $value
   *   A Schema, or a value one holds.
   *
   * @return mixed
   *   The same, every object an array but a text.
   */
  public static function withTexts(mixed $value): mixed {
    if ($value instanceof Schema) {
      if ($value->isBoolean()) {
        return Schemas::decoded($value);
      }
      $value = $value->all();
    }
    elseif ($value instanceof \stdClass) {
      $value = (array) $value;
    }
    if (!is_array($value)) {
      return $value;
    }
    foreach ($value as $name => $member) {
      if ($member instanceof Schema || $member instanceof \stdClass || is_array($member)) {
        $value[$name] = static::withTexts($member);
      }
    }
    return $value;
  }

  /**
   * Copies each text in a value, leaving the rest.
   *
   * @param mixed $value
   *   A text, or an array that holds texts at any depth.
   *
   * @return mixed
   *   The same, each \Stringable a copy.
   *
   * @internal
   *   Public for the closure bound to a text, which copies its arguments.
   */
  public static function copiedTexts(mixed $value): mixed {
    if ($value instanceof \Stringable) {
      $copy = clone $value;
      if ($copy instanceof FormattableMarkup) {
        \Closure::bind(function (): void {
          $this->arguments = KeywordReader::copiedTexts($this->arguments);
        }, $copy, FormattableMarkup::class)();
      }
      if ($copy instanceof TranslatableMarkup) {
        (new \ReflectionProperty(TranslatableMarkup::class, 'translatedMarkup'))->setValue($copy, NULL);
      }
      return $copy;
    }
    if (!is_array($value)) {
      return $value;
    }
    foreach ($value as $name => $member) {
      if ($member instanceof \Stringable || is_array($member)) {
        $value[$name] = static::copiedTexts($member);
      }
    }
    return $value;
  }

  /**
   * Copies each text in a schema value, leaving the rest.
   *
   * @param mixed $value
   *   A Schema, a list or a map of them, or a value.
   *
   * @return mixed
   *   The same, each \Stringable a copy.
   */
  protected static function copied(mixed $value): mixed {
    if ($value instanceof Schema) {
      return $value->isBoolean() ? $value : Schema::from(static::copiedTexts(static::withTexts($value)));
    }
    if ($value instanceof \Stringable || is_array($value)) {
      return static::copiedTexts(is_array($value) ? array_map(static::copied(...), $value) : $value);
    }
    return $value;
  }

  /**
   * Decodes what a keyword holds from a copy, as Schemas::decoded() does.
   *
   * @param \PortableSchema\Builder\Schema|\stdClass|array $value
   *   The value.
   *
   * @return array|bool
   *   It decoded; no text the author holds was rendered.
   */
  protected static function decodedCopy(Schema|\stdClass|array $value): array|bool {
    $copy = static::copiedTexts(static::withTexts($value));
    assert(is_array($copy) || is_bool($copy));
    return Schemas::decoded($copy);
  }

  /**
   * Refuses a schema whose `properties` name a property by digits alone.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema, and every schema it holds.
   * @param string $what
   *   Who was handed it, for the message.
   * @param string $pointer
   *   Its pointer.
   *
   * @throws \InvalidArgumentException
   *   Naming the property and its pointer.
   */
  protected static function assertNamed(Schema $schema, string $what, string $pointer): void {
    if ($schema->isBoolean()) {
      return;
    }
    foreach ($schema->all() as $keyword => $value) {
      $keyword = (string) $keyword;
      $at = static::below($pointer, $keyword);
      if ($keyword === 'properties' && is_array($value)) {
        foreach (array_keys($value) as $name) {
          if (ctype_digit((string) $name)) {
            throw new \InvalidArgumentException(sprintf('%s was handed a schema naming the property "%s" at %s: a name of digits alone is written as a position, so the object would be served as a list. Name it with a letter in it.', $what, $name, $at));
          }
        }
      }
      if ($value instanceof Schema) {
        static::assertNamed($value, $what, $at);
      }
      elseif (is_array($value)) {
        foreach ($value as $name => $member) {
          if ($member instanceof Schema) {
            static::assertNamed($member, $what, static::below($at, (string) $name));
          }
        }
      }
    }
  }

  /**
   * Reads a text a schema holds: a string, or one an author made.
   *
   * @param mixed $value
   *   The value a keyword holds.
   *
   * @return string|\Stringable|null
   *   The text, or NULL for a value that is none.
   */
  protected static function text(mixed $value): string|\Stringable|null {
    return is_string($value) || $value instanceof \Stringable ? $value : NULL;
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
   * Notes what was done with a part of an author's schema, accepting it.
   *
   * @param string $pointer
   *   The part's pointer.
   * @param string $note
   *   What was done.
   */
  protected function accept(string $pointer, string $note): void {
    $this->note($pointer, $note);
    $this->accepted[$pointer === '' ? '/' : $pointer] = TRUE;
  }

  /**
   * Refuses what an author's schema holds that was not read as written.
   *
   * @param string $what
   *   Who was handed the schema, for the message.
   *
   * @throws \InvalidArgumentException
   *   Listing each part noted and not accepted, by pointer.
   */
  protected function refuseUnread(string $what): void {
    $refused = array_diff_key($this->notes, $this->accepted);
    if ($refused === []) {
      return;
    }
    ksort($refused);
    $parts = [];
    foreach ($refused as $at => $note) {
      $parts[] = $at . ': ' . $note;
    }
    throw new \InvalidArgumentException(sprintf('%s reads the schema as the author\'s own, and these parts of it are not read as written (by JSON pointer into the object the key is laid in, the key at /properties/<key>): %s Fix each, or leave it out.', $what, implode(' ', $parts)));
  }

}
