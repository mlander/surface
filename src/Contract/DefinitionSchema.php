<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\Validation\ConstraintManager;
use Drupal\surface\Computed;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Options\SurfaceOptions;
use Drupal\surface\Plugin\SurfaceWidget\DateWidget;
use Drupal\surface\Plugin\Validation\Constraint\JsonSchemaConstraint;
use Drupal\surface\Refinement\DoesNotApply;
use Drupal\surface\SurfaceInterface;
use PortableSchema\Builder\Schema;

/**
 * Reads a definition into its built node, and projects a node to a document.
 *
 * The class has two halves, split by what each needs.
 *
 * Reading (of(), head(), describe(), keywords() and every static method
 * they call) is a pure function of the definition:
 * - It uses no service, makes no `\Drupal::` call of its own, runs no
 *   options resolver, reads no plugin manager and renders no text. So a
 *   node can be read where a data surface is built, with no request.
 * - The calls it makes on a definition are getDataType(), getLabel(),
 *   getDescription(), isRequired(), getConstraints(), getSetting(),
 *   getPropertyDefinitions() on a complex definition, getItemDefinition()
 *   on a list, and, through DefinitionMetadata, toArray(), offsetExists()
 *   and offsetGet() where the definition offers them, and isSecret(),
 *   hasDefaultValue(), getDefaultValue() and getExamples() where it offers
 *   them with no required argument. What those do inside core is core's:
 *   a DataDefinition's getConstraints() asks the typed data manager for
 *   its type's default constraints, an entity's getPropertyDefinitions()
 *   asks the entity field manager, and a list's getItemDefinition() asks
 *   the typed data manager when it builds the item.
 * - of() is the one read of a definition into a `Schema`: the built node,
 *   the definition's own statement at every depth. It holds the title and
 *   description, the type and the keywords its constraints state, every
 *   property of a map and the item of a list (each the built node of that
 *   definition, read by the same function), every declared check under
 *   `portable-check` with the options the definition holds, whether or not
 *   a list of values states it, the messages the definition gives
 *   (`x-portable-messages`), `writeOnly` for a secret, which is how a
 *   reader of the node knows it is one (Secrets::marked()), and MODEL.
 * - A property a refinement marked as not applying (DoesNotApply) is on
 *   the node: the mark is a fact of the definition and is kept there, on
 *   the property's definition, where the projection reads it.
 * - Every data type is read: a definition of a data type the leaf table
 *   has no row for (LeafTypes) gets a node with no `type` keyword, and
 *   its data type in MODEL; a definition with no place for a default or
 *   examples (DefinitionMetadata::canHoldDefaultValue(), canHoldExamples())
 *   has none. The read's one refusal: a definition met again inside
 *   itself (a map that holds itself) is refused with a LogicException
 *   naming its data type and where it is met again, before the read runs
 *   out of memory.
 * - Text stays as the definition holds it: a title, a description and a
 *   message that are a TranslatableMarkup stay one, so a node is the same
 *   in every language until it is projected. A title or description is
 *   kept when the definition holds one that is not NULL and not the
 *   literal ''. A message the reader writes itself (a check's own sentence
 *   given as a string, a Regex's, what a JsonSchema check holds a value
 *   to) is a NodeMessage built with no translation service. A check the
 *   definition gives no message holds none.
 *
 * Projecting (published(), publishedNode(), publishedKeywords(), entry())
 * is the one place a built node becomes a document node, and holds what
 * needs a service or the request:
 * - Whether a key offers a list of values (listed(), an option set, of its
 *   item for a list), and what follows from it: the check its list states
 *   and that check's message leave `portable-check`, and a map that offers
 *   one is said as one value, made from its node (oneValue(): its
 *   properties, `type`, `required` and `additionalProperties` are no
 *   keywords of it). Of a listed map's definition the projection reads
 *   only whether it is a map and whether it offers a list.
 * - Whether a list states a check (SurfaceOptions::isStatedByList(),
 *   SurfaceOptions::readsAsList()), kept on the service by plugin id and
 *   options; a check's label is kept by plugin id as its plugin holds it.
 *   Nothing rendered is kept.
 * - A check's label as its sentence where the definition states no
 *   message, or one that renders empty.
 * - A property that does not apply is left out, and the map's `required`
 *   follows.
 * - A value of a data type no row is for is refused, with LeafTypes'
 *   message, where its document node is made (publishedNode()), one node
 *   at a time, by MODEL's `type`. A reader that takes a node and does not
 *   project it (BuiltNodes::place()) gets a node with no `type` keyword
 *   and the data type in MODEL, and no refusal.
 * - Each check's options go through CheckOptions::published(); the title,
 *   the description and each message are rendered as plain text
 *   (PlainText::of()); a title, description or message that renders empty
 *   is left out; MODEL is stripped. published() also strips MODEL at
 *   every other keyword position (`allOf`, `$defs`, `patternProperties`
 *   and the rest), at every depth.
 * A node is projected before anything compares it (Schemas::same()), so
 * every comparison of the walk sees what the document says, and two sites
 * whose checks differ only in their options still share one def. The
 * projection takes the definition beside the node and walks the two
 * together, because the options resolvers and the mark of a key that does
 * not apply are read from the definition; that parameter goes when the
 * options resolvers take a node.
 *
 * A built node is held in memory only: it never reaches the build cache, a
 * served document or ServedContract, a log or an exception message.
 *
 * The memo. The node of a definition a sealed data surface holds is built
 * once per definition object. The code knows a definition is held by one
 * because it was handed one: seal() marks every definition of a data
 * surface (a data surface is sealed by construction), hold() marks the
 * definition it is handed as one a data surface holds (BuiltNodes hands it
 * what Location finds), and a node built for a marked definition marks
 * its properties and items. Any other definition (a copy a conditional
 * narrows, one a test builds and changes) is read afresh each time. The
 * memo is static, in WeakMaps keyed by the definition object, so a reader
 * with no service shares it. That is safe across kernel tests in one
 * process: an entry lives only while its definition object does, a test
 * builds its own definitions, and a read is a function of the definition
 * alone, so no entry can say what another container or request decided.
 *
 * The precondition of the memo: a definition a sealed data surface holds,
 * and one Location hands out, is never written after its node is built.
 * Code that changes a definition works on a copy (Surface::deepClone()), as
 * refiners and conditionals do. Where assertions run, each read of a kept
 * node checks it: the definition's fingerprint (fingerprint()) must equal
 * the one taken when its node was first built, or the assertion fails,
 * naming the definition's label (its source string) and data type. The
 * fingerprint is taken and compared only inside assert(), so production
 * pays nothing. It sees what the definition array holds (its type, label,
 * description, required, settings, its own constraints with their options,
 * the module's metadata), or for a definition that is no DataDefinition
 * what its getters say, and the same of every property and item below it.
 * Text is read as its source string, arguments and options. It does not
 * see inside any other object (a closure, a service, a definition held in
 * a setting): such an object is compared by identity, so one replaced is
 * seen and one changed in place is not.
 *
 * MODEL holds what a node knows that no document says. A built node holds
 * no reference to its definition: a reader that needs the definition takes
 * it from where it found the node (BuiltNodes::place()). Its members are a
 * closed list, MEMBERS, and each has a reader:
 * - `constraints`: every constraint the definition declares, by plugin
 *   id, with its options as held, the ones a keyword states included,
 *   read by the check provider through declares() and options(), and to
 *   word a keyword's refusal by its constraint. A bridge until the model
 *   is the source: a check names only what no keyword states.
 * - `type`: the definition's data type (getDataType()), on every node, so
 *   a node of a type the leaf table has no row for still says what it
 *   holds. Its one reader in this slice is the projection, which names
 *   the type in its refusal of such a node from the member alone
 *   (publishedNode()). A bridge for the pipeline's casts:
 *   it goes before the series closes.
 *
 * Decision: see
 * docs/decisions.md#a-definition-is-read-by-a-pure-function-the-projection-holds-the-request.
 *
 * @phpstan-type Definition \Drupal\Core\TypedData\DataDefinitionInterface
 *
 * @internal
 */
final class DefinitionSchema {

  /**
   * The model-only annotation: what a node knows that no document says.
   */
  public const MODEL = 'x-surface-model';

  /**
   * The members MODEL holds, a closed list.
   */
  public const MEMBERS = ['constraints', 'type'];

  /**
   * A placeholder the server fills in a check's message.
   *
   * Drupal's `%value` and `@value`, Symfony's `{{ value }}`, by any name.
   * A check's message that holds one is not written under
   * `x-portable-messages`.
   */
  protected const PLACEHOLDER = '/(?<![\w.])[%@]\w|\{\{ ?\w+ ?\}\}/';

  /**
   * The constraints a list of values is read from, by the options resolvers.
   *
   * Written as the `oneOf` of the list where the key's own list is
   * written (PropertySchema); one no resolver reads a list from
   * (AllowedValues, whose values a callback gives when it validates, or a
   * Choice with `match` FALSE), one on a list definition and one with
   * `multiple` are named under `portable-check`. The read names each under
   * `portable-check`; the projection leaves out the one a key's list
   * states (statedByList()).
   */
  public const LISTED = [
    'Choice',
    'LabeledChoice',
    'AllowedValues',
  ];

  /**
   * Constraints the schema states with no keyword of the constraint table.
   *
   * The constraints the table maps to keywords are its own
   * (ConstraintKeywords); the ones here are said otherwise. NotNull is
   * `required`. PrimitiveType, ComplexData and ValidReference are the
   * data type's own, which `type` states: the read names none of them.
   * The lists of values are LISTED, which the read names as checks.
   * Anything else no keyword states is named under `portable-check`, so a
   * client knows the schema is not the whole story for that key, unless
   * the resolver reading it says its list is the whole of it, which is
   * its `oneOf` (SurfaceOptions::isStatedByList()): the projection leaves
   * that one out.
   */
  public const STATED = [
    'NotNull',
    'PrimitiveType',
    'ComplexData',
    'ValidReference',
    ...self::LISTED,
  ];

  /**
   * The built nodes of definitions a data surface holds.
   *
   * @var \WeakMap<\Drupal\Core\TypedData\DataDefinitionInterface, \PortableSchema\Builder\Schema>|null
   */
  private static ?\WeakMap $built = NULL;

  /**
   * The definitions a sealed data surface holds.
   *
   * @var \WeakMap<\Drupal\Core\TypedData\DataDefinitionInterface, true>|null
   */
  private static ?\WeakMap $held = NULL;

  /**
   * The data surfaces whose definitions are marked.
   *
   * @var \WeakMap<\Drupal\surface\SurfaceInterface, true>|null
   */
  private static ?\WeakMap $sealed = NULL;

  /**
   * The fingerprint of each held definition whose node was built.
   *
   * Written and read only inside assert() (fingerprinted(), unchanged()).
   *
   * @var \WeakMap<\Drupal\Core\TypedData\DataDefinitionInterface, string>|null
   */
  private static ?\WeakMap $prints = NULL;

  /**
   * Whether a list states a constraint, by plugin id.
   *
   * A function of the two plugin managers' definitions alone.
   *
   * @var array<string, bool>
   */
  private array $byList = [];

  /**
   * Whether a resolver reads a list of values from a constraint.
   *
   * By plugin id and serialized options (readsAsList()).
   *
   * @var array<string, bool>
   */
  private array $readsAsList = [];

  /**
   * The label each constraint plugin declares, by plugin id.
   *
   * Kept as the plugin definition holds it, never rendered: sentence()
   * renders it for each document, in the document's language.
   *
   * @var array<string, mixed>
   */
  private array $labels = [];

  /**
   * Constructs a DefinitionSchema, for the projection.
   *
   * @param \Drupal\surface\Options\SurfaceOptions $options
   *   The options service, which says whether a key offers a list of
   *   values and whether a list states a constraint.
   * @param \Drupal\Core\Validation\ConstraintManager|null $constraints
   *   The constraint plugin manager, for what a constraint only the server
   *   checks says it checks (its label); without it, its id.
   */
  public function __construct(
    protected readonly SurfaceOptions $options,
    protected readonly ?ConstraintManager $constraints = NULL,
  ) {}

  /**
   * Marks every definition a sealed data surface holds.
   *
   * Its keys' and its outputs' definitions; their properties and items
   * are marked as each node is built.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   */
  public static function seal(SurfaceInterface $surface): void {
    self::$sealed ??= new \WeakMap();
    self::$held ??= new \WeakMap();
    if (isset(self::$sealed[$surface])) {
      return;
    }
    self::$sealed[$surface] = TRUE;
    foreach ($surface->getDefinitions()->entries() as $entry) {
      self::$held[$entry->definition] = TRUE;
    }
    foreach ($surface->getOutputDefinitions()->entries() as $entry) {
      self::$held[$entry->definition] = TRUE;
    }
  }

  /**
   * Marks a definition as one a sealed data surface holds.
   *
   * Its node is then built once (of()), under the precondition the class
   * states: it is never written after.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition, one a data surface holds (its key's, or a property
   *   or item below one), as Location hands it out.
   */
  public static function hold(DataDefinitionInterface $definition): void {
    self::$held ??= new \WeakMap();
    self::$held[$definition] = TRUE;
  }

  /**
   * Reads the node of a collection's own definition, without its items.
   *
   * What a collection key says of itself: its title, description, `type`
   * and keywords (`minItems`, `maxItems`), its checks and messages, and
   * MODEL. Its items are the child every item is, which the emitter
   * refers to.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The list, as the parent says it.
   *
   * @return \PortableSchema\Builder\Schema
   *   Its built node.
   */
  public static function head(DataDefinitionInterface $definition): Schema {
    $keywords = static::keywords($definition, 'array');
    $checks = array_intersect_key($keywords, array_flip([PortableKeywords::CHECK, PortableKeywords::MESSAGES]));
    $schema = Schemas::defaults(static::describe($definition), ['type' => static::nullable('array', $definition)] + array_diff_key($keywords, $checks));
    return Schemas::defaults($schema, $checks)->with(self::MODEL, static::annotation($definition));
  }

  /**
   * Reads one definition into its built node.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return \PortableSchema\Builder\Schema
   *   The built node: the same object for the same definition a data
   *   surface holds.
   */
  public static function of(DataDefinitionInterface $definition): Schema {
    return self::read($definition, '', []);
  }

  /**
   * Reads one definition into its built node, below the ones above it.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param string $path
   *   Where it is in the node being read, as a JSON Pointer ('' at the
   *   top).
   * @param array<int, true> $above
   *   The definitions above it, by object id.
   *
   * @return \PortableSchema\Builder\Schema
   *   The built node.
   *
   * @throws \LogicException
   *   When the definition is one of those above it.
   */
  private static function read(DataDefinitionInterface $definition, string $path, array $above): Schema {
    $id = spl_object_id($definition);
    if (isset($above[$id])) {
      throw new \LogicException(sprintf('The definition of the %s type at "%s" holds itself: it is met again inside itself, so no node can be read from it.', $definition->getDataType(), $path));
    }
    $above[$id] = TRUE;
    self::$built ??= new \WeakMap();
    self::$prints ??= new \WeakMap();
    $held = isset(self::$held[$definition]);
    if ($held && isset(self::$built[$definition])) {
      assert(self::unchanged($definition));
      return self::$built[$definition];
    }
    $node = self::build($definition, $held, $path, $above);
    if ($held) {
      assert(isset(self::$prints[$definition]) ? self::unchanged($definition) : self::fingerprinted($definition));
      self::$built[$definition] = $node;
    }
    return $node;
  }

  /**
   * Builds a node, reading a map's properties and a list's item with read().
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param bool $held
   *   Whether a sealed data surface holds it, so its properties and items
   *   are held too.
   * @param string $path
   *   Where it is in the node being read, as a JSON Pointer.
   * @param array<int, true> $above
   *   The definitions above it and itself, by object id.
   *
   * @return \PortableSchema\Builder\Schema
   *   The built node.
   */
  private static function build(DataDefinitionInterface $definition, bool $held, string $path, array $above): Schema {
    $schema = static::describe($definition);
    if ($definition instanceof ListDataDefinitionInterface) {
      $item = $definition->getItemDefinition();
      if ($held) {
        self::$held[$item] = TRUE;
      }
      $list = [
        'type' => static::nullable('array', $definition),
        'items' => static::read($item, $path . '/items', $above),
      ];
      $schema = Schemas::defaults($schema, $list + static::keywords($definition, 'array'));
    }
    elseif ($definition instanceof ComplexDataDefinitionInterface && static::isMap($definition)) {
      $properties = [];
      $required = [];
      foreach ($definition->getPropertyDefinitions() as $name => $property) {
        // A key an alter mounted and a refinement ruled out is read too:
        // the projection leaves it out (applying()).
        if ($held) {
          self::$held[$property] = TRUE;
        }
        $at = $path . '/properties/' . strtr((string) $name, [
          '~' => '~0',
          '/' => '~1',
        ]);
        $properties[(string) $name] = static::read($property, $at, $above);
        if (static::required($property)) {
          $required[] = (string) $name;
        }
      }
      $schema = Schemas::defaults($schema, [
        'type' => static::nullable('object', $definition),
        // Every key ruled out leaves an object still.
        'properties' => $properties,
        'additionalProperties' => FALSE,
      ]);
      if ($required !== []) {
        $schema = $schema->with('required', $required);
      }
      $schema = static::mapChecks($schema, $definition);
    }
    else {
      $type = static::type($definition);
      if (isset($type['type'])) {
        $type['type'] = static::nullable($type['type'], $definition);
      }
      $schema = Schemas::defaults($schema, static::keywords($definition, $type['type'] ?? NULL, $type));
    }
    // What only the server checks, and the messages, come after what a
    // value holds by default; the emitter places what a request adds
    // before them (PropertySchema::property()).
    $checks = array_intersect_key($schema->all(), array_flip([PortableKeywords::CHECK, PortableKeywords::MESSAGES]));
    foreach (array_keys($checks) as $keyword) {
      $schema = $schema->without($keyword);
    }
    if (DefinitionMetadata::isSecret($definition)) {
      $schema = $schema->with('writeOnly', TRUE);
    }
    elseif (!$definition instanceof ComplexDataDefinitionInterface) {
      // A definition with no place for a default or examples (a
      // configurable field) holds none, so it is asked first.
      $default = DefinitionMetadata::canHoldDefaultValue($definition) && DefinitionMetadata::hasDefaultValue($definition) ? DefinitionMetadata::getDefaultValue($definition) : NULL;
      if ($default !== NULL) {
        $schema = $schema->with('default', $default);
      }
      $examples = DefinitionMetadata::canHoldExamples($definition) ? DefinitionMetadata::getExamples($definition) : [];
      if ($examples !== []) {
        $schema = $schema->with('examples', array_values($examples));
      }
    }
    return Schemas::defaults($schema, $checks)->with(self::MODEL, static::annotation($definition));
  }

  /**
   * Answers whether a definition is read as a map of properties.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return bool
   *   TRUE for a complex definition that has properties; FALSE for a
   *   list, one value, and a map with none, which is read as one value.
   */
  public static function isMap(DataDefinitionInterface $definition): bool {
    return !$definition instanceof ListDataDefinitionInterface && $definition instanceof ComplexDataDefinitionInterface && $definition->getPropertyDefinitions() !== [];
  }

  /**
   * Writes a definition's MODEL.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return array{constraints: array<string, mixed>, type: string}
   *   The members, MEMBERS.
   */
  private static function annotation(DataDefinitionInterface $definition): array {
    $constraints = [];
    foreach ($definition->getConstraints() as $id => $options) {
      $constraints[(string) $id] = $options;
    }
    return [
      'constraints' => $constraints,
      'type' => $definition->getDataType(),
    ];
  }

  /**
   * Takes a held definition's fingerprint, when its node is first built.
   *
   * Called only inside assert(), so production never takes one.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return bool
   *   TRUE, always: the assertion holds.
   */
  private static function fingerprinted(DataDefinitionInterface $definition): bool {
    self::$prints ??= new \WeakMap();
    self::$prints[$definition] = static::fingerprint($definition);
    return TRUE;
  }

  /**
   * Answers that a held definition was not written since its node was built.
   *
   * Called only inside assert(), so production never compares. The
   * message names the label by its source string, so it renders nothing.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return bool
   *   TRUE when its fingerprint is the one taken, or none was taken.
   *
   * @throws \AssertionError
   *   When it differs: the kept node no longer says the definition.
   */
  private static function unchanged(DataDefinitionInterface $definition): bool {
    $taken = self::$prints[$definition] ?? NULL;
    if ($taken === NULL || $taken === static::fingerprint($definition)) {
      return TRUE;
    }
    $label = $definition->getLabel();
    $named = match (TRUE) {
      $label instanceof TranslatableMarkup => $label->getUntranslatedString(),
      is_string($label) => $label,
      default => get_debug_type($label),
    };
    throw new \AssertionError(sprintf('The definition "%s" (%s) was written after its node was built. A definition a sealed data surface holds, or one Location hands out, is never written after its node is built; change a copy (Surface::deepClone()).', $named, $definition->getDataType()));
  }

  /**
   * Writes what a definition says, at every depth, as one string.
   *
   * What it sees and what it does not is the class's rule: the definition
   * array, or the getters of a definition that is no DataDefinition, and
   * the same of its properties and items; text by its source string,
   * arguments and options; any other object by its class and identity.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param array<int, true> $seen
   *   The definitions above, by object id, so a definition that holds
   *   itself is read once.
   *
   * @return string
   *   The fingerprint.
   */
  private static function fingerprint(DataDefinitionInterface $definition, array $seen = []): string {
    $id = spl_object_id($definition);
    if (isset($seen[$id])) {
      return 'above:' . $id;
    }
    $seen[$id] = TRUE;
    $own = $definition instanceof DataDefinition ? $definition->toArray() : [
      'type' => $definition->getDataType(),
      'label' => $definition->getLabel(),
      'description' => $definition->getDescription(),
      'required' => $definition->isRequired(),
      'settings' => $definition->getSettings(),
      'constraints' => $definition->getConstraints(),
    ];
    $below = [];
    if ($definition instanceof ListDataDefinitionInterface) {
      $below['items'] = static::fingerprint($definition->getItemDefinition(), $seen);
    }
    elseif ($definition instanceof ComplexDataDefinitionInterface) {
      foreach ($definition->getPropertyDefinitions() as $name => $property) {
        $below[(string) $name] = static::fingerprint($property, $seen);
      }
    }
    return hash('xxh128', serialize([get_class($definition), static::comparable($own), $below]));
  }

  /**
   * Writes a value a definition holds as data a fingerprint can compare.
   *
   * @param mixed $value
   *   The value.
   *
   * @return mixed
   *   Scalars and arrays alone: a TranslatableMarkup as its source string,
   *   arguments and options; any other object as its class and identity.
   */
  private static function comparable(mixed $value): mixed {
    if ($value instanceof TranslatableMarkup) {
      return [
        'text',
        $value->getUntranslatedString(),
        static::comparable($value->getArguments()),
        static::comparable($value->getOptions()),
      ];
    }
    if (is_object($value)) {
      return ['object', get_class($value), spl_object_id($value)];
    }
    if (is_array($value)) {
      return array_map(static::comparable(...), $value);
    }
    return $value;
  }

  /**
   * Reads a definition's title and description.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return \PortableSchema\Builder\Schema
   *   `title` and `description`, each where the definition holds one that
   *   is not NULL and not the literal '', as the definition holds it;
   *   whether it renders empty is the projection's (publishedTexts()).
   */
  public static function describe(DataDefinitionInterface $definition): Schema {
    $described = Schema::any();
    $label = $definition->getLabel();
    if ($label !== NULL && $label !== '') {
      $described = $described->with('title', $label);
    }
    $description = $definition->getDescription();
    if ($description !== NULL && $description !== '') {
      $described = $described->with('description', $description);
    }
    return $described;
  }

  /**
   * Names the JSON type, and format, a data type is.
   *
   * The row of the type table (LeafTypes); a map or a list has none here,
   * since what it holds says its type.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return array
   *   `type`, and `format` where the row has one or the key is drawn as a
   *   date; empty for a map, a list, the `any` the module makes, and a
   *   value of a type no row is for, which the projection refuses.
   */
  public static function type(DataDefinitionInterface $definition): array {
    if (!LeafTypes::isLeaf($definition)) {
      return [];
    }
    // A date input posts the RFC 3339 full-date, `YYYY-MM-DD`, so a key
    // drawn as one is that format. A time input posts `HH:MM`, which
    // RFC 3339's time refuses (it needs seconds and an offset), so a time
    // states no format.
    if ($definition->getDataType() === 'string' && DateWidget::draws($definition)) {
      return ['type' => 'string', 'format' => 'date'];
    }
    return LeafTypes::spelled($definition) ?? [];
  }

  /**
   * Answers whether a key is in its object's `required`.
   *
   * A key the resource requires must be present and not null. A secret is
   * always optional, whatever it requires: left out or null, the stored
   * secret stands, and a required one that was never set is refused by
   * the pipeline as missing. Nor is a computed key asked of a caller: one
   * computed unless sent is computed when left out.
   *
   * Decision: see docs/decisions.md#a-secret-is-optional-never-nullable.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key.
   *
   * @return bool
   *   Whether the schema requires it.
   */
  public static function required(DataDefinitionInterface $definition): bool {
    return $definition->isRequired() && !DefinitionMetadata::isSecret($definition) && Computed::of($definition) === NULL;
  }

  /**
   * Allows null beside a type, for an optional key a caller may clear.
   *
   * Null on an optional key clears it, so it is a value the key takes. A
   * required key never takes it, nor one its storage always holds, which
   * refuses it, and neither does a secret, which null leaves as it is and
   * only its clear marker empties.
   *
   * Decision: see docs/decisions.md#absent-keeps-null-clears and
   * docs/decisions.md#a-key-its-storage-always-holds.
   *
   * @param string|array $type
   *   The JSON type.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return string|array
   *   The type, or the type and null.
   */
  public static function nullable(string|array $type, DataDefinitionInterface $definition): string|array {
    if (DefinitionMetadata::holdsValue($definition) || DefinitionMetadata::isSecret($definition) || is_array($type)) {
      return $type;
    }
    return [$type, 'null'];
  }

  /**
   * States a definition's constraints as JSON Schema keywords and checks.
   *
   * The table of constraints and keywords writes what it maps
   * (ConstraintKeywords::state()); what is left is named under
   * `portable-check`, but for the data type's own constraints STATED
   * names. A list of values (LISTED) is named too: whether a key's list
   * states it is the projection's.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param string|array|null $type
   *   The JSON type it was given.
   * @param array $given
   *   What the key says already, its `type` and `format`, which the
   *   constraints' keywords are merged into and which come first.
   *
   * @return array
   *   The keywords; then `portable-check`, each constraint no keyword
   *   states by `drupal:<id>` with its options as the definition holds
   *   them, and `x-portable-messages`, a Regex's own message by the code
   *   of its keyword and each check's own message under
   *   `check.drupal:<id>`, as the text is held; each only where it holds
   *   something.
   */
  public static function keywords(DataDefinitionInterface $definition, string|array|null $type, array $given = []): array {
    return static::stated($definition->getConstraints(), $type, $given);
  }

  /**
   * States constraints as JSON Schema keywords and checks.
   *
   * What keywords() says of a definition's constraints, read from the
   * constraints alone, so a node's MODEL `constraints` gives the same.
   *
   * @param array $constraints
   *   The constraints, options by plugin id, as getConstraints() gives
   *   them.
   * @param string|array|null $type
   *   The JSON type it was given.
   * @param array $given
   *   What the key says already, as keywords() takes it.
   *
   * @return array
   *   What keywords() returns.
   */
  private static function stated(array $constraints, string|array|null $type, array $given = []): array {
    $stated = ConstraintKeywords::state($constraints, (array) $type, $given);
    $keywords = $stated['keywords'];
    // A pattern is for machines; a person is told the message, in the
    // document's language: the source core's validator translates when it
    // refuses a value.
    $messages = [];
    foreach ($stated['extras']['messages'] ?? [] as $code => $message) {
      $message = is_string($message) ? static::text($message) : $message;
      if ($message instanceof TranslatableMarkup) {
        $messages[(string) $code] = $message;
      }
    }
    $checks = [];
    foreach ($stated['left'] as [$name, $options, $mapped]) {
      // The data type's own constraints are said by `type` and `required`;
      // every other constraint the table does not state is a check.
      if ($mapped || in_array($name, self::LISTED, TRUE) || !in_array($name, self::STATED, TRUE)) {
        static::checked($checks, $messages, $name, $options);
      }
    }
    return $keywords + ($checks === [] ? [] : [PortableKeywords::CHECK => $checks]) + ($messages === [] ? [] : [PortableKeywords::MESSAGES => $messages]);
  }

  /**
   * Names a map's own constraints under `portable-check`, at the map.
   *
   * A map's settings, a map in a map and the map each item of a list is
   * alike: every constraint the definition holds as its own, not its data
   * type's (ReviewDeadline on an amount and a unit together, a
   * ComplexData), but NotNull and PrimitiveType, which `required` and
   * `type` state. Each is a check the server runs at the map
   * (DrupalCheckProvider), so the document names it. No keyword states a
   * map's constraint, so the constraint table is not read here.
   *
   * Decision: see docs/decisions.md#the-checks-the-document-names.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The map's node.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The map.
   *
   * @return \PortableSchema\Builder\Schema
   *   The node, each of them under `portable-check` and its own message
   *   under `x-portable-messages`.
   */
  private static function mapChecks(Schema $schema, DataDefinitionInterface $definition): Schema {
    $own = $definition instanceof DataDefinition ? ($definition->toArray()['constraints'] ?? []) : [];
    $checks = (array) ($schema->get(PortableKeywords::CHECK) ?? []);
    $messages = (array) ($schema->get(PortableKeywords::MESSAGES) ?? []);
    $named = FALSE;
    foreach (is_array($own) ? $own : [] as $id => $options) {
      if (!in_array((string) $id, ['NotNull', 'PrimitiveType'], TRUE)) {
        static::checked($checks, $messages, (string) $id, $options);
        $named = TRUE;
      }
    }
    if (!$named) {
      return $schema;
    }
    $schema = $schema->with(PortableKeywords::CHECK, $checks);
    return $messages === [] ? $schema : $schema->with(PortableKeywords::MESSAGES, $messages);
  }

  /**
   * Names a constraint only the server checks, with the message it is given.
   *
   * A constraint named twice is one check: its first options and its
   * first message stand. The options are the definition's; what the
   * document says of them is CheckOptions::published(), and the sentence
   * of a check given no message is the projection's (sentence()).
   *
   * @param array $checks
   *   The checks so far, options by `drupal:<id>`; gains this one.
   * @param array $messages
   *   The messages so far, by code; gains this one's, where it has one.
   * @param string $name
   *   The constraint's plugin id.
   * @param mixed $options
   *   Its options, as the definition holds them.
   */
  private static function checked(array &$checks, array &$messages, string $name, mixed $options): void {
    $id = PortableKeywords::PROVIDER . $name;
    if (array_key_exists($id, $checks)) {
      return;
    }
    $checks[$id] = $options;
    $message = static::heldMessage($name, $options);
    if ($message !== NULL) {
      $messages += [PortableKeywords::CHECK_CODE . $id => $message];
    }
  }

  /**
   * Reads the message a definition gives a check, as it is held.
   *
   * The constraint's own reason where it was given one (SurfaceUnique's),
   * else its own message (a Regex's is how a person is told what it
   * allows). A message holding a placeholder the server fills is said by
   * the server's refusal alone, so it is none here. A JsonSchema check's
   * is the keywords of the schema it holds a value to.
   *
   * Decision: see docs/decisions.md#contract-2,
   * docs/decisions.md#a-schema-can-be-read-back-into-a-structure.
   *
   * @param string $name
   *   The constraint's plugin id.
   * @param mixed $options
   *   Its options.
   *
   * @return \Stringable|null
   *   The message as held, a source string given as a NodeMessage; NULL
   *   for none.
   */
  public static function heldMessage(string $name, mixed $options): ?\Stringable {
    if ($name === 'JsonSchema' && is_array($options)) {
      // What a structure read from a schema kept of it and no keyword of
      // this document states: named by the keywords it holds a value to.
      return static::heldTo(is_array($options['schema'] ?? NULL) ? $options['schema'] : []);
    }
    // A constraint that refuses in a sentence of its own (SurfaceUnique)
    // says what it checks in its `reason`.
    $message = is_array($options) ? ($options['reason'] ?? $options['message'] ?? NULL) : NULL;
    if (is_string($message) && preg_match(self::PLACEHOLDER, $message)) {
      // The dialect's messages are plain sentences: one the server fills
      // in (`%value`) is said by the server's refusal alone.
      return NULL;
    }
    if (is_string($message)) {
      return $message === '' ? NULL : static::text($message);
    }
    return $message instanceof \Stringable ? $message : NULL;
  }

  /**
   * Says which keywords a JSON Schema only the server checks holds.
   *
   * @param array $schema
   *   The schema a JsonSchema constraint holds a value, or a frame, to.
   *
   * @return \Drupal\surface\Contract\NodeMessage
   *   The message, built with no translation service.
   */
  public static function heldTo(array $schema): NodeMessage {
    // Schemas held together as an `allOf` alone are named by what each
    // says: the message a frame's several schemas were written with.
    $together = array_keys($schema) === ['allOf'] && is_array($schema['allOf']) && array_is_list($schema['allOf']) && array_filter($schema['allOf'], 'is_array') === $schema['allOf'];
    $keywords = $together ? array_values(array_unique(array_merge(...array_map(JsonSchemaConstraint::keywords(...), $schema['allOf'])))) : JsonSchemaConstraint::keywords($schema);
    return new NodeMessage('Checked on the server against the schema it was read from: @keywords.', ['@keywords' => implode(', ', $keywords)]);
  }

  /**
   * Writes a message the definition holds as a source string.
   *
   * @param string $string
   *   The source string.
   *
   * @return \Drupal\surface\Contract\NodeMessage
   *   The message, translated each time it is rendered, built with no
   *   translation service.
   */
  private static function text(string $string): NodeMessage {
    // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
    return new NodeMessage($string);
  }

  /**
   * Reads a node's MODEL.
   *
   * @param \PortableSchema\Builder\Schema|null $node
   *   A built node, or NULL.
   *
   * @return array{constraints?: array<string, mixed>, type?: string}
   *   The members; empty for no node, or a node no definition was read
   *   into.
   */
  public static function model(?Schema $node): array {
    $model = $node?->get(self::MODEL);
    return is_array($model) ? $model : [];
  }

  /**
   * Answers whether a built node's definition declares a constraint.
   *
   * @param \PortableSchema\Builder\Schema|null $node
   *   The built node.
   * @param string $id
   *   The constraint's plugin id.
   *
   * @return bool
   *   TRUE where it does.
   */
  public static function declares(?Schema $node, string $id): bool {
    return array_key_exists($id, static::model($node)['constraints'] ?? []);
  }

  /**
   * Reads the options a built node's constraint runs with.
   *
   * A check's own options, under `portable-check`; a constraint a keyword
   * states, or one no check names, from the declared list.
   *
   * @param \PortableSchema\Builder\Schema|null $node
   *   The built node.
   * @param string $id
   *   The constraint's plugin id.
   *
   * @return mixed
   *   Its options as the definition holds them: an array, NULL or a
   *   scalar; NULL where it declares none.
   */
  public static function options(?Schema $node, string $id): mixed {
    $checks = $node?->get(PortableKeywords::CHECK);
    $name = PortableKeywords::PROVIDER . $id;
    if (is_array($checks) && array_key_exists($name, $checks)) {
      return $checks[$name];
    }
    return static::model($node)['constraints'][$id] ?? NULL;
  }

  /**
   * Answers whether a key offers a list of values.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key.
   *
   * @return bool
   *   TRUE where the options service resolves a set for it, of its item
   *   for a list.
   */
  public function listed(DataDefinitionInterface $definition): bool {
    return $this->options->resolve($definition instanceof ListDataDefinitionInterface ? $definition->getItemDefinition() : $definition) !== NULL;
  }

  /**
   * Projects a built node, and every node inside it, to the document form.
   *
   * Each node as publishedNode() projects it, beside its own definition: a
   * list's item beside the item's, a map's properties beside theirs, each
   * offering a list of values or not as the options service says. A node
   * at any other keyword position has no definition beside it: MODEL is
   * stripped from it, at every depth, and nothing else.
   *
   * @param \PortableSchema\Builder\Schema $built
   *   A built node (of(), head()).
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition it was read from.
   * @param bool|null $listed
   *   Whether the key offers a list of values; NULL to ask the options
   *   service.
   *
   * @return \PortableSchema\Builder\Schema
   *   What a document says of it, at every depth.
   */
  public function published(Schema $built, DataDefinitionInterface $definition, ?bool $listed = NULL): Schema {
    return Schemas::map($this->projected($built, $definition, $listed), static fn (Schema $node): Schema => $node->has(self::MODEL) ? $node->without(self::MODEL) : $node);
  }

  /**
   * Projects a node, its item and its properties, beside their definitions.
   *
   * @param \PortableSchema\Builder\Schema $built
   *   A built node.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition it was read from.
   * @param bool|null $listed
   *   Whether the key offers a list of values; NULL to ask the options
   *   service.
   *
   * @return \PortableSchema\Builder\Schema
   *   The node, each item and property projected (publishedNode()).
   */
  private function projected(Schema $built, DataDefinitionInterface $definition, ?bool $listed = NULL): Schema {
    $node = $this->publishedNode($built, $definition, $listed);
    if ($definition instanceof ListDataDefinitionInterface) {
      $item = $node->get('items');
      return $item instanceof Schema ? $node->with('items', $this->projected($item, $definition->getItemDefinition())) : $node;
    }
    $properties = $node->get('properties');
    if (!is_array($properties) || !static::isMap($definition)) {
      return $node;
    }
    $definitions = $definition instanceof ComplexDataDefinitionInterface ? $definition->getPropertyDefinitions() : [];
    foreach ($properties as $name => $property) {
      $below = $definitions[$name] ?? NULL;
      if ($property instanceof Schema && $below instanceof DataDefinitionInterface) {
        $properties[$name] = $this->projected($property, $below);
      }
    }
    return $node->with('properties', $properties);
  }

  /**
   * Projects one built node, and not the nodes inside it.
   *
   * What the class says the projection holds: a map that offers a list of
   * values said as one value (oneValue()), a property that does not
   * apply left out with its place in `required` (applying()), a value of a
   * type no row is for refused, the checks a list states left out and a
   * check's sentence given (publishedChecks()), the title and description
   * as plain text (publishedTexts()), MODEL stripped. Every keyword keeps
   * its place.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The built node (of(), head()).
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition it was read from.
   * @param bool|null $listed
   *   Whether the key offers a list of values; NULL to ask the options
   *   service.
   *
   * @return \PortableSchema\Builder\Schema
   *   The node as a document says it.
   *
   * @throws \LogicException
   *   For a value of a type no row is for (LeafTypes::unwritten()), whose
   *   type is named by MODEL's `type` alone.
   */
  public function publishedNode(Schema $node, DataDefinitionInterface $definition, ?bool $listed = NULL): Schema {
    if ($node->isBoolean()) {
      return $node;
    }
    $map = static::isMap($definition);
    if ($map) {
      $listed ??= $this->listed($definition);
    }
    if ($map && $listed) {
      $node = static::oneValue($node);
      $map = FALSE;
    }
    elseif ($map) {
      $node = static::applying($node, $definition);
    }
    $value = !$map && !$definition instanceof ListDataDefinitionInterface;
    if ($value && LeafTypes::isLeaf($definition) && !$node->has('type') && !LeafTypes::heldAsAny($definition)) {
      throw LeafTypes::unwritten($definition->getLabel(), (string) (static::model($node)['type'] ?? ''));
    }
    // A list's own checks and a map's are never stated by a list of its
    // values, so the options service is asked only for a value's.
    $listed = $value && ($listed ?? $this->listed($definition));
    $node = $this->publishedChecks(static::publishedTexts($node), $map, $listed);
    return $node->has(self::MODEL) ? $node->without(self::MODEL) : $node;
  }

  /**
   * Projects what keywords() read, as a value's checks are projected.
   *
   * For a reader that compares what a document would say of a value's
   * constraints (PropertySchema::signature()).
   *
   * @param \PortableSchema\Builder\Schema $keywords
   *   What keywords() read, as a schema.
   * @param bool $listed
   *   Whether the key offers a list of values a list constraint states.
   *
   * @return \PortableSchema\Builder\Schema
   *   The same, as a document says it.
   */
  public function publishedKeywords(Schema $keywords, bool $listed): Schema {
    return $this->publishedChecks($keywords, FALSE, $listed);
  }

  /**
   * Says a map's built node as one value.
   *
   * What a map that offers a list of values is said as: a value with a
   * `oneOf`, so its own constraints are stated as a value's are, and its
   * properties, `type`, `required` and `additionalProperties` are no part
   * of it. Made from the node alone: its title and description, and its
   * MODEL `constraints` read as keywords() reads a value's constraints.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The map's built node (of()).
   *
   * @return \PortableSchema\Builder\Schema
   *   The node of one value, MODEL kept.
   */
  private static function oneValue(Schema $node): Schema {
    $value = Schema::any();
    foreach (['title', 'description'] as $text) {
      if ($node->has($text)) {
        $value = $value->with($text, $node->get($text));
      }
    }
    $model = static::model($node);
    $value = Schemas::defaults($value, static::stated((array) ($model['constraints'] ?? []), NULL));
    // What only the server checks, and the messages, come last, as the
    // read places them (build()).
    $checks = array_intersect_key($value->all(), array_flip([PortableKeywords::CHECK, PortableKeywords::MESSAGES]));
    foreach (array_keys($checks) as $keyword) {
      $value = $value->without($keyword);
    }
    if ($node->has('writeOnly')) {
      $value = $value->with('writeOnly', $node->get('writeOnly'));
    }
    $value = Schemas::defaults($value, $checks);
    return $node->has(self::MODEL) ? $value->with(self::MODEL, $node->get(self::MODEL)) : $value;
  }

  /**
   * Leaves out the properties of a map that do not apply.
   *
   * A key an alter mounted and a refinement ruled out is on the built node
   * and in its `required`; the document says neither, as at the top of a
   * frame. Every key ruled out leaves an object still.
   *
   * Decision: see docs/decisions.md#a-key-that-does-not-apply.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The map's built node.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The map, whose properties' definitions hold the mark.
   *
   * @return \PortableSchema\Builder\Schema
   *   The node, without them.
   */
  private static function applying(Schema $node, DataDefinitionInterface $definition): Schema {
    $properties = $node->get('properties');
    if (!is_array($properties) || !$definition instanceof ComplexDataDefinitionInterface) {
      return $node;
    }
    $out = [];
    foreach ($definition->getPropertyDefinitions() as $name => $property) {
      if (DoesNotApply::marks($property)) {
        $out[(string) $name] = TRUE;
      }
    }
    if ($out === []) {
      return $node;
    }
    $node = $node->with('properties', array_diff_key($properties, $out));
    $required = $node->get('required');
    if (!is_array($required)) {
      return $node;
    }
    $required = array_values(array_filter($required, static fn ($name): bool => !isset($out[(string) $name])));
    return $required === [] ? $node->without('required') : $node->with('required', $required);
  }

  /**
   * Projects a node's title and description, and not the nodes inside it.
   *
   * Each rendered as plain text; one that renders empty is left out.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A built node (of(), head(), describe()).
   *
   * @return \PortableSchema\Builder\Schema
   *   The node, its texts as a document says them.
   */
  public static function publishedTexts(Schema $node): Schema {
    if ($node->isBoolean()) {
      return $node;
    }
    foreach (['title', 'description'] as $text) {
      $held = $node->get($text);
      if (is_string($held) || $held instanceof \Stringable) {
        $rendered = (string) $held;
        $node = $rendered === '' ? $node->without($text) : $node->with($text, PlainText::of($rendered));
      }
    }
    return $node;
  }

  /**
   * Projects a node's checks and messages.
   *
   * Each check's options as CheckOptions::published() says them, but for a
   * check a list states (statedByList()), which is left out with its
   * message. Each message as plain text, a check's own or its sentence
   * (sentence()), one empty as plain text left out. The messages of
   * keywords come first, then each check's, in the checks' order.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The node.
   * @param bool $map
   *   Whether these are a map's own checks, which no list states.
   * @param bool $listed
   *   Whether the key, a value, offers a list of values.
   *
   * @return \PortableSchema\Builder\Schema
   *   The node, its checks and messages as a document says them.
   */
  private function publishedChecks(Schema $node, bool $map, bool $listed): Schema {
    $checks = $node->get(PortableKeywords::CHECK);
    $held = $node->get(PortableKeywords::MESSAGES);
    $held = is_array($held) ? $held : [];
    if (!is_array($checks)) {
      $checks = [];
    }
    $said = [];
    foreach ($held as $code => $message) {
      $code = (string) $code;
      if (str_starts_with($code, PortableKeywords::CHECK_CODE) && array_key_exists(substr($code, strlen(PortableKeywords::CHECK_CODE)), $checks)) {
        continue;
      }
      $plain = is_string($message) || $message instanceof \Stringable ? PlainText::of($message) : '';
      if ($plain !== '') {
        $said[$code] = $plain;
      }
    }
    $published = [];
    $format = $node->get('format');
    foreach ($checks as $id => $options) {
      $id = (string) $id;
      $name = str_starts_with($id, PortableKeywords::PROVIDER) ? substr($id, strlen(PortableKeywords::PROVIDER)) : NULL;
      $code = PortableKeywords::CHECK_CODE . $id;
      if ($name === NULL) {
        $published[$id] = $options;
        $plain = isset($held[$code]) && (is_string($held[$code]) || $held[$code] instanceof \Stringable) ? PlainText::of($held[$code]) : '';
      }
      elseif (!$map && $this->statedByList($name, $options, $listed)) {
        continue;
      }
      else {
        $published[$id] = CheckOptions::published($name, $options);
        // Email beside `format: email` and Uuid beside `format: uuid`: the
        // format's sentence says it, so no label stands in for a message
        // the constraint was not given.
        $sentence = $this->sentence($name, $options, $held[$code] ?? NULL, !ConstraintKeywords::isFormatTwin($name, $format));
        $plain = $sentence === '' ? '' : PlainText::of($sentence);
      }
      if ($plain !== '') {
        $said[$code] = $plain;
      }
    }
    if ($node->has(PortableKeywords::CHECK)) {
      $node = $published === [] ? $node->without(PortableKeywords::CHECK) : $node->with(PortableKeywords::CHECK, $published);
    }
    if ($said === []) {
      return $node->has(PortableKeywords::MESSAGES) ? $node->without(PortableKeywords::MESSAGES) : $node;
    }
    if ($node->has(PortableKeywords::MESSAGES)) {
      return $node->with(PortableKeywords::MESSAGES, $said);
    }
    // The messages stand right after the checks, where the read writes
    // them: MODEL, which follows the checks on a built node, goes first.
    $node = $node->has(self::MODEL) ? $node->without(self::MODEL) : $node;
    return $node->with(PortableKeywords::MESSAGES, $said);
  }

  /**
   * Answers whether a key's list of values states a check.
   *
   * A list constraint (LISTED) is the `oneOf` the options resolvers read,
   * where that `oneOf` is written for the key (a value that offers a list)
   * and one reads it. One resolved only when it is validated (a callback),
   * one that names the values it refuses (`match` FALSE), one on a list,
   * and one of several values (`multiple`, an array checked against the
   * list with its own `min` and `max`, written on no array here) are the
   * server's. A constraint whose resolver says it is its list is the
   * `oneOf` the list is written as. A constraint the table of keywords
   * has a row for is always the server's.
   *
   * @param string $name
   *   The constraint's plugin id.
   * @param mixed $options
   *   Its options.
   * @param bool $listed
   *   Whether the key, a value, offers a list of values.
   *
   * @return bool
   *   TRUE where the document leaves the check out.
   */
  private function statedByList(string $name, mixed $options, bool $listed): bool {
    if (ConstraintKeywords::row($name) !== NULL) {
      return FALSE;
    }
    if (in_array($name, self::LISTED, TRUE)) {
      return $listed && empty($options['multiple']) && $this->readsAsList($name, $options);
    }
    return $this->byList[$name] ??= $this->options->isStatedByList($name);
  }

  /**
   * Answers whether a resolver reads a list of values from a constraint.
   *
   * SurfaceOptions::readsAsList(), kept by plugin id and options: the
   * answer is a function of the two, and asking makes a constraint.
   * Options that cannot be serialized (a closure) are asked each time.
   *
   * @param string $name
   *   The constraint's plugin id.
   * @param mixed $options
   *   Its options.
   *
   * @return bool
   *   What the options service says.
   */
  private function readsAsList(string $name, mixed $options): bool {
    try {
      $key = $name . "\0" . serialize($options);
    }
    catch (\Throwable) {
      return $this->options->readsAsList($name, $options);
    }
    return $this->readsAsList[$key] ??= $this->options->readsAsList($name, $options);
  }

  /**
   * Gives the sentence a check says what it checks in, rendered.
   *
   * A client told only a plugin id learns nothing it can act on, so each
   * check carries a sentence: the message the definition gives it
   * (heldMessage()), else the label its plugin declares, else the id
   * again; a check beside the format it is the host's rule for
   * (ConstraintKeywords::FORMAT_TWINS) says none of those two. A message
   * that renders empty is none. A JsonSchema check says the keywords of
   * the schema it holds a value to, and nothing else.
   *
   * Decision: see docs/decisions.md#contract-2,
   * docs/decisions.md#a-reference-and-a-deferred-list-are-the-dialects-check-options-and-links.
   *
   * @param string $name
   *   The constraint's plugin id.
   * @param mixed $options
   *   Its options.
   * @param mixed $held
   *   The message the node holds for it, or NULL.
   * @param bool $labeled
   *   FALSE where a message the constraint was not given is left empty,
   *   not said by its label or id.
   *
   * @return string
   *   The sentence, rendered; '' for none.
   */
  private function sentence(string $name, mixed $options, mixed $held, bool $labeled): string {
    $rendered = is_string($held) || $held instanceof \Stringable ? (string) $held : '';
    if (($name === 'JsonSchema' && is_array($options)) || $rendered !== '' || !$labeled) {
      return $rendered;
    }
    if (!array_key_exists($name, $this->labels)) {
      $this->labels[$name] = $this->constraints?->getDefinitions()[$name]['label'] ?? NULL;
    }
    $label = $this->labels[$name];
    return $label instanceof \Stringable || (is_string($label) && $label !== '') ? (string) $label : $name;
  }

  /**
   * Writes one check as a frame names it, as the document says it.
   *
   * @param string $name
   *   The constraint's plugin id.
   * @param mixed $options
   *   Its options, as the frame's schema holds them.
   *
   * @return array{constraint: string, message: string, options?: array}
   *   The entry PortableKeywords::write() takes: its message as plain
   *   text, and the options CheckOptions::published() says where they are
   *   not empty.
   */
  public function entry(string $name, mixed $options): array {
    $message = $this->sentence($name, $options, static::heldMessage($name, $options), TRUE);
    $entry = ['constraint' => $name, 'message' => $message === '' ? '' : PlainText::of($message)];
    $published = CheckOptions::published($name, $options);
    return is_array($published) && $published !== [] ? $entry + ['options' => $published] : $entry;
  }

}
