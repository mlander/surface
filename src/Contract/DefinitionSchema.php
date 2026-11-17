<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\Core\Validation\ConstraintManager;
use Drupal\surface\Computed;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Options\SurfaceOptions;
use Drupal\surface\Plugin\Validation\Constraint\JsonSchemaConstraint;
use PortableSchema\Builder\Schema;

/**
 * Reads a definition into its built schema, and projects one to a document.
 *
 * The class has two halves, split by what each needs.
 *
 * Reading (of(), head(), describe(), keywords() and every static method
 * they call) is a pure function of the definition:
 * - It uses no service, makes no `\Drupal::` call of its own, runs no
 *   options resolver, reads no plugin manager and renders no text. So a
 *   schema can be read where a data surface is built, with no request.
 * - The calls it makes on a definition are getDataType(), getLabel(),
 *   getDescription(), isRequired(), getConstraints(), getSetting(),
 *   toArray() on a DataDefinition, getPropertyDefinitions() and
 *   getMainPropertyName() on a complex definition, getItemDefinition()
 *   on a list, and, through DefinitionMetadata, toArray(), offsetExists()
 *   and offsetGet() where the definition offers them, and isSecret(),
 *   hasDefaultValue(), getDefaultValue() and getExamples() where it offers
 *   them with no required argument. What those do inside core is core's:
 *   a DataDefinition's getConstraints() asks the typed data manager for
 *   its type's default constraints, an entity's getPropertyDefinitions()
 *   asks the entity field manager, and a list's getItemDefinition() asks
 *   the typed data manager when it builds the item.
 * - of() is the one read of a definition into a `Schema`: the built schema,
 *   the definition's own statement at every depth. It holds the title and
 *   description, the type and the keywords its constraints state, every
 *   property of a map and the item of a list (each the built schema of that
 *   definition, read by the same function), every declared check under
 *   `portable-check` with the options the definition holds, whether or not
 *   a list of values states it, the messages the definition gives
 *   (`x-portable-messages`), `writeOnly` for a secret, which is how a
 *   reader of the schema knows it is one (Secrets::marked()), and MODEL.
 * - The schema holds every fact of a DataDefinition, a ListDataDefinition
 *   or a MapDataDefinition: what a keyword states stays in that keyword,
 *   and every other fact is a member of MODEL (MEMBERS), so a definition
 *   of the three classes is derived back from the schema alone
 *   (DefinitionFromSchema::of()) and equals the one read. A shape's, a config
 *   type's and `each`'s definition are read into schemas their members
 *   hold. A definition of any other class is read as before: its keywords
 *   from its getters, its class in `definitionClass`. Its schema does not
 *   hold what the class keeps outside the definition array (a reference's
 *   target, a field item's field definition, an entity's properties, a
 *   FieldConfig's `required` and settings), and the inverse refuses it.
 * - A property a refinement marked as not applying (DoesNotApply) is on
 *   the schema, and so is the mark, in its `doesNotApply` member, where the
 *   projection reads it.
 * - Every data type is read: a definition of a data type the leaf table
 *   has no row for (LeafTypes) gets a schema with no `type` keyword, and
 *   its data type in MODEL; a definition with no place for a default or
 *   examples (DefinitionMetadata::canHoldDefaultValue(), canHoldExamples())
 *   has none. The read's one refusal: a definition met again inside
 *   itself (a map that holds itself, or a definition held as its own
 *   shape, config type or `each`) is refused with a LogicException naming
 *   its data type and where it is met again, before the read runs out of
 *   memory.
 * - Text stays as the definition holds it: a title, a description and a
 *   message that are a TranslatableMarkup stay one, so a schema is the same
 *   in every language until it is projected. A title or description is
 *   kept when the definition holds one that is not NULL and not the
 *   literal ''. A message the reader writes itself (a check's own sentence
 *   given as a string, a Regex's, what a JsonSchema check holds a value
 *   to) is a SchemaMessage built with no translation service. A check the
 *   definition gives no message holds none.
 *
 * Projecting (published(), publishedSchema(), publishedKeywords(), entry())
 * is the one place a built schema becomes a document schema, and holds what
 * needs a service or the request:
 * - Whether a key offers a list of values (listed(), an option set, of its
 *   item for a list), and what follows from it: the check its list states
 *   and that check's message leave `portable-check`, and a map that offers
 *   one is said as one value, made from its schema (oneValue(): its
 *   properties, `type`, `required` and `additionalProperties` are no
 *   keywords of it). The options service is handed the schema; whether
 *   it is a map comes from its schema too (holdsProperties()).
 * - Whether a list states a check (SurfaceOptions::isStatedByList(),
 *   SurfaceOptions::readsAsList()), kept on the service by plugin id and
 *   options; a check's label is kept by plugin id as its plugin holds it.
 *   Nothing rendered is kept.
 * - A check's label as its sentence where the definition states no
 *   message, or one that renders empty.
 * - A property that does not apply (its schema's `doesNotApply` member) is
 *   left out, and the map's `required` follows.
 * - A value of a data type no row is for is refused, with LeafTypes'
 *   message, where its document schema is made (publishedSchema()), one schema
 *   at a time, by MODEL's `type`. A reader that takes a schema and does not
 *   project it (Location::definitionAt()) gets a schema with no `type`
 *   keyword and the data type in MODEL, and no refusal.
 * - Each check's options go through CheckOptions::published(); the title,
 *   the description and each message are rendered as plain text
 *   (PlainText::of()); a title, description or message that renders empty
 *   is left out; MODEL is stripped. published() also strips MODEL at
 *   every other keyword position (`allOf`, `$defs`, `patternProperties`
 *   and the rest), at every depth.
 * A schema is projected before anything compares it (Schemas::same()), so
 * every comparison of the walk sees what the document says, and two sites
 * whose checks differ only in their options still share one def. What
 * the projection knows of a definition it reads from the schema: whether
 * it is a map, a list or one value (holdsProperties(), isList(), isLeaf()),
 * whether it is an `any` held as one (heldAsAny()), the mark of a key
 * that does not apply, and the label its refusal names. It takes the
 * definition beside the schema, and walks the two together, only to hand
 * each definition to the options resolvers (listed()); that parameter
 * goes when the options resolvers take a schema.
 *
 * A built schema is held in memory only: it never reaches the build cache, a
 * served document or ServedContract, a log or an exception message.
 *
 * Nothing is kept here: of() reads the definition each time it is called.
 * The schema of a key is kept by its entry (Entry::schema()), and a reader
 * that walks down from an entry walks the entry's schema in step
 * (propertyOf(), itemOf()), so no definition is read again at each
 * depth. A definition two entries share is read once by each.
 *
 * MODEL holds what a schema knows that no document says. A built schema holds
 * no reference to its definition: a reader that needs the definition takes
 * it from where it found the schema (a Location), or derives one
 * from the schema (DefinitionFromSchema::of()). Its members are a closed list,
 * MEMBERS, whose docblock says what each holds and who reads it; the
 * bridges among them, which go before the series closes, are BRIDGES.
 *
 * Decision: see
 * docs/decisions.md#a-definition-is-read-by-a-pure-function-the-projection-holds-the-request,
 * docs/decisions.md#a-schema-holds-every-fact-of-its-definition-and-a-definition-is-derived-back-from-it,
 * docs/decisions.md#an-entry-holds-its-schema-the-memo-is-gone.
 *
 * @phpstan-type Definition \Drupal\Core\TypedData\DataDefinitionInterface
 *
 * @internal
 */
final class DefinitionSchema {

  /**
   * The model-only annotation: what a schema knows that no document says.
   */
  public const MODEL = 'x-surface-model';

  /**
   * The members MODEL holds, a closed list, in the order a schema holds them.
   *
   * A member is on a schema only where its definition states the fact, and
   * only where no keyword of the schema holds it already; `type` is on every
   * schema. What each holds, and who reads it:
   * - `type`: the data type (getDataType()). Read by the projection, which
   *   names it in the refusal of a type no row is for, and by the inverse.
   * - `definitionClass`: the definition's PHP class, where it is not the
   *   one implied() names for the schema. Read by the projection (whether a
   *   schema is a list, a map or one value) and by the inverse.
   * - `label`, `description`: the label or description as stored where
   *   `title` or `description` does not hold it: the literal '' or NULL.
   *   Read by the inverse; the projection reads `label` for its refusal.
   * - `required`: the definition's stored `required`, on its own schema (the
   *   parent's `required` keyword keeps its document rule). Read by the
   *   inverse.
   * - `readOnly`, `computed`: core's `read-only` and `computed` flags, as
   *   stored. Read by the inverse.
   * - `settings`: the settings, whole and as stored. Read by the inverse.
   * - `constraints`: every constraint the definition declares with its
   *   type's defaults (getConstraints()), by plugin id, with its options as
   *   held, only where SchemaFacts::derived() does not give that list exactly
   *   from `type`, `declared`, `required` and `stored` (a type with no row
   *   in LeafTypes::DEFAULT_CONSTRAINTS, a `class` key, a class whose
   *   getConstraints() is its own); everywhere else the list is derived.
   *   Read by SchemaFacts::constraints() alone, which every reader of the
   *   merged list asks (the check provider, declares(), options(),
   *   oneValue(), keywordsOf(), the inverse's refusal of a schema whose
   *   type's defaults are another manager's).
   * - `declared`: the definition's own constraints (toArray()), as stored.
   *   Read by the inverse, by SchemaFacts::derived() and, through
   *   SchemaFacts::declaredConstraints(), by ConfigSchemaGenerator,
   *   ResourceCode and InputDefinitions.
   * - `default`, `examples`: the stored default or examples where the
   *   keyword does not hold them as stored: an explicit NULL, a map's, a
   *   secret's, examples with keys. Read by the inverse. A default merged
   *   from a map's properties (DefinitionMetadata::defaultOf()) is no
   *   member: it is made from the properties' schemas.
   * - `secret`: the stored secret flag where `writeOnly` does not state it
   *   as stored: FALSE, NULL, or a truthy value other than TRUE (beside
   *   `writeOnly`, which says TRUE). Read by the inverse.
   * - `variantsBy`, `heldWhole`, `readVariants`, `oneVariant`, `fixed`,
   *   `grouping`, `alwaysHeld`, `placedAfter`, `variantsFrom`, `ownLabel`,
   *   `arrangement`: what DefinitionMetadata stores under its keys, as
   *   stored (an Arrangement and a text are the objects held). Read by
   *   the inverse; the projection reads `variantsBy` and `heldWhole` for
   *   an `any` held as one. A reader of the own label falls back to
   *   `title`, then to the `label` member.
   * - `compute`, `resolve`: the Computed and the Resolve the definition
   *   holds, the objects themselves. Read by the inverse.
   * - `shape`: `{id, schema}`, the shape's id and the schema of the shape's
   *   definition; `configType`: `{type, schema}`, the config schema type and
   *   the schema of its own definition; `each`: the schema of the definition
   *   every entry has, or the class every entry is. Each schema is read by
   *   read(), so the recursion ends where the definitions held end, and a
   *   definition met again inside itself is refused. Read by the inverse.
   * - `doesNotApply`: the mark of a key a refinement ruled out, as stored.
   *   Read by the projection, which leaves such a property out.
   * - `mainProperty`: a MapDataDefinition's main property name, where one
   *   is set. Read by the inverse.
   * - `stored`: every key of the definition array PLACES does not name (a
   *   hand definition's own key, core's `class` and `internal`), and a
   *   value its member cannot hold (a `type` key that is not the data
   *   type, a shape of another form), as stored. Read by the inverse.
   * The order of a definition array's keys, and a `type` key that holds
   * the data type beside none, are no facts: nothing reads them, so no
   * member holds them.
   */
  public const MEMBERS = [
    'type',
    'definitionClass',
    'label',
    'description',
    'required',
    'readOnly',
    'computed',
    'settings',
    'constraints',
    'declared',
    'default',
    'examples',
    'secret',
    'variantsBy',
    'heldWhole',
    'readVariants',
    'oneVariant',
    'fixed',
    'grouping',
    'alwaysHeld',
    'placedAfter',
    'variantsFrom',
    'ownLabel',
    'arrangement',
    'compute',
    'resolve',
    'shape',
    'configType',
    'each',
    'doesNotApply',
    'mainProperty',
    'stored',
  ];

  /**
   * The members that are bridges, each to go before the series closes.
   *
   * A bridge exists only so a Typed Data definition can be derived back
   * (DefinitionFromSchema::of()) or because a consumer has not moved yet; every
   * other member is a fact of Surface's own model and stays. Each bridge,
   * its readers, and the slice it goes in:
   * - `type`: the projection (the refusal of a type no row is for), the
   *   inverse, SchemaFacts::derived(), and every reader of
   *   SchemaFacts::dataType() (the pipeline's casts, the config schema
   *   generator, the tool door's inputs, the generated code, among
   *   others); goes before slice 35 closes;
   * - `definitionClass`: classOf() and so isList(), isComplex() and
   *   isLeaf() (the projection, the validator, the definition map, the
   *   tool door's inputs, the generated code), and the inverse; goes in
   *   slice 35;
   * - `readOnly`, `computed`, `mainProperty`: the inverse alone; go in
   *   slice 35, when nothing derives a definition;
   * - `stored`: the inverse, and SchemaFacts::derived(), which gives no list
   *   where it holds a `class` key; goes in slice 35;
   * - `declared`: the inverse, SchemaFacts::derived(), and
   *   ConfigSchemaGenerator, ResourceCode and InputDefinitions through
   *   SchemaFacts::declaredConstraints(); goes once an authored schema says
   *   what its own constraints are;
   * - `constraints`: on a schema only where SchemaFacts::derived() does not
   *   give getConstraints() exactly; read by SchemaFacts::constraints()
   *   alone; goes in slice 35.
   */
  public const BRIDGES = [
    'type',
    'definitionClass',
    'readOnly',
    'computed',
    'constraints',
    'declared',
    'mainProperty',
    'stored',
  ];

  /**
   * Where each key of a definition array is held on its schema.
   *
   * The member, and the keyword that holds the key's value where it holds
   * it as stored (NULL for none), by the key, in the order the inverse
   * writes the keys. The keys DefinitionMetadata and DoesNotApply store
   * are written as strings here; ModelMembersTest ties each to its
   * constant.
   */
  public const PLACES = [
    'type' => ['type', NULL],
    'label' => ['label', 'title'],
    'description' => ['description', 'description'],
    'required' => ['required', NULL],
    'read-only' => ['readOnly', NULL],
    'computed' => ['computed', NULL],
    'settings' => ['settings', NULL],
    'constraints' => ['declared', NULL],
    'default_value' => ['default', 'default'],
    'examples' => ['examples', 'examples'],
    'secret' => ['secret', 'writeOnly'],
    'surface_variants_by' => ['variantsBy', NULL],
    'surface_held_whole' => ['heldWhole', NULL],
    'surface_read_variants' => ['readVariants', NULL],
    'surface_one_variant' => ['oneVariant', NULL],
    'surface_fixed' => ['fixed', NULL],
    'surface_grouping' => ['grouping', NULL],
    'surface_always_held' => ['alwaysHeld', NULL],
    'surface_placed_after' => ['placedAfter', NULL],
    'surface_variants_from' => ['variantsFrom', NULL],
    'surface_own_label' => ['ownLabel', NULL],
    'surface_arrangement' => ['arrangement', NULL],
    'surface_computed' => ['compute', NULL],
    'surface_resolve' => ['resolve', NULL],
    'surface_shape' => ['shape', NULL],
    'surface_config_type' => ['configType', NULL],
    'surface_each' => ['each', NULL],
    'surface_does_not_apply' => ['doesNotApply', NULL],
  ];

  /**
   * The members that hold a schema read from a definition the array holds.
   */
  protected const NESTED = ['shape', 'configType', 'each'];

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
   * Reads the schema of a collection's own definition, without its items.
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
   *   Its built schema.
   */
  public static function head(DataDefinitionInterface $definition): Schema {
    $keywords = static::keywords($definition, 'array');
    $checks = array_intersect_key($keywords, array_flip([PortableKeywords::CHECK, PortableKeywords::MESSAGES]));
    $schema = Schemas::defaults(static::describe($definition), ['type' => static::nullable('array', $definition)] + array_diff_key($keywords, $checks));
    $schema = Schemas::defaults($schema, $checks);
    return $schema->with(self::MODEL, static::annotation($definition, $schema, '', [spl_object_id($definition) => TRUE]));
  }

  /**
   * Reads a definition's own facts around the schemas given for what is below.
   *
   * The schema of() reads, with each property or item given here placed
   * where of() would read it: the schema of a key that holds a child frame
   * (an attachment, variants, a collection, a mount), whose properties
   * are the child's keys, which the child's own entries read already.
   * What the definition says of itself (its keywords, `required` from its
   * properties' flags, its checks and MODEL) is read once, and nothing
   * given is read again. A property or an item not given is read as of()
   * reads it. A schema given for a property the map does not hold is placed
   * after the ones it holds, in the order given, and is in `required`
   * where its own schema says so: the seal hands a shell its own facts
   * alone and its child's keys as schemas (Entry::sealed()), and the schema is
   * the one of() reads of the map the child's definitions would fill.
   * Unlike head(), which reads a collection's own keywords with no items
   * at all, in its own order, the schema is the one of() reads:
   * the same keywords in the same order, so DefinitionSchema::same()
   * holds between the two when each schema given is the one of() reads for
   * its definition.
   *
   * Decision: see
   * docs/decisions.md#a-key-that-holds-a-child-frame-has-a-schema-of-its-own.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param array<string, \PortableSchema\Builder\Schema> $properties
   *   For a map, the schemas of its properties, by name; those not given are
   *   read, and those it does not hold come after the ones it holds.
   * @param \PortableSchema\Builder\Schema|null $items
   *   For a list, the schema of its item; NULL to read it.
   *
   * @return \PortableSchema\Builder\Schema
   *   The built schema.
   */
  public static function around(DataDefinitionInterface $definition, array $properties = [], ?Schema $items = NULL): Schema {
    return self::build($definition, '', [spl_object_id($definition) => TRUE], $properties, $items);
  }

  /**
   * Reads a map's own facts as the schema of a map that holds no property.
   *
   * The schema of() reads of the map once a child's keys fill it, its
   * `properties` empty and no `required`: what withProperties() places a
   * child's keys in. Its own keywords, its checks and MODEL are read from
   * the definition alone: the shell of variants (Variants::$shell), which
   * every chosen variant wears.
   *
   * Decision: see docs/decisions.md#a-refined-shell-is-composed-of-schemas.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The map, its own facts alone.
   *
   * @return \PortableSchema\Builder\Schema
   *   Its built schema, as a map's.
   */
  public static function shell(DataDefinitionInterface $definition): Schema {
    return self::build($definition, '', [spl_object_id($definition) => TRUE], asMap: TRUE);
  }

  /**
   * Places a map's properties in a map's schema, by name, in the order given.
   *
   * The composer of a refined shell (an attachment, chosen variants, a
   * structure at a mount): the shell's schema with `properties` replaced by
   * the schemas given, and its `required` list rewritten from each of them
   * (requiredOf(), the rule around() places a schema given by). Everything
   * else the schema holds (its own keywords, its checks, MODEL) is left as
   * it is: nothing the schema says of itself depends on its properties. No
   * schema given is read or copied: each is placed as it is. It writes no
   * fact of a property and no own fact of the map: not `declared`, not
   * the `constraints` member, not a key's own `required`.
   *
   * Decision: see docs/decisions.md#a-refined-shell-is-composed-of-schemas.
   *
   * @param \PortableSchema\Builder\Schema $shell
   *   A map's schema (one that holds `properties`): a sealed shell's, or
   *   shell()'s.
   * @param array<string, \PortableSchema\Builder\Schema> $properties
   *   The schemas of its properties, by name, in their order: a refined
   *   child's entries' schemas.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema, the very schemas given under `properties`.
   */
  public static function withProperties(Schema $shell, array $properties): Schema {
    assert(static::holdsProperties($shell));
    $required = static::requiredOf($properties);
    $schema = $shell->with('properties', $properties);
    if ($required === [] || $schema->has('required')) {
      return $required === [] ? $schema->without('required') : $schema->with('required', $required);
    }
    // `required` comes right after `additionalProperties`, where build()
    // writes it: what follows is taken off and put back after it.
    $after = [];
    $seen = FALSE;
    foreach ($schema->all() as $name => $value) {
      if ($seen) {
        $after[(string) $name] = $value;
      }
      $seen = $seen || $name === 'additionalProperties';
    }
    foreach (array_keys($after) as $name) {
      $schema = $schema->without((string) $name);
    }
    $schema = $schema->with('required', $required);
    foreach ($after as $name => $value) {
      $schema = $schema->with((string) $name, $value);
    }
    return $schema;
  }

  /**
   * Replaces the schema at a path of properties below a map's schema.
   *
   * Each map on the way keeps its `required` list: the schema placed is
   * composed of the one it replaces (withProperties()), and says what
   * that one says of itself, so whether it is required is unchanged.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A map's schema: a mount's.
   * @param string[] $parents
   *   The property names from it to the place.
   * @param \PortableSchema\Builder\Schema $with
   *   The schema to place there.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema, the one at the path replaced.
   */
  public static function withPropertyAt(Schema $schema, array $parents, Schema $with): Schema {
    if ($parents === []) {
      return $with;
    }
    $name = array_shift($parents);
    $name = (string) $name;
    $properties = $schema->get('properties');
    assert(is_array($properties) && ($properties[$name] ?? NULL) instanceof Schema);
    $properties[$name] = static::withPropertyAt($properties[$name], $parents, $with);
    return $schema->with('properties', $properties);
  }

  /**
   * Lists the properties a map requires, by their schemas.
   *
   * The one rule for the `required` list of a map composed of schemas: a
   * property is listed where its own schema says it is required
   * (PropertySchema::required(): required, and neither a secret nor
   * computed), as required() says it of a definition.
   *
   * @param array<string, \PortableSchema\Builder\Schema> $properties
   *   The schemas of the map's properties, by name.
   *
   * @return string[]
   *   The names, in the order given.
   */
  private static function requiredOf(array $properties): array {
    $required = [];
    foreach ($properties as $name => $schema) {
      if (PropertySchema::required($schema)) {
        $required[] = (string) $name;
      }
    }
    return $required;
  }

  /**
   * Reads one definition into its built schema.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return \PortableSchema\Builder\Schema
   *   The built schema, read afresh: nothing is kept. An entry keeps the
   *   schema of its definition (Entry::schema()).
   */
  public static function of(DataDefinitionInterface $definition): Schema {
    return self::read($definition, '', []);
  }

  /**
   * Reads one definition into its built schema, below the ones above it.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param string $path
   *   Where it is in the schema being read, as a JSON Pointer ('' at the
   *   top).
   * @param array<int, true> $above
   *   The definitions above it, by object id.
   *
   * @return \PortableSchema\Builder\Schema
   *   The built schema.
   *
   * @throws \LogicException
   *   When the definition is one of those above it.
   */
  private static function read(DataDefinitionInterface $definition, string $path, array $above): Schema {
    $id = spl_object_id($definition);
    if (isset($above[$id])) {
      throw new \LogicException(sprintf('The definition of the %s type at "%s" holds itself: it is met again inside itself, so no schema can be read from it.', $definition->getDataType(), $path));
    }
    $above[$id] = TRUE;
    return self::build($definition, $path, $above);
  }

  /**
   * Builds a schema, reading a map's properties and a list's item with read().
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param string $path
   *   Where it is in the schema being read, as a JSON Pointer.
   * @param array<int, true> $above
   *   The definitions above it and itself, by object id.
   * @param array<string, \PortableSchema\Builder\Schema> $given
   *   The schemas of properties already read, by name (around()).
   * @param \PortableSchema\Builder\Schema|null $item
   *   The schema of the item, already read (around()).
   * @param bool $asMap
   *   Whether a complex definition that holds no property is read as a
   *   map all the same (shell()).
   *
   * @return \PortableSchema\Builder\Schema
   *   The built schema.
   */
  private static function build(DataDefinitionInterface $definition, string $path, array $above, array $given = [], ?Schema $item = NULL, bool $asMap = FALSE): Schema {
    $schema = static::describe($definition);
    if ($definition instanceof ListDataDefinitionInterface) {
      $list = [
        'type' => static::nullable('array', $definition),
        'items' => $item ?? static::read($definition->getItemDefinition(), $path . '/items', $above),
      ];
      $schema = Schemas::defaults($schema, $list + static::keywords($definition, 'array'));
    }
    // A map that holds no property is read as a value, unless schemas are
    // given for properties it does not hold (around()), or it is read as
    // a shell (shell()).
    elseif ($definition instanceof ComplexDataDefinitionInterface && (static::isMap($definition) || $given !== [] || $asMap)) {
      $properties = [];
      $required = [];
      foreach ($definition->getPropertyDefinitions() as $name => $property) {
        // A key an alter mounted and a refinement ruled out is read too:
        // the projection leaves it out (applying()).
        $at = $path . '/properties/' . strtr((string) $name, [
          '~' => '~0',
          '/' => '~1',
        ]);
        $properties[(string) $name] = $given[(string) $name] ?? static::read($property, $at, $above);
        if (static::required($property)) {
          $required[] = (string) $name;
        }
      }
      // A schema given for a property the map does not hold (a shell the
      // seal handed its own facts alone, around()) comes after the ones it
      // holds, in the order given, and its own schema says whether it is
      // required (requiredOf(), rule for rule required()).
      $placed = array_diff_key($given, $properties);
      foreach ($placed as $name => $property_schema) {
        $properties[(string) $name] = $property_schema;
      }
      array_push($required, ...static::requiredOf($placed));
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
    $schema = Schemas::defaults($schema, $checks);
    return $schema->with(self::MODEL, static::annotation($definition, $schema, $path, $above));
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
   * Answers whether a built schema is read as a map of properties.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return bool
   *   TRUE where it holds `properties`: what isMap() answers for the
   *   definition it was read from.
   */
  public static function holdsProperties(Schema $schema): bool {
    return !$schema->isBoolean() && is_array($schema->get('properties'));
  }

  /**
   * Gets the schema of a property, below a map's schema.
   *
   * The step a reader takes from a map's definition to one of its
   * properties, taken on the schema: a built map's schema holds a schema for
   * every property its definition has, a marked one included.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   * @param string $name
   *   The property name.
   *
   * @return \PortableSchema\Builder\Schema|null
   *   The property's schema; NULL where the schema holds no such property.
   */
  public static function propertyOf(Schema $schema, string $name): ?Schema {
    $properties = $schema->isBoolean() ? NULL : $schema->get('properties');
    $property = is_array($properties) ? $properties[$name] ?? NULL : NULL;
    return $property instanceof Schema ? $property : NULL;
  }

  /**
   * Gets the schema of a list's item, below the list's schema.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return \PortableSchema\Builder\Schema|null
   *   The item's schema; NULL where the schema holds none (one head() read).
   */
  public static function itemOf(Schema $schema): ?Schema {
    $item = $schema->isBoolean() ? NULL : $schema->get('items');
    return $item instanceof Schema ? $item : NULL;
  }

  /**
   * Answers whether two reads of a definition say the same.
   *
   * What an assertion compares a kept schema with (Entry::schema()). Two
   * reads of a definition nothing wrote hold the same values and the same
   * objects, except the messages the read itself makes (SchemaMessage),
   * which are compared by what they say, never rendered. Any other object
   * is the same only if it is the same object.
   *
   * @param mixed $kept
   *   A schema, or a value inside one.
   * @param mixed $fresh
   *   The other.
   *
   * @return bool
   *   TRUE where nothing differs.
   */
  public static function same(mixed $kept, mixed $fresh): bool {
    if ($kept === $fresh) {
      return TRUE;
    }
    if ($kept instanceof Schema && $fresh instanceof Schema) {
      if ($kept->isBoolean() || $fresh->isBoolean()) {
        return $kept->isBoolean() && $fresh->isBoolean() && $kept->toDocument() === $fresh->toDocument();
      }
      return $kept->isRequired() === $fresh->isRequired() && static::same($kept->all(), $fresh->all());
    }
    if (is_array($kept) && is_array($fresh)) {
      if (array_keys($kept) !== array_keys($fresh)) {
        return FALSE;
      }
      foreach ($kept as $key => $value) {
        if (!static::same($value, $fresh[$key])) {
          return FALSE;
        }
      }
      return TRUE;
    }
    if ($kept instanceof SchemaMessage && $fresh instanceof SchemaMessage) {
      return $kept->getUntranslatedString() === $fresh->getUntranslatedString()
        && static::same($kept->getArguments(), $fresh->getArguments())
        && static::same($kept->getOptions(), $fresh->getOptions());
    }
    return FALSE;
  }

  /**
   * Gets the schema of the shape a schema's definition is declared with.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return \PortableSchema\Builder\Schema|null
   *   The schema of the shape's definition (MODEL's `shape`); NULL where none
   *   is held.
   */
  public static function shapeOf(Schema $schema): ?Schema {
    $shape = static::model($schema)['shape']['schema'] ?? NULL;
    return $shape instanceof Schema ? $shape : NULL;
  }

  /**
   * Answers whether a built schema was read from a list definition.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema (of(), head()).
   *
   * @return bool
   *   TRUE where its class (classOf()) is a list definition.
   */
  public static function isList(Schema $schema): bool {
    return !$schema->isBoolean() && is_a(static::classOf($schema), ListDataDefinitionInterface::class, TRUE);
  }

  /**
   * Answers whether a schema was read from a complex definition.
   *
   * Stands for `instanceof ComplexDataDefinitionInterface`: a map, a map
   * with no properties, and any other complex class (a field item), by
   * the class the schema was read from (classOf()).
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return bool
   *   TRUE where its class is a complex definition.
   */
  public static function isComplex(Schema $schema): bool {
    return !$schema->isBoolean() && is_a(static::classOf($schema), ComplexDataDefinitionInterface::class, TRUE);
  }

  /**
   * Answers whether a built schema was read from one value's definition.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return bool
   *   TRUE where its class is neither complex nor a list: what
   *   LeafTypes::isLeaf() answers for its definition.
   */
  public static function isLeaf(Schema $schema): bool {
    $class = static::classOf($schema);
    return !is_a($class, ComplexDataDefinitionInterface::class, TRUE) && !is_a($class, ListDataDefinitionInterface::class, TRUE);
  }

  /**
   * Answers whether a built schema is an `any` the module holds as one.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return bool
   *   What LeafTypes::heldAsAny() answers for its definition: an `any`
   *   that marks variants (`variantsBy`, a string) or is held whole
   *   (`heldWhole`, set and not NULL).
   */
  public static function heldAsAny(Schema $schema): bool {
    $model = static::model($schema);
    return ($model['type'] ?? NULL) === LeafTypes::ANY && (is_string($model['variantsBy'] ?? NULL) || isset($model['heldWhole']));
  }

  /**
   * Writes a definition's MODEL.
   *
   * The members MEMBERS names, each where the definition states its fact
   * and no keyword of the schema holds it: `type` always, `definitionClass`,
   * for a DataDefinition what its array holds (stored()); for a definition
   * that is no DataDefinition (a FieldConfig) the getters say no more.
   * `constraints` last, and only where SchemaFacts::derived() does not give
   * getConstraints() exactly from the others (an empty list included).
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param \PortableSchema\Builder\Schema $schema
   *   Its schema's keywords, MODEL aside.
   * @param string $path
   *   Where the schema is, as a JSON Pointer.
   * @param array<int, true> $above
   *   The definitions above it and itself, by object id.
   *
   * @return array<string, mixed>
   *   The members, in MEMBERS's order.
   */
  private static function annotation(DataDefinitionInterface $definition, Schema $schema, string $path, array $above): array {
    $type = $definition->getDataType();
    $members = ['type' => $type];
    $class = get_class($definition);
    if ($class !== static::implied($type, $schema->has('properties'))) {
      $members['definitionClass'] = $class;
    }
    if ($definition instanceof DataDefinition) {
      $members += static::stored($definition->toArray(), $type, $schema, $path, $above);
    }
    if ($class === MapDataDefinition::class && $definition instanceof ComplexDataDefinitionInterface && $definition->getMainPropertyName() !== NULL) {
      $members['mainProperty'] = $definition->getMainPropertyName();
    }
    $constraints = [];
    foreach ($definition->getConstraints() as $id => $options) {
      $constraints[(string) $id] = $options;
    }
    // The merged list is derived from the other members wherever they say
    // it exactly; the member holds it only where they do not.
    if (SchemaFacts::derived($members) !== $constraints) {
      $members['constraints'] = $constraints;
    }
    return array_merge(array_intersect_key(array_fill_keys(self::MEMBERS, NULL), $members), $members);
  }

  /**
   * Places each key of a definition array the schema's keywords do not hold.
   *
   * A key whose keyword (PLACES) holds the very value stored is that
   * keyword's; every other key of PLACES is its member, as stored; a
   * shape's, a config type's and `each`'s definition are read into their
   * schemas (nested()); a key PLACES does not name, or a value its member
   * cannot hold, is under `stored`. The order of the keys is not read.
   *
   * @param array $array
   *   The definition array, toArray().
   * @param string $type
   *   The definition's data type.
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema's keywords.
   * @param string $path
   *   Where the schema is, as a JSON Pointer.
   * @param array<int, true> $above
   *   The definitions above it and itself, by object id.
   *
   * @return array<string, mixed>
   *   The members, by name.
   */
  private static function stored(array $array, string $type, Schema $schema, string $path, array $above): array {
    $members = [];
    $unnamed = [];
    foreach ($array as $key => $value) {
      $place = self::PLACES[$key] ?? NULL;
      if ($place === NULL || ($place[0] === 'type' && $value !== $type)) {
        $unnamed[$key] = $value;
        continue;
      }
      [$name, $keyword] = $place;
      if ($name === 'type' || ($keyword !== NULL && $schema->has($keyword) && $schema->get($keyword) === $value)) {
        continue;
      }
      if (!in_array($name, self::NESTED, TRUE)) {
        $members[$name] = $value;
        continue;
      }
      $nested = static::nested($name, $value, $path . '/' . self::MODEL . '/' . $name, $above);
      if ($nested === NULL) {
        $unnamed[$key] = $value;
        continue;
      }
      $members[$name] = $nested;
    }
    if ($unnamed !== []) {
      $members['stored'] = $unnamed;
    }
    return $members;
  }

  /**
   * Reads the definition a shape, a config type or `each` holds.
   *
   * Through read(), so a definition met again inside itself is refused
   * there as anywhere, and the recursion ends where the definitions held
   * end.
   *
   * @param string $member
   *   `shape`, `configType` or `each`.
   * @param mixed $value
   *   What the definition array holds for it.
   * @param string $path
   *   Where the member is, as a JSON Pointer.
   * @param array<int, true> $above
   *   The definitions above it, by object id.
   *
   * @return \PortableSchema\Builder\Schema|array|string|null
   *   `{id, schema}` for a shape, `{type, schema}` for a config type, a schema
   *   or the class for `each`; NULL for a value of another form, which is
   *   kept under `stored`.
   */
  private static function nested(string $member, mixed $value, string $path, array $above): Schema|array|string|null {
    if ($member === 'each') {
      if (is_string($value)) {
        return $value;
      }
      return $value instanceof DataDefinitionInterface ? static::read($value, $path, $above) : NULL;
    }
    $name = $member === 'shape' ? 'id' : 'type';
    if (!is_array($value) || array_keys($value) !== [$name, 'definition'] || !is_string($value[$name]) || !$value['definition'] instanceof DataDefinitionInterface) {
      return NULL;
    }
    return [$name => $value[$name], 'schema' => static::read($value['definition'], $path . '/schema', $above)];
  }

  /**
   * Names the class the inverse makes for a schema that names none.
   *
   * @param string $type
   *   The schema's data type.
   * @param bool $properties
   *   Whether the schema holds `properties`.
   *
   * @return string
   *   ListDataDefinition for a list, MapDataDefinition for a schema with
   *   properties, DataDefinition for any other.
   */
  public static function implied(string $type, bool $properties): string {
    return match (TRUE) {
      $type === 'list' => ListDataDefinition::class,
      $properties => MapDataDefinition::class,
      default => DataDefinition::class,
    };
  }

  /**
   * Names the class of the definition a schema was read from.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return string
   *   Its `definitionClass` member, else the class implied().
   */
  public static function classOf(Schema $schema): string {
    $model = static::model($schema);
    $type = $model['type'] ?? '';
    return $model['definitionClass'] ?? static::implied(is_string($type) ? $type : '', is_array($schema->get('properties')));
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
    if ($definition->getDataType() === 'string' && $definition->getSetting('widget') === 'date') {
      return ['type' => 'string', 'format' => 'date'];
    }
    return LeafTypes::spelled($definition) ?? [];
  }

  /**
   * Names the JSON type, and format, a built schema's data type is.
   *
   * Stands for type(), read from the schema's facts: its data type, its
   * `widget` setting, whether it declares DateOnly. The schema's own `type`
   * keyword says the type with null where the key may be cleared, and its
   * `format` keyword may be a constraint's; a reader that needs the
   * type's row as type() gives it (a cast, a signature) reads it here.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   *
   * @return array
   *   What type() returns for the definition the schema was read from.
   */
  public static function typeOf(Schema $schema): array {
    if (!static::isLeaf($schema)) {
      return [];
    }
    $type = SchemaFacts::dataType($schema);
    if ($type === 'string' && SchemaFacts::setting($schema, 'widget') === 'date') {
      return ['type' => 'string', 'format' => 'date'];
    }
    if ($type === 'datetime_iso8601' && static::declares($schema, 'DateOnly')) {
      return LeafTypes::DATE_ONLY;
    }
    // A type no row is for, and an `any` the module holds as one, have
    // none.
    return LeafTypes::ROWS[$type] ?? [];
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
   * States a built schema's constraints as JSON Schema keywords and checks.
   *
   * Stands for keywords(), read from the schema's `constraints` member,
   * for a type the caller gives: what a reader compares that is not the
   * schema's own keywords, which were stated for the type with null where
   * the key may be cleared (PropertySchema::signature()).
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema.
   * @param string|array|null $type
   *   The JSON type.
   * @param array $given
   *   What the key says already, as keywords() takes it.
   *
   * @return array
   *   What keywords() returns for the definition the schema was read from.
   */
  public static function keywordsOf(Schema $schema, string|array|null $type, array $given = []): array {
    return static::stated(SchemaFacts::constraints($schema), $type, $given);
  }

  /**
   * States constraints as JSON Schema keywords and checks.
   *
   * What keywords() says of a definition's constraints, read from the
   * constraints alone, so a schema's MODEL `constraints` gives the same.
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
   *   The map's schema.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The map.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema, each of them under `portable-check` and its own message
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
   *   The message as held, a source string given as a SchemaMessage; NULL
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
   * @return \Drupal\surface\Contract\SchemaMessage
   *   The message, built with no translation service.
   */
  public static function heldTo(array $schema): SchemaMessage {
    // Schemas held together as an `allOf` alone are named by what each
    // says: the message a frame's several schemas were written with.
    $together = array_keys($schema) === ['allOf'] && is_array($schema['allOf']) && array_is_list($schema['allOf']) && array_filter($schema['allOf'], 'is_array') === $schema['allOf'];
    $keywords = $together ? array_values(array_unique(array_merge(...array_map(JsonSchemaConstraint::keywords(...), $schema['allOf'])))) : JsonSchemaConstraint::keywords($schema);
    return new SchemaMessage('Checked on the server against the schema it was read from: @keywords.', ['@keywords' => implode(', ', $keywords)]);
  }

  /**
   * Writes a message the definition holds as a source string.
   *
   * @param string $string
   *   The source string.
   *
   * @return \Drupal\surface\Contract\SchemaMessage
   *   The message, translated each time it is rendered, built with no
   *   translation service.
   */
  private static function text(string $string): SchemaMessage {
    // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
    return new SchemaMessage($string);
  }

  /**
   * Reads a schema's MODEL.
   *
   * @param \PortableSchema\Builder\Schema|null $schema
   *   A built schema, or NULL.
   *
   * @return array<string, mixed>
   *   The members (MEMBERS) by name; empty for no schema, or a schema no
   *   definition was read into.
   */
  public static function model(?Schema $schema): array {
    $model = $schema?->get(self::MODEL);
    return is_array($model) ? $model : [];
  }

  /**
   * Answers whether a built schema's definition declares a constraint.
   *
   * @param \PortableSchema\Builder\Schema|null $schema
   *   The built schema.
   * @param string $id
   *   The constraint's plugin id.
   *
   * @return bool
   *   TRUE where it does.
   */
  public static function declares(?Schema $schema, string $id): bool {
    return $schema !== NULL && array_key_exists($id, SchemaFacts::constraints($schema));
  }

  /**
   * Reads the options a built schema's constraint runs with.
   *
   * A check's own options, under `portable-check`; a constraint a keyword
   * states, or one no check names, from the declared list.
   *
   * @param \PortableSchema\Builder\Schema|null $schema
   *   The built schema.
   * @param string $id
   *   The constraint's plugin id.
   *
   * @return mixed
   *   Its options as the definition holds them: an array, NULL or a
   *   scalar; NULL where it declares none.
   */
  public static function options(?Schema $schema, string $id): mixed {
    $checks = $schema?->get(PortableKeywords::CHECK);
    $name = PortableKeywords::PROVIDER . $id;
    if (is_array($checks) && array_key_exists($name, $checks)) {
      return $checks[$name];
    }
    return $schema === NULL ? NULL : SchemaFacts::constraints($schema)[$id] ?? NULL;
  }

  /**
   * Answers whether a key offers a list of values.
   *
   * @param \PortableSchema\Builder\Schema|\Drupal\Core\TypedData\DataDefinitionInterface $key
   *   The key's schema, which the options service is handed, of its item
   *   for a list; or its definition, where the caller holds no schema.
   *
   * @return bool
   *   TRUE where the options service resolves a set for it, of its item
   *   for a list.
   */
  public function listed(Schema|DataDefinitionInterface $key): bool {
    if ($key instanceof Schema) {
      $item = static::isList($key) ? static::itemOf($key) : NULL;
      return $this->options->resolve($item ?? $key) !== NULL;
    }
    return $this->options->resolve($key instanceof ListDataDefinitionInterface ? $key->getItemDefinition() : $key) !== NULL;
  }

  /**
   * Projects a built schema, and every schema inside it, to the document form.
   *
   * Each schema as publishedSchema() projects it: a list's item and a map's
   * properties too, each offering a list of values or not as the options
   * service says. A schema at any other keyword position is no key's: MODEL
   * is stripped from it, at every depth, and nothing else.
   *
   * @param \PortableSchema\Builder\Schema $built
   *   A built schema (of(), head()).
   * @param bool|null $listed
   *   Whether the key offers a list of values; NULL to ask the options
   *   service.
   *
   * @return \PortableSchema\Builder\Schema
   *   What a document says of it, at every depth.
   */
  public function published(Schema $built, ?bool $listed = NULL): Schema {
    return Schemas::map($this->projected($built, $listed), static fn (Schema $schema): Schema => $schema->has(self::MODEL) ? $schema->without(self::MODEL) : $schema);
  }

  /**
   * Projects a schema, its item and its properties.
   *
   * @param \PortableSchema\Builder\Schema $built
   *   A built schema.
   * @param bool|null $listed
   *   Whether the key offers a list of values; NULL to ask the options
   *   service.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema, each item and property projected (publishedSchema()).
   */
  private function projected(Schema $built, ?bool $listed = NULL): Schema {
    $schema = $this->publishedSchema($built, $listed);
    if (static::isList($built)) {
      $item = $schema->get('items');
      return $item instanceof Schema ? $schema->with('items', $this->projected($item)) : $schema;
    }
    $properties = $schema->get('properties');
    if (!is_array($properties) || !static::holdsProperties($built)) {
      return $schema;
    }
    foreach ($properties as $name => $property) {
      if ($property instanceof Schema) {
        $properties[$name] = $this->projected($property);
      }
    }
    return $schema->with('properties', $properties);
  }

  /**
   * Projects one built schema, and not the schemas inside it.
   *
   * What the class says the projection holds: a map that offers a list of
   * values said as one value (oneValue()), a property that does not
   * apply left out with its place in `required` (applying()), a value of a
   * type no row is for refused, the checks a list states left out and a
   * check's sentence given (publishedChecks()), the title and description
   * as plain text (publishedTexts()), MODEL stripped. Every keyword keeps
   * its place.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The built schema (of(), head()).
   * @param bool|null $listed
   *   Whether the key offers a list of values; NULL to ask the options
   *   service, which is handed the schema.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema as a document says it.
   *
   * @throws \LogicException
   *   For a value of a type no row is for (LeafTypes::unwritten()), whose
   *   type is named by MODEL's `type` alone.
   */
  public function publishedSchema(Schema $schema, ?bool $listed = NULL): Schema {
    if ($schema->isBoolean()) {
      return $schema;
    }
    // What the definition is (a map, a list, one value) is read from the
    // schema it was read into, before a listed map is said as one value.
    $read = $schema;
    $map = static::holdsProperties($read);
    if ($map) {
      $listed ??= $this->listed($read);
    }
    if ($map && $listed) {
      $schema = static::oneValue($schema);
      $map = FALSE;
    }
    elseif ($map) {
      $schema = static::applying($schema);
    }
    $value = !$map && !static::isList($read);
    if ($value && static::isLeaf($read) && !$schema->has('type') && !static::heldAsAny($read)) {
      $model = static::model($read);
      $label = $read->has('title') ? $read->get('title') : ($model['label'] ?? NULL);
      throw LeafTypes::unwritten(is_string($label) || $label instanceof \Stringable ? $label : NULL, is_string($model['type'] ?? NULL) ? $model['type'] : '');
    }
    // A list's own checks and a map's are never stated by a list of its
    // values, so the options service is asked only for a value's.
    $listed = $value && ($listed ?? $this->listed($read));
    $schema = $this->publishedChecks(static::publishedTexts($schema), $map, $listed);
    return $schema->has(self::MODEL) ? $schema->without(self::MODEL) : $schema;
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
   * Says a map's built schema as one value.
   *
   * What a map that offers a list of values is said as: a value with a
   * `oneOf`, so its own constraints are stated as a value's are, and its
   * properties, `type`, `required` and `additionalProperties` are no part
   * of it. Made from the schema alone: its title and description, and its
   * merged constraints (SchemaFacts::constraints()) read as keywords() reads
   * a value's constraints.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The map's built schema (of()).
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema of one value, MODEL kept.
   */
  private static function oneValue(Schema $schema): Schema {
    $value = Schema::any();
    foreach (['title', 'description'] as $text) {
      if ($schema->has($text)) {
        $value = $value->with($text, $schema->get($text));
      }
    }
    $value = Schemas::defaults($value, static::stated(SchemaFacts::constraints($schema), NULL));
    // What only the server checks, and the messages, come last, as the
    // read places them (build()).
    $checks = array_intersect_key($value->all(), array_flip([PortableKeywords::CHECK, PortableKeywords::MESSAGES]));
    foreach (array_keys($checks) as $keyword) {
      $value = $value->without($keyword);
    }
    if ($schema->has('writeOnly')) {
      $value = $value->with('writeOnly', $schema->get('writeOnly'));
    }
    $value = Schemas::defaults($value, $checks);
    return $schema->has(self::MODEL) ? $value->with(self::MODEL, $schema->get(self::MODEL)) : $value;
  }

  /**
   * Leaves out the properties of a map that do not apply.
   *
   * A key an alter mounted and a refinement ruled out is on the built schema
   * and in its `required`; the document says neither, as at the top of a
   * frame. Every key ruled out leaves an object still.
   *
   * Decision: see docs/decisions.md#a-key-that-does-not-apply.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The map's built schema, whose properties' schemas hold the mark in their
   *   `doesNotApply` member.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema, without them.
   */
  private static function applying(Schema $schema): Schema {
    $properties = $schema->get('properties');
    if (!is_array($properties)) {
      return $schema;
    }
    $out = [];
    foreach ($properties as $name => $property) {
      // DoesNotApply::marks(): a mark that is set and not empty.
      if ($property instanceof Schema && !empty(static::model($property)['doesNotApply'])) {
        $out[(string) $name] = TRUE;
      }
    }
    if ($out === []) {
      return $schema;
    }
    $schema = $schema->with('properties', array_diff_key($properties, $out));
    $required = $schema->get('required');
    if (!is_array($required)) {
      return $schema;
    }
    $required = array_values(array_filter($required, static fn ($name): bool => !isset($out[(string) $name])));
    return $required === [] ? $schema->without('required') : $schema->with('required', $required);
  }

  /**
   * Projects a schema's title and description, and not the schemas inside it.
   *
   * Each rendered as plain text; one that renders empty is left out.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema (of(), head(), describe()).
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema, its texts as a document says them.
   */
  public static function publishedTexts(Schema $schema): Schema {
    if ($schema->isBoolean()) {
      return $schema;
    }
    foreach (['title', 'description'] as $text) {
      $held = $schema->get($text);
      if (is_string($held) || $held instanceof \Stringable) {
        $rendered = (string) $held;
        $schema = $rendered === '' ? $schema->without($text) : $schema->with($text, PlainText::of($rendered));
      }
    }
    return $schema;
  }

  /**
   * Projects a schema's checks and messages.
   *
   * Each check's options as CheckOptions::published() says them, but for a
   * check a list states (statedByList()), which is left out with its
   * message. Each message as plain text, a check's own or its sentence
   * (sentence()), one empty as plain text left out. The messages of
   * keywords come first, then each check's, in the checks' order.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   * @param bool $map
   *   Whether these are a map's own checks, which no list states.
   * @param bool $listed
   *   Whether the key, a value, offers a list of values.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema, its checks and messages as a document says them.
   */
  private function publishedChecks(Schema $schema, bool $map, bool $listed): Schema {
    $checks = $schema->get(PortableKeywords::CHECK);
    $held = $schema->get(PortableKeywords::MESSAGES);
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
    $format = $schema->get('format');
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
    if ($schema->has(PortableKeywords::CHECK)) {
      $schema = $published === [] ? $schema->without(PortableKeywords::CHECK) : $schema->with(PortableKeywords::CHECK, $published);
    }
    if ($said === []) {
      return $schema->has(PortableKeywords::MESSAGES) ? $schema->without(PortableKeywords::MESSAGES) : $schema;
    }
    if ($schema->has(PortableKeywords::MESSAGES)) {
      return $schema->with(PortableKeywords::MESSAGES, $said);
    }
    // The messages stand right after the checks, where the read writes
    // them: MODEL, which follows the checks on a built schema, goes first.
    $schema = $schema->has(self::MODEL) ? $schema->without(self::MODEL) : $schema;
    return $schema->with(PortableKeywords::MESSAGES, $said);
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
   *   The message the schema holds for it, or NULL.
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
