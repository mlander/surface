<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\Validation\ConstraintManager;
use Drupal\surface\Computed;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Options\SurfaceOptions;
use Drupal\surface\Plugin\SurfaceWidget\DateWidget;
use Drupal\surface\Refinement\DoesNotApply;
use Drupal\surface\SurfaceInterface;
use PortableSchema\Builder\Schema;

/**
 * Reads a definition into its built node, and projects a node to a document.
 *
 * The rules:
 * - of() is the one read of a definition into a `Schema`. Its result is
 *   the built node of the definition at one site: what the definition
 *   says whatever the request, its title and description, its type and
 *   the keywords its constraints state, a map's properties and a list's
 *   items (each the built node of that definition), what only the server
 *   checks (`portable-check`, with each check's options as the definition
 *   holds them) and the messages (`x-portable-messages`), `writeOnly`
 *   for a secret, which is how a reader of the node knows it is one
 *   (Secrets::marked()), and MODEL. What depends on a request (an option
 *   set's `oneOf` and empty option, a stale value, a lock, links, the
 *   widget hints, what a key depends on, sharing a node as a def, naming
 *   it) is the emitter's (PropertySchema, ContractEmitter), which
 *   decorates the projection.
 * - Text stays as the definition holds it: a title, a description and a
 *   message that are a TranslatableMarkup stay one, so a node is the same
 *   in every language until it is projected.
 * - published() is the one place a built node becomes a document node, a
 *   node at a time (publishedNode()): each check's options go through
 *   CheckOptions::published(), the title, the description and each
 *   message are read as plain text (PlainText::of()), a message empty as
 *   plain text is left out, and MODEL is stripped. A node is projected
 *   before anything compares it (Schemas::same()), so every comparison
 *   of the walk sees what the document says, and two sites whose checks
 *   differ only in their options still share one def.
 * - A built node is held in memory only: it never reaches the build
 *   cache, a served document or ServedContract, a log or an exception
 *   message.
 * - The node of a definition a sealed data surface holds is built once
 *   per definition object and $listed. The code knows a definition is
 *   held by one because it was handed one: seal() marks every definition
 *   of a data surface (a data surface is sealed by construction), hold()
 *   marks the definition it is handed as one a data surface holds
 *   (BuiltNodes hands it what Location finds), and a node built for a
 *   marked definition marks its properties and items. Any other
 *   definition (a copy a conditional narrows, one a test builds and
 *   changes) is read afresh each time.
 * - The precondition of the memo: a definition a sealed data surface
 *   holds, and one Location hands out, is never written after its node is
 *   built. Code that changes a definition works on a copy
 *   (Surface::deepClone()), as refiners and conditionals do. Where
 *   assertions run, each read of a kept node checks it: the definition's
 *   fingerprint (fingerprint()) must equal the one taken when its node
 *   was first built, or the assertion fails, naming the definition's
 *   label and data type. The fingerprint is taken and compared only
 *   inside assert(), so production pays nothing. It sees what the
 *   definition array holds (its type, label, description, required,
 *   settings, its own constraints with their options, the module's
 *   metadata), or for a definition that is no DataDefinition what its
 *   getters say, and the same of every property and item below it. Text
 *   is read as its source string, arguments and options. It does not see
 *   inside any other object (a closure, a service, a definition held in
 *   a setting): such an object is compared by identity, so one replaced
 *   is seen and one changed in place is not.
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
 *
 * Decision: see
 * docs/decisions.md#a-definition-is-read-once-into-a-built-node.
 *
 * @phpstan-type Definition \Drupal\Core\TypedData\DataDefinitionInterface
 *
 * @internal
 */
final class DefinitionSchema {

  use StringTranslationTrait;

  /**
   * The model-only annotation: what a node knows that no document says.
   */
  public const MODEL = 'x-surface-model';

  /**
   * The members MODEL holds, a closed list.
   */
  public const MEMBERS = ['constraints'];

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
   * `multiple` are named under `portable-check`.
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
   * data type's own, which `type` states. The lists of values are LISTED.
   * Anything else no keyword states is named under `portable-check`, so a
   * client knows the schema is not the whole story for that key, unless
   * the resolver reading it says its list is the whole of it, which is
   * its `oneOf` (SurfaceOptions::isStatedByList()).
   */
  public const STATED = [
    'NotNull',
    'PrimitiveType',
    'ComplexData',
    'ValidReference',
    ...self::LISTED,
  ];

  /**
   * The built nodes of definitions a data surface holds, by $listed.
   *
   * @var \WeakMap<\Drupal\Core\TypedData\DataDefinitionInterface, array<int, \PortableSchema\Builder\Schema>>
   */
  private \WeakMap $built;

  /**
   * The definitions a sealed data surface holds.
   *
   * @var \WeakMap<\Drupal\Core\TypedData\DataDefinitionInterface, true>
   */
  private \WeakMap $held;

  /**
   * The data surfaces whose definitions are marked.
   *
   * @var \WeakMap<\Drupal\surface\SurfaceInterface, true>
   */
  private \WeakMap $sealed;

  /**
   * The fingerprint of each held definition whose node was built.
   *
   * Written and read only inside assert() (fingerprinted(), unchanged()).
   *
   * @var \WeakMap<\Drupal\Core\TypedData\DataDefinitionInterface, string>
   */
  private \WeakMap $prints;

  /**
   * Constructs a DefinitionSchema.
   *
   * @param \Drupal\surface\Options\SurfaceOptions $options
   *   The options service, which says whether a constraint is read as a
   *   list of values and whether a key offers one.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service, for a check's own message.
   * @param \Drupal\Core\Validation\ConstraintManager|null $constraints
   *   The constraint plugin manager, for what a constraint only the server
   *   checks says it checks (its label); without it, its id.
   */
  public function __construct(
    protected readonly SurfaceOptions $options,
    TranslationInterface $string_translation,
    protected readonly ?ConstraintManager $constraints = NULL,
  ) {
    $this->stringTranslation = $string_translation;
    $this->built = new \WeakMap();
    $this->held = new \WeakMap();
    $this->sealed = new \WeakMap();
    $this->prints = new \WeakMap();
  }

  /**
   * Marks every definition a sealed data surface holds.
   *
   * Its keys' and its outputs' definitions; their properties and items
   * are marked as each node is built.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   */
  public function seal(SurfaceInterface $surface): void {
    if (isset($this->sealed[$surface])) {
      return;
    }
    $this->sealed[$surface] = TRUE;
    foreach ($surface->getDefinitions()->entries() as $entry) {
      $this->held[$entry->definition] = TRUE;
    }
    foreach ($surface->getOutputDefinitions()->entries() as $entry) {
      $this->held[$entry->definition] = TRUE;
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
  public function hold(DataDefinitionInterface $definition): void {
    $this->held[$definition] = TRUE;
  }

  /**
   * Builds the node of a collection's own definition, without its items.
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
  public function head(DataDefinitionInterface $definition): Schema {
    $keywords = $this->keywords($definition, 'array');
    $checks = array_intersect_key($keywords, array_flip([PortableKeywords::CHECK, PortableKeywords::MESSAGES]));
    $schema = Schemas::defaults($this->describe($definition), ['type' => $this->nullable('array', $definition)] + array_diff_key($keywords, $checks));
    return Schemas::defaults($schema, $checks)->with(self::MODEL, $this->annotation($definition));
  }

  /**
   * Builds the node of one definition at one site.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param bool $listed
   *   Whether the key offers a list of values (an option set, of its item
   *   for a list): a key that does is described as a value with a `oneOf`,
   *   whatever its type, and a list constraint the `oneOf` states is no
   *   check.
   *
   * @return \PortableSchema\Builder\Schema
   *   The built node: the same object for the same definition a data
   *   surface holds and the same $listed.
   */
  public function of(DataDefinitionInterface $definition, bool $listed): Schema {
    $held = isset($this->held[$definition]);
    if ($held && isset($this->built[$definition][(int) $listed])) {
      assert($this->unchanged($definition));
      return $this->built[$definition][(int) $listed];
    }
    $node = $this->build($definition, $listed, $held);
    if ($held) {
      assert(isset($this->prints[$definition]) ? $this->unchanged($definition) : $this->fingerprinted($definition));
      $built = $this->built[$definition] ?? [];
      $built[(int) $listed] = $node;
      $this->built[$definition] = $built;
    }
    return $node;
  }

  /**
   * Builds a node, recursing through a map's properties and a list's items.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param bool $listed
   *   Whether the key offers a list of values.
   * @param bool $held
   *   Whether a sealed data surface holds it, so its properties and items
   *   are held too.
   *
   * @return \PortableSchema\Builder\Schema
   *   The built node.
   */
  protected function build(DataDefinitionInterface $definition, bool $listed, bool $held): Schema {
    $schema = $this->describe($definition);
    if ($definition instanceof ListDataDefinitionInterface) {
      $item = $definition->getItemDefinition();
      if ($held) {
        $this->held[$item] = TRUE;
      }
      $list = [
        'type' => $this->nullable('array', $definition),
        'items' => $this->of($item, $this->listed($item)),
      ];
      $schema = Schemas::defaults($schema, $list + $this->keywords($definition, 'array'));
    }
    elseif (!$listed && $definition instanceof ComplexDataDefinitionInterface && $definition->getPropertyDefinitions() !== []) {
      $properties = [];
      $required = [];
      foreach ($definition->getPropertyDefinitions() as $name => $property) {
        if (DoesNotApply::marks($property)) {
          // A key an alter mounted, ruled out: as at the top of a frame.
          continue;
        }
        if ($held) {
          $this->held[$property] = TRUE;
        }
        $properties[(string) $name] = $this->of($property, $this->listed($property));
        if (static::required($property)) {
          $required[] = (string) $name;
        }
      }
      $schema = Schemas::defaults($schema, [
        'type' => $this->nullable('object', $definition),
        // Every key ruled out leaves an object still.
        'properties' => $properties,
        'additionalProperties' => FALSE,
      ]);
      if ($required !== []) {
        $schema = $schema->with('required', $required);
      }
      $schema = $this->mapChecks($schema, $definition);
    }
    else {
      $type = $this->type($definition);
      if (isset($type['type'])) {
        $type['type'] = $this->nullable($type['type'], $definition);
      }
      $schema = Schemas::defaults($schema, $this->keywords($definition, $type['type'] ?? NULL, $type, $listed));
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
      $default = DefinitionMetadata::hasDefaultValue($definition) ? DefinitionMetadata::getDefaultValue($definition) : NULL;
      if ($default !== NULL) {
        $schema = $schema->with('default', $default);
      }
      $examples = DefinitionMetadata::getExamples($definition);
      if ($examples !== []) {
        $schema = $schema->with('examples', array_values($examples));
      }
    }
    return Schemas::defaults($schema, $checks)->with(self::MODEL, $this->annotation($definition));
  }

  /**
   * Writes a definition's MODEL.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return array{constraints: array<string, mixed>}
   *   The members, MEMBERS.
   */
  protected function annotation(DataDefinitionInterface $definition): array {
    $constraints = [];
    foreach ($definition->getConstraints() as $id => $options) {
      $constraints[(string) $id] = $options;
    }
    return [
      'constraints' => $constraints,
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
  private function fingerprinted(DataDefinitionInterface $definition): bool {
    $this->prints[$definition] = static::fingerprint($definition);
    return TRUE;
  }

  /**
   * Answers that a held definition was not written since its node was built.
   *
   * Called only inside assert(), so production never compares.
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
  private function unchanged(DataDefinitionInterface $definition): bool {
    $taken = $this->prints[$definition] ?? NULL;
    if ($taken === NULL || $taken === static::fingerprint($definition)) {
      return TRUE;
    }
    throw new \AssertionError(sprintf('The definition "%s" (%s) was written after its node was built. A definition a sealed data surface holds, or one Location hands out, is never written after its node is built; change a copy (Surface::deepClone()).', PlainText::of((string) $definition->getLabel()), $definition->getDataType()));
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
   * Reads a definition's title and description.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return \PortableSchema\Builder\Schema
   *   `title` and `description`, each only when the definition has one,
   *   as the definition holds it (publishedNode() reads it as plain
   *   text).
   */
  public function describe(DataDefinitionInterface $definition): Schema {
    $described = Schema::any();
    $label = $definition->getLabel();
    if ($label !== NULL && (string) $label !== '') {
      $described = $described->with('title', $label);
    }
    $description = $definition->getDescription();
    if ($description !== NULL && (string) $description !== '') {
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
   *   date; empty for a map, a list, and the `any` the module makes.
   *
   * @throws \LogicException
   *   For a value of a type no row is for, which the blueprint refuses.
   */
  public function type(DataDefinitionInterface $definition): array {
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
    return LeafTypes::spelling($definition);
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
  public function nullable(string|array $type, DataDefinitionInterface $definition): string|array {
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
   * `portable-check`, but for the constraints STATED names.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param string|array|null $type
   *   The JSON type it was given.
   * @param array $given
   *   What the key says already, its `type` and `format`, which the
   *   constraints' keywords are merged into and which come first.
   * @param bool $listed
   *   Whether the key's `oneOf` is written from its own constraints'
   *   list: never for a list, whose `oneOf` is its items'.
   *
   * @return array
   *   The keywords; then `portable-check`, each constraint no keyword
   *   states by `drupal:<id>` with its options as the definition holds
   *   them, and `x-portable-messages`, a Regex's own message by the code
   *   of its keyword and each check's under `check.drupal:<id>`, as the
   *   text is held; each only where it holds something.
   */
  public function keywords(DataDefinitionInterface $definition, string|array|null $type, array $given = [], bool $listed = FALSE): array {
    $stated = ConstraintKeywords::state($definition->getConstraints(), (array) $type, $given);
    $keywords = $stated['keywords'];
    // A pattern is for machines; a person is told the message, in the
    // document's language: the source core's validator translates when it
    // refuses a value.
    $messages = [];
    foreach ($stated['extras']['messages'] ?? [] as $code => $message) {
      // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
      $message = is_string($message) ? $this->t($message) : $message;
      if ($message instanceof TranslatableMarkup) {
        $messages[(string) $code] = $message;
      }
    }
    $checks = [];
    foreach ($stated['left'] as [$name, $options, $mapped]) {
      $said = !$mapped && match (TRUE) {
        // A list of values is the `oneOf` the options resolvers read, where
        // that `oneOf` is written for this key and one reads it. One
        // resolved only when it is validated (a callback), one that names
        // the values it refuses (`match` FALSE), one on a list, and one
        // of several values (`multiple`, an array checked against the list
        // with its own `min` and `max`, written on no array here) are the
        // server's.
        in_array($name, self::LISTED, TRUE) => $listed && empty($options['multiple']) && $this->options->readsAsList($name, $options),
        // A constraint whose resolver says it is its list is the `oneOf`
        // the list is written as.
        default => in_array($name, self::STATED, TRUE) || $this->options->isStatedByList($name),
      };
      if (!$said) {
        // Email beside `format: email` and Uuid beside `format: uuid`:
        // the format's sentence says it, so no label stands in for a
        // message the constraint was not given.
        $this->checked($checks, $messages, $name, $options, !ConstraintKeywords::isFormatTwin($name, $keywords['format'] ?? NULL));
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
   *   The node, each of them under `portable-check` and its message under
   *   `x-portable-messages`.
   */
  protected function mapChecks(Schema $schema, DataDefinitionInterface $definition): Schema {
    $own = $definition instanceof DataDefinition ? ($definition->toArray()['constraints'] ?? []) : [];
    $checks = (array) ($schema->get(PortableKeywords::CHECK) ?? []);
    $messages = (array) ($schema->get(PortableKeywords::MESSAGES) ?? []);
    $named = FALSE;
    foreach (is_array($own) ? $own : [] as $id => $options) {
      if (!in_array((string) $id, ['NotNull', 'PrimitiveType'], TRUE)) {
        $this->checked($checks, $messages, (string) $id, $options);
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
   * Names a constraint only the server checks, and says what it checks.
   *
   * A client told only a plugin id learns nothing it can act on, so each
   * check carries a sentence: the constraint's own reason where it was
   * given one (SurfaceUnique's), else its own message (a Regex's is how a
   * person is told what it allows), else the label its plugin declares,
   * else the id again; a check beside the format it is the host's rule for
   * (ConstraintKeywords::FORMAT_TWINS) says none of those two. A message
   * holding a placeholder the server fills is said by the server's refusal
   * alone. A JsonSchema check says the keywords of the schema it holds a
   * value to. A constraint named twice is one check: its first options and
   * its first message stand. The options are the definition's; what the
   * document says of them is CheckOptions::published().
   *
   * Decision: see docs/decisions.md#contract-2,
   * docs/decisions.md#a-reference-and-a-deferred-list-are-the-dialects-check-options-and-links.
   *
   * @param array $checks
   *   The checks so far, options by `drupal:<id>`; gains this one.
   * @param array $messages
   *   The messages so far, by code; gains this one's, where it has one.
   * @param string $name
   *   The constraint's plugin id.
   * @param mixed $options
   *   Its options, as the definition holds them.
   * @param bool $labeled
   *   FALSE where a message the constraint was not given is left empty,
   *   not said by its label or id.
   */
  protected function checked(array &$checks, array &$messages, string $name, mixed $options, bool $labeled = TRUE): void {
    $id = PortableKeywords::PROVIDER . $name;
    if (array_key_exists($id, $checks)) {
      return;
    }
    $checks[$id] = $options;
    $message = $this->message($name, $options, $labeled);
    if ($message !== '') {
      $messages += [PortableKeywords::CHECK_CODE . $id => $message];
    }
  }

  /**
   * Gives the sentence a check says what it checks in, as it is held.
   *
   * @param string $name
   *   The constraint's plugin id.
   * @param mixed $options
   *   Its options.
   * @param bool $labeled
   *   FALSE where a message the constraint was not given is left empty.
   *
   * @return string|\Stringable
   *   The sentence; '' for none.
   */
  protected function message(string $name, mixed $options, bool $labeled = TRUE): string|\Stringable {
    if ($name === 'JsonSchema' && is_array($options)) {
      // What a structure read from a schema kept of it and no keyword of
      // this document states: named by the keywords it holds a value to.
      // Decision: see
      // docs/decisions.md#a-schema-can-be-read-back-into-a-structure.
      return ContractEmitter::foreignMessage(is_array($options['schema'] ?? NULL) ? $options['schema'] : [], $this->stringTranslation);
    }
    // A constraint that refuses in a sentence of its own (SurfaceUnique)
    // says what it checks in its `reason`.
    $message = is_array($options) ? ($options['reason'] ?? $options['message'] ?? NULL) : NULL;
    if (is_string($message) && preg_match(self::PLACEHOLDER, $message)) {
      // The dialect's messages are plain sentences: one the server fills
      // in (`%value`) is said by the server's refusal alone.
      $message = NULL;
    }
    if (is_string($message) && $message !== '') {
      // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
      $message = $this->t($message);
    }
    if ((!$message instanceof \Stringable || (string) $message === '') && !$labeled) {
      return '';
    }
    if (!$message instanceof \Stringable || (string) $message === '') {
      $label = $this->constraints?->getDefinitions()[$name]['label'] ?? NULL;
      return $label instanceof \Stringable || (is_string($label) && $label !== '') ? $label : $name;
    }
    return $message;
  }

  /**
   * Writes a message the definition holds as a source string.
   *
   * @param string $string
   *   The source string.
   * @param array $args
   *   Its arguments.
   * @param array $options
   *   Its options.
   *
   * @return \Drupal\surface\Contract\NodeMessage
   *   The message, translated each time it is rendered.
   */
  protected function t($string, array $args = [], array $options = []) {
    return new NodeMessage($string, $args, $options, $this->getStringTranslation());
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
    $message = $this->message($name, $options);
    $entry = ['constraint' => $name, 'message' => $message === '' ? '' : PlainText::of($message)];
    $published = CheckOptions::published($name, $options);
    return is_array($published) && $published !== [] ? $entry + ['options' => $published] : $entry;
  }

  /**
   * Reads a node's MODEL.
   *
   * @param \PortableSchema\Builder\Schema|null $node
   *   A built node, or NULL.
   *
   * @return array{constraints?: array<string, mixed>}
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
   * Projects a built node, and every node inside it, to the document form.
   *
   * @param \PortableSchema\Builder\Schema $built
   *   A built node (of(), head(), describe()).
   *
   * @return \PortableSchema\Builder\Schema
   *   What a document says of it (publishedNode(), at every depth).
   */
  public static function published(Schema $built): Schema {
    return Schemas::map($built, static::publishedNode(...));
  }

  /**
   * Projects one built node, and not the nodes inside it.
   *
   * Each check's options as CheckOptions::published() says them; the
   * title, the description and each message as plain text, a message
   * empty as plain text left out; MODEL stripped. Every keyword keeps its
   * place.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The built node.
   *
   * @return \PortableSchema\Builder\Schema
   *   The node as a document says it.
   */
  public static function publishedNode(Schema $node): Schema {
    if ($node->isBoolean()) {
      return $node;
    }
    foreach (['title', 'description'] as $text) {
      $held = $node->get($text);
      if (is_string($held) || $held instanceof \Stringable) {
        $node = $node->with($text, PlainText::of($held));
      }
    }
    $checks = $node->get(PortableKeywords::CHECK);
    if (is_array($checks)) {
      $published = [];
      foreach ($checks as $name => $options) {
        $name = (string) $name;
        $published[$name] = str_starts_with($name, PortableKeywords::PROVIDER) ? CheckOptions::published(substr($name, strlen(PortableKeywords::PROVIDER)), $options) : $options;
      }
      $node = $node->with(PortableKeywords::CHECK, $published);
    }
    $messages = $node->get(PortableKeywords::MESSAGES);
    if (is_array($messages)) {
      $said = [];
      foreach ($messages as $code => $message) {
        $plain = is_string($message) || $message instanceof \Stringable ? PlainText::of($message) : '';
        if ($plain !== '') {
          $said[$code] = $plain;
        }
      }
      $node = $said === [] ? $node->without(PortableKeywords::MESSAGES) : $node->with(PortableKeywords::MESSAGES, $said);
    }
    return $node->has(self::MODEL) ? $node->without(self::MODEL) : $node;
  }

}
