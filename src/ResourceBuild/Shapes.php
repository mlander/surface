<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\surface\ConstraintMessages;
use Drupal\surface\Contract\KeywordReader;
use Drupal\surface\Contract\DefinitionFromSchema;
use Drupal\surface\Contract\SchemaReader;
use Drupal\surface\Surface;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Resource\AltersInputsInterface;
use PortableSchema\Builder\Attribute\Document;
use Drupal\surface\Resource\ShapeInterface;
use Drupal\surface\SurfaceBuilder;
use PortableSchema\Builder\Schema;

/**
 * Turns a shape into the definition a key declared with it starts from.
 *
 * The shape's schema(), held to what a shape may say and read as an
 * author's schema is (SchemaReader::authored(), the one reading of a
 * Schema), then each alter of the shape in discovery order, each only
 * tightening, a definition in and a definition out; the result is
 * copied for the key and marked with the shape, so the key's own
 * additions are checked against it when its owner is built and the
 * served contract writes the shape once, as a def.
 *
 * A shape is read once per class in a process (declared()): its reading
 * depends on its class and on the types of the library, both code, and
 * on nothing a request or a language changes, since the reader leaves
 * every text lazy. The definition read is kept and never handed out:
 * each use gets a copy, its texts copied with it, so nothing one key
 * writes or renders reaches the kept one or another key's. An alter's
 * result is not kept: an alter may read site state.
 *
 * Decision: see docs/decisions.md#a-shape-is-a-named-definition.
 *
 * @internal
 */
final class Shapes {

  /**
   * The key a shape's schema is read at, in a blueprint of its own.
   */
  protected const KEY = 'shape';

  /**
   * The data types that hold more than one scalar value.
   */
  protected const GROUPED = ['map', 'list'];

  /**
   * The keywords that make a schema read as no one scalar type.
   */
  protected const COMPOSING = ['allOf', 'anyOf', 'oneOf', '$ref', 'not', 'if'];

  /**
   * The keywords that say a value holds more than one, with no type named.
   *
   * Named where no keyword of COMPOSING is: an object's members, a list's
   * positions, or a `const` or `enum` whose values are no scalar.
   */
  protected const UNTYPED = [
    'additionalProperties',
    'patternProperties',
    'propertyNames',
    'prefixItems',
    'contains',
    'const',
    'enum',
  ];

  /**
   * Each shape's definition as declared() read it, by class and id.
   *
   * Kept unmarked and before any alter, and never handed out: a use gets
   * a copy (copied()).
   *
   * @var array<class-string, array<string, \Drupal\Core\TypedData\DataDefinition>>
   */
  protected static array $read = [];

  /**
   * Constructs the shape resolver.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found: each shape's id and alters.
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface $classResolver
   *   What returns each alter as the autowired service discovery
   *   registered.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface|null $typedConfig
   *   The config schema, whose data types a key may be declared with;
   *   NULL for none.
   */
  public function __construct(
    protected readonly ResourceRegistry $registry,
    protected readonly ClassResolverInterface $classResolver,
    protected readonly ?TypedConfigManagerInterface $typedConfig = NULL,
  ) {
  }

  /**
   * Answers whether a type handed to add() or reads() names a shape.
   *
   * @param string $type
   *   A typed data type, or a class.
   *
   * @return bool
   *   TRUE for a class implementing ShapeInterface.
   */
  public static function isShape(string $type): bool {
    return str_contains($type, '\\') && is_subclass_of($type, ShapeInterface::class);
  }

  /**
   * Gets the definition a key declared with a shape starts from.
   *
   * @param string $shape
   *   The shape's class.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   A copy of the shape as its alters left it, marked with the shape.
   *
   * @throws \LogicException
   *   When the shape says what no shape may, or an alter widens it or
   *   does what no alter of a shape may.
   */
  public function forKey(string $shape): DataDefinition {
    $found = $this->registry->getShape($shape);
    $definition = static::declared($found->class, $found->id);
    foreach ($found->alters as $alter) {
      $instance = $this->classResolver->getInstanceFromDefinition($alter->class);
      assert($instance instanceof AltersInputsInterface);
      $blueprint = new ShapeBlueprint($found->id, $alter->class);
      $instance->alterInputs($blueprint);
      $definition = $blueprint->applyTo($definition);
    }
    return static::marked($found->id, $definition);
  }

  /**
   * Gets the definition a key starts from, with no alter applied.
   *
   * For a blueprint built by hand, with no discovery behind it.
   *
   * @param string $shape
   *   The shape's class.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   A copy of the shape as declared, marked with the shape.
   */
  public static function unaltered(string $shape): DataDefinition {
    $id = static::idOf($shape);
    return static::marked($id, static::declared($shape, $id));
  }

  /**
   * Gets the definition a key of a config schema data type starts from.
   *
   * A type core's config schema names and typed data does not, such as
   * `machine_name`: followed through what it extends to the typed data
   * type underneath, a string, which the key is, with every constraint
   * the config schema type states (a `machine_name`'s Regex and Length)
   * as the key's own, so both documents state them and a reader of either
   * reads them back. A Regex anchored with `$` is read with `D`, which
   * narrows it by a final line break only and lets both engines read it.
   * Only a type that is one value; a mapping or a sequence is a
   * structure's to declare.
   *
   * Decision: see docs/decisions.md#a-machine-name-is-a-computed-identity.
   *
   * @param string $type
   *   The type handed to add().
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The typed data manager, which knows the types underneath.
   *
   * @return \Drupal\Core\TypedData\DataDefinition|null
   *   The key's definition, marked with the type; NULL when typed data
   *   knows the type, or the config schema names no such value.
   */
  public function configType(string $type, TypedDataManagerInterface $typedDataManager): ?DataDefinition {
    if ($typedDataManager->hasDefinition($type) || preg_match('/^[a-z][a-z0-9_]*$/', $type) !== 1) {
      return NULL;
    }
    $typedConfig = $this->typedConfig;
    if ($typedConfig === NULL) {
      return NULL;
    }
    $raw = $typedConfig->getDefinitions();
    $base = $type;
    $seen = [];
    while (!$typedDataManager->hasDefinition($base)) {
      $next = $raw[$base]['type'] ?? NULL;
      if (!is_string($next) || isset($seen[$base]) || preg_match('/^[a-z][a-z0-9_]*$/', $next) !== 1) {
        return NULL;
      }
      $seen[$base] = TRUE;
      $base = $next;
    }
    $definition = $typedDataManager->createDataDefinition($base);
    if (!$definition instanceof DataDefinition || $definition instanceof ComplexDataDefinitionInterface || $definition instanceof ListDataDefinitionInterface) {
      return NULL;
    }
    $constraints = $typedConfig->getDefinition($type)['constraints'] ?? [];
    foreach (is_array($constraints) ? $constraints : [] as $name => $options) {
      if ($name === 'Regex' && is_array($options) && is_string($options['pattern'] ?? NULL) && preg_match('~^/.*\$/([a-zA-Z]*)$~s', $options['pattern'], $match) === 1 && !str_contains($match[1], 'D')) {
        // Anchored at the very end, as both engines read `$`: PCRE's own
        // `$` lets a final line break through, which no machine name
        // holds, and which keeps the pattern out of both documents.
        // Decision: see docs/decisions.md#a-pattern-both-engines-read-alike.
        $options['pattern'] .= 'D';
      }
      $definition->addConstraint((string) $name, $options);
    }
    $declared = Surface::deepClone($definition);
    DefinitionMetadata::setConfigType($definition, $type, $declared);
    return $definition;
  }

  /**
   * Reads a shape's id from its attribute.
   *
   * @param string $shape
   *   The shape's class.
   *
   * @return string
   *   The id.
   *
   * @throws \LogicException
   *   When the class carries no #[Document].
   */
  public static function idOf(string $shape): string {
    $id = ((new \ReflectionClass($shape))->getAttributes(Document::class)[0] ?? NULL)?->newInstance()->id;
    if ($id === NULL) {
      throw new \LogicException(sprintf('%s implements %s but carries no #[Document]: a shape is named by its attribute, #[Document(\'<id>\')].', $shape, ShapeInterface::class));
    }
    return $id;
  }

  /**
   * Gets the typed data type a shape's value is, for a port or a placeholder.
   *
   * The kept definition's, once declared() has read the shape; else read
   * as declared() reads it, and kept. A shape that says what no shape may
   * is read without its checks: it is refused where a key is declared
   * with it.
   *
   * @param string $shape
   *   The shape's class.
   *
   * @return string
   *   The data type, such as `string` or `integer`.
   */
  public static function dataType(string $shape): string {
    assert(is_subclass_of($shape, ShapeInterface::class));
    $kept = self::$read[$shape] ?? [];
    if ($kept !== []) {
      return reset($kept)->getDataType();
    }
    try {
      return static::declared($shape, static::idOf($shape))->getDataType();
    }
    catch (\LogicException | \InvalidArgumentException) {
      // Refused where a key is declared with it, and read here as it is.
      return static::read($shape, $shape::schema())->getDataType();
    }
  }

  /**
   * Reads a shape's schema(), and refuses what no shape may say.
   *
   * The rules, each checked here, where a shape is first read, and not at
   * discovery:
   * - A shape is one scalar value: a schema of type object or array, or
   *   one holding properties or items, is refused before it is read, and
   *   so is one that reads as a map or a list.
   * - A shape states no default: a schema with `default`, even
   *   `default: null`, is refused. The default is the key's.
   * - A shape is never required: a schema marked required() is refused
   *   before it is read, since the reader applies its own rule to the
   *   marker. Whether a value must be given is the key's.
   * - A shape is not locked: `readOnly` is refused, since a key is
   *   locked where it is declared. At 20ef965 a define() that called
   *   setReadOnly(TRUE) changed nothing a key served, accepted or
   *   checked. With `const` beside it, `readOnly` reads as a key locked at
   *   the value it starts from, a default, and is refused as one.
   * - The schema is read as an author's schema at a key that is not
   *   required (SchemaReader::authored()), so what the reader does not
   *   read as written is refused with the reader's own words, and so is
   *   every note it makes of what it reads otherwise than as written (a
   *   link dropped, a check merged or kept): a shape is authored, and an
   *   author's note is an error. A schema with no title gives a
   *   definition with no label.
   * - What is read is one definition and nothing else: the blueprint it
   *   is read in holds the one key afterwards, as a blueprint that was
   *   handed only the definition does, or the shape is refused, naming
   *   what else it holds (a lock, a port, a group, a rule).
   * - A schema that reads as no one scalar type (through `allOf`,
   *   `anyOf`, `oneOf` or a `$ref`, or with no type and the members or
   *   positions of more than one value) is refused, naming the keyword. What
   *   the reader or the blueprint refuses while the shape is read is
   *   said as the shape's, naming its schema(), with the reason kept.
   *
   * Read once per class and id in a process, and kept; the definition
   * answered is a copy of the kept one.
   *
   * @param string $shape
   *   The shape's class.
   * @param string $id
   *   Its id, for the messages.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The definition it declares, a copy of its own.
   *
   * @throws \LogicException
   *   For an object or an array, which is a structure, a default, a lock
   *   or a required marker, which are the key's, for what is noted or
   *   left beside the definition, and for what the reader or the
   *   blueprint refuses without naming the shape.
   * @throws \InvalidArgumentException
   *   For a part of the schema the reader does not read as written.
   */
  public static function declared(string $shape, string $id): DataDefinition {
    assert(is_subclass_of($shape, ShapeInterface::class));
    return static::copied(self::$read[$shape][$id] ??= static::firstRead($shape, $id));
  }

  /**
   * Reads a shape's schema() the first time, as declared() says.
   *
   * @param class-string<\Drupal\surface\Resource\ShapeInterface> $shape
   *   The shape's class.
   * @param string $id
   *   Its id.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The definition, to be kept.
   */
  protected static function firstRead(string $shape, string $id): DataDefinition {
    $schema = $shape::schema();
    $types = $schema->isBoolean() ? [] : array_values(array_diff((array) ($schema->get('type') ?? []), ['null']));
    $grouped = match (TRUE) {
      in_array('object', $types, TRUE), !$schema->isBoolean() && $schema->has('properties') => 'map',
      in_array('array', $types, TRUE), !$schema->isBoolean() && $schema->has('items') => 'list',
      default => NULL,
    };
    static::refuse($shape, $id, match (TRUE) {
      $grouped !== NULL => static::grouped($grouped),
      !$schema->isBoolean() && $schema->has('default') => static::defaulted($shape),
      $schema->isRequired() => static::requiring(),
      default => NULL,
    });
    $definition = static::read($shape, $schema, $id, $held);
    $locked = !$schema->isBoolean() && $schema->get('readOnly') === TRUE;
    static::refuse($shape, $id, match (TRUE) {
      $definition instanceof ComplexDataDefinitionInterface,
      $definition instanceof ListDataDefinitionInterface,
      in_array($definition->getDataType(), self::GROUPED, TRUE) => static::grouped($definition->getDataType()),
      $definition->getDataType() === 'any' => static::composed($schema),
      // `readOnly` beside `const` reads as a key locked at the value it
      // starts from: a default.
      $held['default'], $locked && $schema->has('const') => static::defaulted($shape),
      $definition->isRequired() => static::requiring(),
      $locked => static::locking(),
      $held['notes'] !== [] => static::noted($shape, $held['notes']),
      $held['besides'] !== [] => static::besides($shape, $held['besides']),
      default => NULL,
    });
    return $definition;
  }

  /**
   * Copies a kept definition for one use, its texts copied with it.
   *
   * @param \Drupal\Core\TypedData\DataDefinition $kept
   *   The definition declared() keeps.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   A copy sharing no text with it.
   */
  protected static function copied(DataDefinition $kept): DataDefinition {
    $copy = clone $kept;
    \Closure::bind(function (): void {
      $this->definition = SchemaReader::copiedTexts($this->definition);
    }, $copy, DataDefinition::class)();
    return $copy;
  }

  /**
   * Reads a shape's schema into a definition, at a key of its own.
   *
   * @param string $shape
   *   The shape's class.
   * @param \PortableSchema\Builder\Schema $schema
   *   What its schema() returned.
   * @param string|null $id
   *   Its id, for the messages; NULL to read it from its attribute.
   * @param array{notes: array<string, string>, default: bool, besides: string[]}|null $held
   *   Collects what the reading left besides the definition: what the
   *   reader noted, by pointer, whether the key was given a default, and
   *   what else the blueprint holds that a blueprint handed only the
   *   definition does not, by name.
   *
   * @param-out array{notes: array<string, string>, default: bool, besides: string[]} $held
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The definition, the reader's own: nothing else holds it.
   *
   * @throws \LogicException
   *   For what the reader or the blueprint refuses without naming the
   *   shape's schema(), said again as the shape's.
   */
  protected static function read(string $shape, Schema $schema, ?string $id = NULL, ?array &$held = NULL): DataDefinition {
    $what = $shape . '::schema()';
    // A shape of one value is read by its keywords into its schema: what
    // the reading leaves besides it is a lock alone.
    // Decision: see docs/decisions.md#authoring-takes-a-schema.
    try {
      $read = KeywordReader::forBuild()->read(self::KEY, $schema, FALSE, $what);
    }
    catch (\InvalidArgumentException | \LogicException $refused) {
      if (str_contains($refused->getMessage(), $what)) {
        throw $refused;
      }
      throw new \LogicException(sprintf('The shape %s (%s) cannot be read from %s: %s', $id ?? static::idOf($shape), $shape, $what, $refused->getMessage()), 0, $refused);
    }
    if ($read !== NULL) {
      $definition = DefinitionFromSchema::of($read->schema);
      assert($definition instanceof DataDefinition);
      $held = [
        'notes' => $read->notes,
        'default' => DefinitionMetadata::hasDefaultValue($definition),
        'besides' => $read->locked ? ['locked'] : [],
      ];
      return static::written($definition, $schema);
    }
    $typedDataManager = \Drupal::service('typed_data_manager');
    $scratch = new ResourceBlueprint($builder = new SurfaceBuilder(), $typedDataManager);
    try {
      $notes = SchemaReader::authored($scratch, self::KEY, $schema, FALSE, $what);
    }
    catch (\InvalidArgumentException | \LogicException $refused) {
      if (str_contains($refused->getMessage(), $what)) {
        throw $refused;
      }
      throw new \LogicException(sprintf('The shape %s (%s) cannot be read from %s: %s', $id ?? static::idOf($shape), $shape, $what, $refused->getMessage()), 0, $refused);
    }
    $definition = $scratch->definitionOf(self::KEY);
    assert($definition instanceof DataDefinition);
    // What the reading left: compared with a blueprint handed only the
    // definition, so nothing the reader writes anywhere else is lost.
    $reference = new ResourceBlueprint($handed = new SurfaceBuilder(), $typedDataManager);
    $reference->addDefinition(self::KEY, $definition);
    $held = [
      'notes' => $notes,
      'default' => DefinitionMetadata::hasDefaultValue($definition),
      'besides' => array_values(array_unique([
        ...static::besidesIn($scratch, $reference),
        ...static::besidesIn($builder, $handed),
      ])),
    ];
    return static::written($definition, $schema);
  }

  /**
   * Writes a shape's definition in the order one is written by hand.
   *
   * @param \Drupal\Core\TypedData\DataDefinition $definition
   *   The definition read.
   * @param \PortableSchema\Builder\Schema $schema
   *   What its schema() returned.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   Its type, label and description first; a shape with no title has no
   *   label. A definition of another class as it is.
   */
  protected static function written(DataDefinition $definition, Schema $schema): DataDefinition {
    if ($definition::class !== DataDefinition::class) {
      return $definition;
    }
    $said = $definition->toArray();
    if ($schema->isBoolean() || !$schema->has('title')) {
      unset($said['label']);
    }
    return new DataDefinition(array_merge(array_intersect_key(array_fill_keys(['type', 'label', 'description'], NULL), $said), $said));
  }

  /**
   * Refuses a shape for what it says, if it says what no shape may.
   *
   * @param string $shape
   *   The shape's class.
   * @param string $id
   *   Its id.
   * @param string|null $problem
   *   What it declares that no shape may; NULL for nothing.
   *
   * @throws \LogicException
   *   Naming the shape and what it declares.
   */
  protected static function refuse(string $shape, string $id, ?string $problem): void {
    if ($problem !== NULL) {
      throw new \LogicException(sprintf('The shape %s (%s) declares %s.', $id, $shape, $problem));
    }
  }

  /**
   * Lists what one blueprint or builder holds that another does not.
   *
   * Every property of the object, at every class it extends, compared
   * with the same property of the other; a builder a blueprint fills is
   * compared on its own.
   *
   * @param object $read
   *   What the shape was read into.
   * @param object $handed
   *   The same kind of object, handed only the definition.
   *
   * @return string[]
   *   The names of the properties that differ.
   */
  protected static function besidesIn(object $read, object $handed): array {
    $differ = [];
    for ($class = new \ReflectionClass($read); $class !== FALSE; $class = $class->getParentClass()) {
      foreach ($class->getProperties() as $property) {
        if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $class->getName() || !$property->isInitialized($read) || !$property->isInitialized($handed)) {
          continue;
        }
        $value = $property->getValue($read);
        if ($value instanceof SurfaceBuilder || $value == $property->getValue($handed)) {
          continue;
        }
        $differ[] = $property->getName();
      }
    }
    return $differ;
  }

  /**
   * Says where a note stands in a shape's own schema.
   *
   * @param string $pointer
   *   The pointer in the object the key was read in.
   *
   * @return string
   *   The pointer in the shape's schema(), or "its root".
   */
  protected static function within(string $pointer): string {
    $own = '/properties/' . self::KEY;
    $inside = str_starts_with($pointer, $own) ? substr($pointer, strlen($own)) : $pointer;
    return $inside === '' ? 'its root' : $inside;
  }

  /**
   * Says why a shape that reads as no one scalar type is refused.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   What its schema() returned.
   *
   * @return string
   *   The reason, naming the keyword that made it so.
   */
  protected static function composed(Schema $schema): string {
    $said = $schema->isBoolean() ? [] : array_keys($schema->all());
    $keywords = array_values(array_intersect(self::COMPOSING, $said)) ?: array_values(array_intersect(self::UNTYPED, $said));
    return sprintf('a schema that does not read as one scalar type%s: a shape is one scalar value, such as Schema::string() or Schema::integer()', $keywords === [] ? '' : ' (through ' . implode(', ', array_map(static fn (string $keyword): string => '`' . $keyword . '`', $keywords)) . ')');
  }

  /**
   * Says why a locked shape is refused.
   *
   * @return string
   *   The reason.
   */
  protected static function locking(): string {
    return 'readOnly: a key is locked where it is declared, so whether its value may change belongs to the key that uses the shape';
  }

  /**
   * Says why a shape whose reading leaves more than a definition is refused.
   *
   * @param string $shape
   *   The shape's class.
   * @param string[] $besides
   *   What the reading left besides the definition.
   *
   * @return string
   *   The reason.
   */
  protected static function besides(string $shape, array $besides): string {
    return sprintf('more than one value: reading %s::schema() leaves %s beside the definition, and a shape is the definition of one value', $shape, implode(', ', $besides));
  }

  /**
   * Says why a shape whose reading was noted is refused.
   *
   * A shape is authored, and an author's note is an error.
   *
   * Decision: see
   * docs/decisions.md#a-shape-is-a-schema-secrecy-is-read-from-a-schema.
   *
   * @param string $shape
   *   The shape's class.
   * @param array<string, string> $notes
   *   What the reader noted, by pointer into the object the key was read
   *   in.
   *
   * @return string
   *   The reason, naming each note where it stands in the shape's schema.
   */
  protected static function noted(string $shape, array $notes): string {
    $said = [];
    foreach ($notes as $pointer => $note) {
      $said[] = static::within((string) $pointer) . ': ' . rtrim($note, '.');
    }
    return sprintf('what %s::schema() does not read as written (%s)', $shape, implode('; ', $said));
  }

  /**
   * Says why a shape that is a group of values is refused.
   *
   * @param string $type
   *   The data type it reads as.
   *
   * @return string
   *   The reason.
   */
  protected static function grouped(string $type): string {
    return sprintf('a %s: a shape is one scalar value, and a group of keys is a structure', $type);
  }

  /**
   * Says why a shape with a default is refused.
   *
   * @param string $shape
   *   The shape's class.
   *
   * @return string
   *   The reason.
   */
  protected static function defaulted(string $shape): string {
    return 'a default: a default belongs to the key, add(\'<key>\', ' . static::shortName($shape) . '::class, ..., default: ...)';
  }

  /**
   * Says why a required shape is refused.
   *
   * @return string
   *   The reason.
   */
  protected static function requiring(): string {
    return 'it required: whether a value must be given belongs to the key, which calls setRequired(TRUE)';
  }

  /**
   * Copies a shape's definition for a key, and marks the copy with it.
   *
   * @param string $id
   *   The shape's id.
   * @param \Drupal\Core\TypedData\DataDefinition $shape
   *   The shape's definition.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The key's definition.
   */
  protected static function marked(string $id, DataDefinition $shape): DataDefinition {
    // Both copies carry a message written with t() as its source, the
    // string core's validator translates.
    ConstraintMessages::sources($shape);
    $key = Surface::deepClone($shape);
    assert($key instanceof DataDefinition);
    DefinitionMetadata::setShape($key, $id, Surface::deepClone($shape));
    return $key;
  }

  /**
   * Gets a class's name without its namespace.
   *
   * @param string $class
   *   The class.
   *
   * @return string
   *   Its short name.
   */
  protected static function shortName(string $class): string {
    $position = strrpos($class, '\\');
    return $position === FALSE ? $class : substr($class, $position + 1);
  }

}
