<?php

declare(strict_types=1);

namespace Drupal\data_surface\Contract;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\Core\Validation\ConstraintManager;
use Drupal\data_surface\DataSurface;
use Drupal\data_surface\Computed;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMap;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Plugin\DataSurfaceWidget\AutocompleteWidget;
use Drupal\data_surface\Plugin\DataSurfaceWidget\CheckboxesWidget;
use Drupal\data_surface\Plugin\Validation\Constraint\JsonSchemaConstraint;
use Drupal\data_surface\Plugin\DataSurfaceWidget\OptionsWidget;
use Drupal\data_surface\Plugin\DataSurfaceWidget\RadiosWidget;
use Drupal\data_surface\Plugin\DataSurfaceWidget\TimeWidget;
use Drupal\data_surface\Form\DataSurfaceFormBuilderInterface;
use Drupal\data_surface\Options\DataSurfaceOptions;
use Drupal\data_surface\Options\OptionSet;
use Drupal\data_surface\Pipeline\ValueState;
use Drupal\data_surface\Reference\EntityReferenceStructure;
use Drupal\data_surface\Reference\References;
use Drupal\data_surface\Resource\Arrangement;
use Drupal\data_surface\Refinement\DeclaredRefiner;
use Drupal\data_surface\Refinement\DoesNotApply;
use Drupal\data_surface\Refinement\Rule;
use Drupal\data_surface\Attachment;
use Drupal\data_surface\Port;
use Drupal\data_surface\ResourceBuild\RefinesInputRefiner;
use Drupal\data_surface\Entry;
use Drupal\data_surface\Collection;
use Drupal\data_surface\Pipeline\ResultDocument;

/**
 * Turns a sealed data surface and its values into the served contract.
 *
 * The contract is JSON Schema 2020-12 for the inputs, with one extension
 * keyword, `x-surface`, on every property for what JSON Schema has no
 * word for, plus the values the schema describes right now. It is the
 * module's canonical contract, and its own emitter rather than the Tool
 * API's, so it can say what a Tool API context definition cannot carry:
 *
 * - allowed values as `oneOf: [{const, title}]`, labels included, read
 *   from the options service: the same list a generated select renders
 *   and the pipeline validates against;
 * - variants as real conditionals on their parent: per variant, one `if`
 *   naming the deciding key's `const` and one `then` naming its shape,
 *   so the union is stated where JSON Schema can state it;
 * - per key, what the data surface knows about it beyond its shape
 *   (`x-surface`): whether it is locked, which siblings its refiners
 *   watch, whether it is narrowed right now, whether a select shows its
 *   empty option and under what label, and whether the value it holds
 *   has gone stale;
 * - where a key's static refiners have nothing to refine against yet,
 *   what they would answer for every value their watched siblings could
 *   take, as conditionals on the parent beside the variants' (enumerate()),
 *   so a static contract is exact wherever the answers are finite; where
 *   they are not, that the key is `refine`, to be asked again with
 *   values; and where a runtime refiner narrows it too, that the key is
 *   `live`, which no schema states ahead of time.
 *
 * Everything is read from the data surface: the declared shape for what
 * it advertises, the same data surface refined against the values for
 * what it allows now, each child in its own frame. Nothing names a
 * resource, a structure or a key, and nothing here reaches storage: the
 * values are handed in.
 *
 * Each child is a reusable subschema. The wall between a parent and
 * its child (the parent cannot refine into the child, the child cannot
 * watch the parent, the child keeps its own refiners and alters) makes a
 * child self-contained, so it is written once under the root's `$defs`,
 * keyed by its own id, as its own frame declares it for no values
 * (define()). Each place it is used, an attached key or a variant's
 * conditional, refers to it, beside what that place says about it: its
 * label, its `x-surface`. Where the child here says more than its def (a
 * key its context locked, a key refined for the values in this
 * document), the reference is composed with only what differs, as
 * `allOf: [{$ref}, {narrowing}]` (reference()): refinement only narrows,
 * so that is refinement in the schema's own grammar.
 * Decision: see docs/decisions.md#the-wall-is-the-reference-boundary.
 *
 * There are two documents, written by the one code path below so they
 * cannot drift apart:
 *
 * - emit(): the schema as it stands, for one set of values. A key whose
 *   watched siblings hold values is refined for them, `x-surface` says
 *   what is true right now, and `values` and `stale` sit beside the
 *   schema. It is what a form is drawn from, and it describes those
 *   values only.
 * - emitFull(): the full schema, for any values. It reads no value at
 *   all: every key is the one the data surface declares, every dependency
 *   that can be listed is stated for every value it could be handed, and
 *   one that cannot is marked `refine`. It is what a validator, or
 *   anything that takes the schema once, holds values to.
 *
 * Neither is ever stricter than the pipeline: a rule JSON Schema cannot
 * state, or a dependency too large to list, is left out and named, never
 * approximated by something narrower.
 * Decision: see
 * docs/decisions.md#two-documents-never-stricter-than-the-runtime.
 *
 * The contract carries no widget vocabulary unless a renderer asks for
 * it (emit()'s $widgets). Then `x-surface.widget` mirrors
 * DataSurfaceWidgetManager's selection in its weight order, so a
 * renderer such as data_surface_react's app draws what the operation
 * form would: a non-empty option list is a select (a list of them, a
 * multiple select, said by `multiple`); a map with properties is a
 * fieldset; a string is a textarea when its definition says multiline,
 * a time input when it asks for one, an email input for the email type,
 * a text input otherwise; a number is a number input; a boolean a
 * checkbox. A list of values is checkboxes or a multiple select, and one
 * value radios when it asks for them, by the widgets' own rule
 * (CheckboxesWidget::drawsList(), RadiosWidget::drawsOne()); a list with
 * no option list is `list`, rows to add and remove. One hint has no
 * Form API counterpart here: `variants`, for a key with variants, whose
 * shape a sibling chooses.
 *
 * Both documents are written in one version of the wire format, the
 * contract's own (CONTRACT, stated as `x-surface.contract`), distinct
 * from a resource's version. In contract 2 no `x-surface` sits below an
 * applicator (`allOf`, `anyOf`, `oneOf`, `if`, `then`, `else`, `not`), so
 * a tool that merges `allOf` meets plain JSON Schema there: the object
 * owning the applicator says which of its conditionals are declared
 * rules (`x-surface.rules`) and what a narrowing says of a key inside it
 * (`x-surface.within`, by JSON pointer), and every narrowing states the
 * declared `type` beside a keyword only one type reads (settled()).
 * Decision: see docs/decisions.md#contract-2.
 */
final class ContractEmitter {

  use StringTranslationTrait;

  /**
   * The dialect every schema this emitter writes declares.
   */
  public const DIALECT = 'https://json-schema.org/draft/2020-12/schema';

  /**
   * The extension keyword carrying what JSON Schema cannot say.
   */
  public const EXTENSION = 'x-surface';

  /**
   * The version of the wire format both documents are written in.
   *
   * Distinct from a resource's version, which counts breaking changes to
   * its stored values: this counts the changes to how every document is
   * spelled. Stated as `x-surface.contract` at the top of both documents
   * and answered as the `Contract-Version` header by the routes serving
   * them. Contract 1 is what the module served until 2026-10-20, contract
   * 2 until 2026-10-22; contract 3 changed the HTTP door (the operation is
   * the method) and spells every path on the wire as a JSON Pointer, and
   * left both documents as contract 2 wrote them.
   * Decision: see docs/decisions.md#contract-3-the-operation-is-the-method.
   */
  public const CONTRACT = 3;

  /**
   * The keywords whose subschemas carry no `x-surface` in contract 2.
   */
  protected const APPLICATORS = ['allOf', 'anyOf', 'oneOf', 'if', 'then', 'else', 'not'];

  /**
   * The keywords a schema holds data under, never a subschema.
   */
  protected const DATA = ['const', 'default', 'examples', 'enum', self::EXTENSION];

  /**
   * The JSON type each keyword only one type reads is about.
   *
   * For what a conditional or a site narrows: ajv's strict mode refuses
   * one of these where nothing at that place in the instance states a
   * type, and a type generator reads it as the type's; a narrowing states
   * the declared type beside it.
   */
  protected const TYPED = [
    'minimum' => 'number',
    'maximum' => 'number',
    'exclusiveMinimum' => 'number',
    'exclusiveMaximum' => 'number',
    'multipleOf' => 'number',
    'minLength' => 'string',
    'maxLength' => 'string',
    'pattern' => 'string',
    'properties' => 'object',
    'required' => 'object',
    'additionalProperties' => 'object',
    'unevaluatedProperties' => 'object',
    'minProperties' => 'object',
    'maxProperties' => 'object',
    'items' => 'array',
    'minItems' => 'array',
    'maxItems' => 'array',
    'uniqueItems' => 'array',
  ];

  /**
   * The most conditionals one key is enumerated into.
   *
   * Counted per key, over the combinations of its watched siblings'
   * values: above it the key is `refine` instead.
   *
   * Decision: see docs/decisions.md#enumeration-is-capped.
   */
  public const ENUMERATION_CAP = 64;

  /**
   * The `x-surface` keywords only true of one moment, for one set of values.
   *
   * The full schema leaves them out (withoutPresent()).
   */
  protected const PRESENT = ['refined', 'stale', 'emptyOption', 'chosen'];

  /**
   * The `x-surface` keywords that are widget hints, written when asked for.
   */
  protected const WIDGET_HINTS = ['widget', 'multiple', 'arrangement'];

  /**
   * Where every subschema a document defines is, as a JSON pointer.
   */
  public const DEFS = '#/$defs/';

  /**
   * The keyword a key declared with a shape carries until it refers to it.
   *
   * Each such key is written whole by property(), and beside it the
   * shape's id and its def; named() then files the def once under `$defs`
   * and turns the key into a reference to it with what the key says
   * differently. Never in a document.
   */
  protected const SHAPED = self::EXTENSION . '-shape';

  /**
   * Constraints the schema states in a keyword of its own, or not at all.
   *
   * NotNull is `required`; PrimitiveType, ComplexData and ValidReference
   * are the data type's own, which `type` states; the three list
   * constraints are `oneOf`. Anything not here and not stated by a
   * keyword is named under `x-surface.checkedOnServer`, so a client knows
   * the schema is not the whole story for that key, unless the resolver
   * reading it says its list is the whole of it, which is its `oneOf`
   * (DataSurfaceOptions::isStatedByList()).
   */
  protected const STATED = [
    'NotNull',
    'PrimitiveType',
    'ComplexData',
    'ValidReference',
    'Choice',
    'LabeledChoice',
    'AllowedValues',
    'Range',
    'Length',
    'Count',
    'Email',
    'NotBlank',
  ];

  /**
   * The surfaces refined during the document being written, by what refined.
   *
   * Keyed by the data surface refined, then by the kinds and the values it
   * was refined against; NULL outside emit() and emitFull().
   *
   * @var \WeakMap<\Drupal\data_surface\DataSurfaceInterface, array<string, \Drupal\data_surface\DataSurfaceInterface>>|null
   */
  protected ?\WeakMap $refined = NULL;

  /**
   * How many combinations were refined, and how many were remembered.
   *
   * @var array{refined: int, reused: int}
   */
  protected array $refineCounts = ['refined' => 0, 'reused' => 0];

  /**
   * Constructs a ContractEmitter.
   *
   * @param \Drupal\data_surface\Options\DataSurfaceOptions $options
   *   The options service, the one place a constraint is read as a list.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service, for the empty option's labels.
   * @param \Drupal\Core\Validation\ConstraintManager|null $constraints
   *   The constraint plugin manager, for what a constraint only the server
   *   checks says it checks (its label); without it, its id.
   */
  public function __construct(
    protected readonly DataSurfaceOptions $options,
    TranslationInterface $string_translation,
    protected readonly ?ConstraintManager $constraints = NULL,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Emits the contract a data surface serves for a set of values.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface as built in its context, not refined: what it
   *   advertises.
   * @param array $values
   *   The values to describe it for, keyed by key: what the storage holds
   *   over the defaults, with any in-progress answers over that. A key
   *   not given shows its default. A refinement overlay
   *   (DataSurfaceFormBuilderInterface::refinementOverlay()) is read the
   *   way buildSurfaceForm() reads it: the data surface is refined against
   *   the keys as they stand, each orphan held unanswered, and each orphan
   *   named under STANDING_KEY is shown stale, standing for its stored
   *   value, which is not handed over.
   * @param string $resource_id
   *   The `#[Resource]` or `#[Structure]` id it was built from.
   * @param string|null $operation_id
   *   The operation id, or NULL for a data surface asked for in no
   *   operation.
   * @param string|\Stringable|null $label
   *   The operation's label, which titles the schema.
   * @param bool $widgets
   *   Whether to add the renderer's widget hints, `x-surface.widget` and,
   *   on a list of allowed values, `x-surface.multiple`. Off by default:
   *   the contract itself names no widget, and a renderer that draws
   *   from it, as data_surface_react does, asks for them.
   * @param array $operation
   *   How the operation behaves (OperationDefinition::facts()), stated at
   *   the top of the schema as `x-surface.operation`; empty for a data
   *   surface asked for in no operation, which states none. Its
   *   description, when it has one, is also the top's `description`.
   * @param string|null $id
   *   The URI the document is served at, written as its `$id` so its
   *   local references resolve wherever it is embedded; NULL for a
   *   document no route serves (the contract panel, a baseline), which
   *   has none.
   *
   * @return \Drupal\data_surface\Contract\ServedContract
   *   The contract, and what it depends on.
   */
  public function emit(DataSurfaceInterface $surface, array $values, string $resource_id, ?string $operation_id = NULL, string|\Stringable|null $label = NULL, bool $widgets = FALSE, array $operation = [], ?string $id = NULL): ServedContract {
    // Refined surfaces are remembered for this one document.
    // Decision: see docs/decisions.md#what-is-paid-once-and-what-per-request.
    $was = $this->refined;
    $this->refined = new \WeakMap();
    try {
      $cacheability = new CacheableMetadata();
      $stale = [];
      $standing = $values[DataSurfaceFormBuilderInterface::STANDING_KEY] ?? [];
      unset($values[DataSurfaceFormBuilderInterface::STANDING_KEY]);
      $subschemas = new Subschemas(FALSE, $widgets ? [] : self::WIDGET_HINTS, $this->mixedIn($surface));
      [$schema, $shown] = $this->frame($surface, $values, '', $resource_id, $cacheability, $stale, $subschemas, is_array($standing) ? $standing : []);
      $schema += $this->definitions($subschemas);
      $schema = $this->named($schema);
      if (!$widgets) {
        $schema = $this->withoutWidgets($schema);
      }
      $document = [
        'resource' => $resource_id,
        'operation' => $operation_id,
        'label' => $label === NULL ? NULL : PlainText::of($label),
        'schema' => $this->top($surface, $schema, $label, $operation, $id),
        'values' => $shown,
        // Each a JSON Pointer, the one spelling a path has on the wire.
        // Decision: see docs/decisions.md#one-result-at-every-door.
        'stale' => array_map(ResultDocument::pointer(...), $stale),
      ];
      $outputs = $this->outputs($surface, $cacheability);
      if ($outputs !== NULL) {
        $document['outputs'] = $outputs;
      }
      return new ServedContract($document, $cacheability);
    }
    finally {
      $this->refined = $was;
    }
  }

  /**
   * Emits the full schema a data surface states, for any values.
   *
   * The second document. It is handed no values and reads none, so it is
   * the same whatever is stored and whatever is being typed:
   *
   * - every key is described as the data surface declares it, with its
   *   declared default; nothing is refined, except against the locked
   *   keys the operation itself knows, which no value can change;
   * - a key whose refiners watch siblings that each offer a list of
   *   values is `enumerated`: one conditional per combination of those
   *   values on the parent, always, because no sibling holds anything
   *   here to be held at;
   * - a key whose answers cannot be listed keeps its declared schema and
   *   is `refine`: wider than what the pipeline will allow for some
   *   values, never narrower, and to be asked about with values;
   * - variants are their conditionals, and every child's frame is described
   *   by the same rule, from nothing, not from the child's defaults;
   * - a runtime refiner contributes nothing but `live: true` on the key
   *   it narrows: it is never run here, so the key keeps the width its
   *   static refiners give it, wider than the pipeline for some values;
   * - access is the account's: emitted from a data surface built for the
   *   account alone (ResourcesInterface::buildStatic()), a key a static
   *   answer locked is `readOnly` and `x-surface.locked`, one it left out
   *   is not there, and one a runtime answer may still lock or leave out
   *   is there, as the static answers leave it, marked
   *   `x-surface.access: "live"`; the schema for these answers settles
   *   it;
   * - `x-surface` carries only what holds for any values: `locked`,
   *   `access`, `dependsOn`, `enumerated`, `refine`, `live`, the variants'
   *   `by` and `variants`, `group`, `after`, `patternMessage`,
   *   `checkedOnServer`, and the widget hints when asked for. What is
   *   only true of a moment (`refined`, `stale`, `emptyOption`, the
   *   variants' `chosen`) is not written, and there is no `values` and no
   *   `stale` beside the schema.
   *
   * Decision: see
   * docs/decisions.md#two-documents-never-stricter-than-the-runtime.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface as built in its context, not refined: for the
   *   account alone (buildStatic()), so access says what the account
   *   decides and marks the rest.
   * @param string $resource_id
   *   The `#[Resource]` or `#[Structure]` id it was built from.
   * @param string|null $operation_id
   *   The operation id, or NULL for a data surface asked for in no
   *   operation.
   * @param string|\Stringable|null $label
   *   The operation's label, which titles the schema.
   * @param bool $widgets
   *   Whether to add the renderer's widget hints, as for emit().
   * @param array $operation
   *   How the operation behaves, as for emit().
   * @param string|null $id
   *   The URI the document is served at, as for emit().
   *
   * @return \Drupal\data_surface\Contract\ServedContract
   *   The document (`resource`, `operation`, `label`, `schema` and, when
   *   the data surface declares outputs, `outputs`), and what it depends
   *   on: the data surface and every option list read, never a stored
   *   value.
   */
  public function emitFull(DataSurfaceInterface $surface, string $resource_id, ?string $operation_id = NULL, string|\Stringable|null $label = NULL, bool $widgets = FALSE, array $operation = [], ?string $id = NULL): ServedContract {
    // Refined surfaces are remembered for this one document.
    // Decision: see docs/decisions.md#what-is-paid-once-and-what-per-request.
    $was = $this->refined;
    $this->refined = new \WeakMap();
    try {
      $cacheability = new CacheableMetadata();
      $ignored = [];
      $subschemas = new Subschemas(TRUE, [...self::PRESENT, ...($widgets ? [] : self::WIDGET_HINTS)], $this->mixedIn($surface));
      [$schema] = $this->frame($surface, $this->known($surface), '', $resource_id, $cacheability, $ignored, $subschemas);
      $schema += $this->definitions($subschemas);
      $schema = $this->named($schema);
      $schema = $this->withoutPresent($schema);
      if (!$widgets) {
        $schema = $this->withoutWidgets($schema);
      }
      $document = [
        'resource' => $resource_id,
        'operation' => $operation_id,
        'label' => $label === NULL ? NULL : PlainText::of($label),
        'schema' => $this->top($surface, $schema, $label, $operation, $id),
      ];
      $outputs = $this->outputs($surface, $cacheability);
      if ($outputs !== NULL) {
        $document['outputs'] = $outputs;
      }
      return new ServedContract($document, $cacheability);
    }
    finally {
      $this->refined = $was;
    }
  }

  /**
   * Writes the top of a document's schema around its top frame.
   *
   * `$schema`, `$id` when it is served at one, the title and description,
   * then `x-surface` led by the contract's version, how the operation
   * behaves and the resource's version, and the frame; laid out by the
   * placement rules of the contract's version (settled()).
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface.
   * @param array $schema
   *   The top frame, its `$defs` written.
   * @param string|\Stringable|null $label
   *   The operation's label.
   * @param array $operation
   *   How the operation behaves, or empty.
   * @param string|null $id
   *   The URI the document is served at, or NULL.
   *
   * @return array
   *   The schema.
   */
  protected function top(DataSurfaceInterface $surface, array $schema, string|\Stringable|null $label, array $operation, ?string $id): array {
    $top = ['$schema' => self::DIALECT];
    if ($id !== NULL) {
      $top['$id'] = $id;
    }
    $schema = $this->lockedWhole($surface, $this->operation($operation, $this->versioned($surface, $schema)));
    $schema[self::EXTENSION] = ['contract' => self::CONTRACT] + $schema[self::EXTENSION];
    if (is_array($schema['$defs'] ?? NULL)) {
      // Each def titled by its id: stable, language-free, and unique in
      // the document, so a type generator names it the same every time.
      // A site gives the key its label beside the reference, as before.
      foreach ($schema['$defs'] as $key => $def) {
        unset($def['title']);
        $schema['$defs'][$key] = ['title' => (string) $key] + $def;
      }
    }
    return $this->settled($top + $this->heading($label, $operation) + $schema);
  }

  /**
   * Lays a document's schema out by the placement rules of contract 2.
   *
   * Written by the one pass that describes every key, the schema carries
   * its markers where that pass reads them; moved here once, at the end:
   *
   * - no `x-surface` below an applicator: a declared rule's conditional
   *   is listed by its index in the owning object's `x-surface.rules`, and
   *   anything else said below one (what a site's narrowing says of a key
   *   inside the child) is said on the owning object, in
   *   `x-surface.within`, by its JSON pointer from that object;
   * - a schema below an applicator that states a keyword only one type
   *   reads, where nothing at that place in the instance states a type
   *   yet, states the declared type beside it, which cannot narrow.
   *
   * The owning object is the nearest schema that is not itself below an
   * applicator: a frame, a property, a def. A reader that wants each
   * marker back where the pass wrote it reads SchemaResolver::inline().
   *
   * Decision: see docs/decisions.md#contract-2.
   *
   * @param array $schema
   *   The document's schema.
   *
   * @return array
   *   The same, laid out.
   */
  protected function settled(array $schema): array {
    return $this->owning($schema, $schema);
  }

  /**
   * Lays out one schema that is not below an applicator, and inside it.
   *
   * @param array $node
   *   The schema.
   * @param array $root
   *   The document's root, which holds `$defs`.
   *
   * @return array
   *   The schema, its applicators carrying no `x-surface`.
   */
  protected function owning(array $node, array $root): array {
    $within = [];
    $rules = [];
    $known = isset($node['type']);
    foreach ($node as $keyword => $value) {
      if (!is_array($value) || in_array($keyword, self::DATA, TRUE)) {
        continue;
      }
      if (in_array($keyword, self::APPLICATORS, TRUE)) {
        if (!array_is_list($value)) {
          $node[$keyword] = $this->below($value, '/' . $keyword, $known, [$node], $root, $within);
          continue;
        }
        foreach ($value as $i => $member) {
          if (!is_array($member)) {
            continue;
          }
          if ($keyword === 'allOf' && ($member[self::EXTENSION]['declared'] ?? FALSE) === TRUE) {
            // A declared rule's conditional, stated as written: a client
            // evaluates it whatever the keys it reads hold.
            $rules[] = $i;
            unset($member[self::EXTENSION]['declared']);
            if ($member[self::EXTENSION] === []) {
              unset($member[self::EXTENSION]);
            }
          }
          $node[$keyword][$i] = $this->below($member, '/' . $keyword . '/' . $i, $known, [$node], $root, $within);
        }
        continue;
      }
      if (in_array($keyword, ['properties', '$defs', 'patternProperties'], TRUE)) {
        foreach ($value as $name => $child) {
          if (is_array($child)) {
            $node[$keyword][$name] = $this->owning($child, $root);
          }
        }
        continue;
      }
      if (in_array($keyword, ['items', 'additionalProperties', 'unevaluatedProperties', 'contains', 'propertyNames'], TRUE)) {
        $node[$keyword] = $this->owning($value, $root);
      }
    }
    if ($rules !== []) {
      $node[self::EXTENSION]['rules'] = $rules;
    }
    if ($within !== []) {
      $node[self::EXTENSION]['within'] = $within;
    }
    return $node;
  }

  /**
   * Lays out a schema below an applicator, and everything inside it.
   *
   * @param array $node
   *   The schema.
   * @param string $pointer
   *   Its JSON pointer from the owning object.
   * @param bool $known
   *   Whether a type is stated at this place in the instance already, by
   *   a schema around it at the same place.
   * @param array[] $sources
   *   The schemas declaring this place in the instance, nearest first,
   *   to read its declared type from.
   * @param array $root
   *   The document's root.
   * @param array $within
   *   Collects what is said below the owning object, by pointer.
   *
   * @return array|true
   *   The schema, with no `x-surface` and every type it needs; TRUE where
   *   it said nothing but its `x-surface`, which is still a schema, and
   *   one every JSON reader reads back as itself.
   */
  protected function below(array $node, string $pointer, bool $known, array $sources, array $root, array &$within): array|bool {
    if (isset($node[self::EXTENSION])) {
      $within[$pointer] = $node[self::EXTENSION];
      unset($node[self::EXTENSION]);
    }
    $sources = [$node, ...$sources];
    $here = $known || isset($node['type']);
    foreach ($node as $keyword => $value) {
      if (!is_array($value) || in_array($keyword, self::DATA, TRUE)) {
        continue;
      }
      if (in_array($keyword, self::APPLICATORS, TRUE)) {
        if (!array_is_list($value)) {
          $node[$keyword] = $this->below($value, $pointer . '/' . $keyword, $here, $sources, $root, $within);
          continue;
        }
        foreach ($value as $i => $member) {
          if (is_array($member)) {
            $node[$keyword][$i] = $this->below($member, $pointer . '/' . $keyword . '/' . $i, $here, $sources, $root, $within);
          }
        }
      }
      elseif ($keyword === 'properties') {
        foreach ($value as $name => $child) {
          if (!is_array($child)) {
            continue;
          }
          $child = $this->below($child, $pointer . '/properties/' . str_replace(['~', '/'], ['~0', '~1'], (string) $name), FALSE, $this->declaredAt($sources, 'properties', (string) $name, $root), $root, $within);
          if ($child === TRUE) {
            // It said nothing but its `x-surface`, now said above: a
            // property that narrows nothing is no property here.
            unset($node[$keyword][$name]);
          }
          else {
            $node[$keyword][$name] = $child;
          }
        }
        if ($node[$keyword] === []) {
          unset($node[$keyword]);
        }
      }
      elseif ($keyword === 'items') {
        $node[$keyword] = $this->below($value, $pointer . '/items', FALSE, $this->declaredAt($sources, 'items', NULL, $root), $root, $within);
      }
      elseif (in_array($keyword, ['additionalProperties', 'unevaluatedProperties', 'contains', 'propertyNames'], TRUE)) {
        $node[$keyword] = $this->below($value, $pointer . '/' . $keyword, FALSE, [], $root, $within);
      }
    }
    if ($node === []) {
      return TRUE;
    }
    if (!$known && !isset($node['type']) && array_intersect_key($node, self::TYPED) !== []) {
      $type = $this->declaredType($sources, $root);
      if ($type !== NULL) {
        $node = ['type' => $type] + $node;
      }
    }
    return $node;
  }

  /**
   * Reads the type declared for a place in the instance.
   *
   * @param array[] $sources
   *   The schemas declaring it, nearest first.
   * @param array $root
   *   The document's root.
   * @param string[] $seen
   *   The defs followed on the way, so a reference back ends.
   *
   * @return string|array|null
   *   The first type one of them states, following its references; NULL
   *   when none does.
   */
  protected function declaredType(array $sources, array $root, array $seen = []): string|array|null {
    foreach ($sources as $source) {
      if (isset($source['type'])) {
        return $source['type'];
      }
      $followed = [...$seen, ...$this->references($source)];
      $type = $this->declaredType($this->referred($source, $root, $seen), $root, $followed);
      if ($type !== NULL) {
        return $type;
      }
    }
    return NULL;
  }

  /**
   * Reads what the schemas declaring a place declare one step inside it.
   *
   * @param array[] $sources
   *   The schemas declaring the place, nearest first.
   * @param string $keyword
   *   The keyword: `properties` or `items`.
   * @param string|null $name
   *   The property, for `properties`.
   * @param array $root
   *   The document's root.
   * @param string[] $seen
   *   The defs followed on the way.
   *
   * @return array[]
   *   The schemas declaring the place inside, nearest first.
   */
  protected function declaredAt(array $sources, string $keyword, ?string $name, array $root, array $seen = []): array {
    $found = [];
    foreach ($sources as $source) {
      $inside = $name === NULL ? ($source[$keyword] ?? NULL) : (is_array($source[$keyword] ?? NULL) ? ($source[$keyword][$name] ?? NULL) : NULL);
      if (is_array($inside)) {
        $found[] = $inside;
      }
      $followed = [...$seen, ...$this->references($source)];
      array_push($found, ...$this->declaredAt($this->referred($source, $root, $seen), $keyword, $name, $root, $followed));
    }
    return $found;
  }

  /**
   * Lists the local references a schema makes, beside it or composed.
   *
   * @param array $source
   *   The schema.
   *
   * @return string[]
   *   The def keys.
   */
  protected function references(array $source): array {
    $references = [];
    foreach ([$source, ...(is_array($source['allOf'] ?? NULL) ? $source['allOf'] : [])] as $part) {
      $name = is_array($part) && is_string($part['$ref'] ?? NULL) ? SchemaResolver::defName($part['$ref']) : NULL;
      if ($name !== NULL) {
        $references[] = $name;
      }
    }
    return $references;
  }

  /**
   * Reads the defs a schema refers to, not yet followed.
   *
   * @param array $source
   *   The schema.
   * @param array $root
   *   The document's root.
   * @param string[] $seen
   *   The defs followed on the way.
   *
   * @return array[]
   *   The defs.
   */
  protected function referred(array $source, array $root, array $seen): array {
    $defs = [];
    foreach (array_diff($this->references($source), $seen) as $name) {
      if (is_array($root['$defs'][$name] ?? NULL)) {
        $defs[] = $root['$defs'][$name];
      }
    }
    return $defs;
  }

  /**
   * Heads a document: its title, and its description when there is one.
   *
   * The title is the operation's label. The description is the
   * operation's, what a call does to what, which a client choosing
   * between operations reads first; a document asked for in no operation,
   * or for one that says none, has none.
   *
   * @param string|\Stringable|null $label
   *   The operation's label.
   * @param array $operation
   *   How the operation behaves (OperationDefinition::facts()), or empty.
   *
   * @return array<string, string>
   *   `title` and `description`, each only when there is one.
   */
  protected function heading(string|\Stringable|null $label, array $operation): array {
    $heading = $label === NULL ? [] : ['title' => PlainText::of($label)];
    if (isset($operation['description']) && (string) $operation['description'] !== '') {
      $heading['description'] = PlainText::of($operation['description']);
    }
    return $heading;
  }

  /**
   * States how the operation behaves, at the top of the schema.
   *
   * Under the top's `x-surface`, first, beside the title that is the
   * operation's label: a client that reads the contract directly, with no
   * tool listing, learns whether a call changes nothing, may destroy what
   * is there, creates, and is safe to repeat. Whatever else the top frame
   * says under `x-surface` follows it.
   *
   * Decision: see docs/decisions.md#an-operation-states-how-it-behaves.
   *
   * @param array $operation
   *   The facts, or empty for none.
   * @param array $schema
   *   The top frame.
   *
   * @return array
   *   The top frame, `x-surface` first when there are facts.
   */
  protected function operation(array $operation, array $schema): array {
    if ($operation === []) {
      return $schema;
    }
    // `kind: read` says it, and JSON Schema's `readOnly` at the same top
    // means locked whole. The attribute keeps it, for the tool.
    // Decision: see docs/decisions.md#contract-2.
    unset($operation['readOnly']);
    $extension = [self::EXTENSION => ['operation' => $operation] + ($schema[self::EXTENSION] ?? [])];
    unset($schema[self::EXTENSION]);
    return $extension + $schema;
  }

  /**
   * Stamps which version of its stored values a frame describes.
   *
   * At the top always, first under `x-surface` after how the operation
   * behaves, so a client compares the number it asked for without knowing
   * a default; on a def only above 1, since a def at 1 has said nothing
   * since values were first stored, and its absence reads as 1.
   *
   * Decision: see docs/decisions.md#a-resource-evolves-in-the-open.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface the frame was described from.
   * @param array $schema
   *   The frame.
   * @param bool $top
   *   Whether it is the top of a document rather than a def.
   *
   * @return array
   *   The frame, stamped.
   */
  protected function versioned(DataSurfaceInterface $surface, array $schema, bool $top = TRUE): array {
    if (!$top && $surface->version() === 1) {
      return $schema;
    }
    $schema[self::EXTENSION] = ['version' => $surface->version()] + ($schema[self::EXTENSION] ?? []);
    if (!$top) {
      return $schema;
    }
    $extension = [self::EXTENSION => $schema[self::EXTENSION]];
    unset($schema[self::EXTENSION]);
    return $extension + $schema;
  }

  /**
   * Says at the top that the whole is locked, when an access answer said so.
   *
   * Every key is already `readOnly` and `x-surface.locked`; the top says
   * it too, so a client reading the document offers no submit.
   *
   * Decision: see docs/decisions.md#locked-at-the-top.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface.
   * @param array $schema
   *   The top frame.
   *
   * @return array
   *   The top frame, `readOnly` and `x-surface.locked` when locked whole.
   */
  protected function lockedWhole(DataSurfaceInterface $surface, array $schema): array {
    if ($surface->lockedWhole() === NULL) {
      return $schema;
    }
    $schema[self::EXTENSION]['locked'] = TRUE;
    return ['readOnly' => TRUE] + $schema;
  }

  /**
   * Reads the values an operation knows and nothing can change.
   *
   * The only values the full schema is described for: a locked key holds
   * its identity whatever is stored or typed, so a key that watches only
   * locked siblings has one answer.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   A data surface frame, as advertised.
   *
   * @return array
   *   The value of each locked key, by key.
   */
  protected function known(DataSurfaceInterface $surface): array {
    $known = [];
    foreach ($surface->getDefinitions()->entries() as $name => $entry) {
      // A key its access locked holds what is stored, which is no value
      // known ahead of time.
      if ($entry->locked && !$entry->held) {
        $known[(string) $name] = $surface->getDefault((string) $name);
      }
    }
    return $known;
  }

  /**
   * Answers whether a key watches anything that could hold another value.
   *
   * @param \Drupal\data_surface\DefinitionMap $advertised
   *   The frame's definitions, as advertised.
   * @param \Drupal\data_surface\Entry $entry
   *   The key.
   * @param bool $declaring
   *   Whether the frame is described as declared, where nothing is
   *   locked.
   *
   * @return bool
   *   TRUE when one of the keys its static refiners watch is not a locked
   *   key of the frame: a sibling a value can be given for, or a key an
   *   alter mounted. What only a runtime refiner watches is never read
   *   for any values.
   */
  protected function watchesFree(DefinitionMap $advertised, Entry $entry, bool $declaring = FALSE): bool {
    foreach ($entry->codedDependencies() as $key) {
      if ($declaring || !$advertised->isLocked($key) || $advertised->isHeld($key)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Answers whether a runtime refiner narrows any key of a frame.
   *
   * @param \Drupal\data_surface\DefinitionMap $advertised
   *   The frame's definitions.
   *
   * @return bool
   *   TRUE when one does, so what the static refiners alone answer has to
   *   be asked separately.
   */
  protected function refinedAtRuntime(DefinitionMap $advertised): bool {
    foreach ($advertised->entries() as $entry) {
      if ($entry->isRefinedAtRuntime()) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Describes one frame: the top level, or one child.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $declared
   *   The data surface of this frame, as advertised.
   * @param array $values
   *   The values of this frame.
   * @param string $prefix
   *   The dotted path of this frame from the top, with its trailing dot.
   * @param string $owner
   *   The id this frame was built from: the document's at the top,
   *   a child's own, or the key a child with no class is defined under.
   *   A child with no class of its own is defined as `<owner>.<key>`, a
   *   variant with none as `<owner>.<key>.<value>`.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the description depends on.
   * @param string[] $stale
   *   Collects the dotted paths of stale values.
   * @param \Drupal\data_surface\Contract\Subschemas $subschemas
   *   The document's defs, which every child is written to, and
   *   whether it is the full schema (emitFull()): $values are then the
   *   locked keys the frame knows and nothing else, every frame inside is
   *   described from its own locked keys instead of its defaults, and a
   *   key that watches anything a value could change is described as
   *   declared.
   * @param array<string, mixed> $standing
   *   The orphans of a refinement, by dotted path from the top, each
   *   mapped to the stored value it stands for; held unanswered in
   *   $values.
   * @param bool $declaring
   *   Whether this frame is a def: the data surface as declared, for no
   *   values, with nothing locked. A locked key is then described as the
   *   key it is before a context fixes it (no `readOnly`, no `const`, and
   *   no default, which is the context's), and every child inside is
   *   a bare reference to its own def.
   * @param bool $frozen
   *   Whether the frame is a child its access locked, or is inside one:
   *   every key is then described locked (`readOnly`, `x-surface.locked`)
   *   and holds what it was given, which is what is stored.
   * @param bool $blank
   *   Whether the frame stands for every item of a collection rather than
   *   one value: it is described from the locked keys the child's
   *   operation knows alone, and a key locked only because the frame is
   *   frozen holds no `const`, since each item holds its own.
   *
   * @return array{0: array, 1: array|\stdClass}
   *   The object schema of the frame, and its values as shown.
   */
  protected function frame(DataSurfaceInterface $declared, array $values, string $prefix, string $owner, CacheableMetadata $cacheability, array &$stale, Subschemas $subschemas, array $standing = [], bool $declaring = FALSE, bool $frozen = FALSE, bool $blank = FALSE): array {
    $full = $subschemas->full;
    // Decision: see docs/decisions.md#static-by-default-runtime-by-declaration.
    // The present moment is refined by every refiner; the full schema and
    // a def, which are for any values, by the static ones alone.
    // A declared rule is stated as written, as a conditional of its own,
    // in the full schema and a def; for one set of values it is evaluated
    // like any refiner. Decision: see
    // docs/decisions.md#a-rule-declared-as-data.
    $runtime = !$full && !$declaring;
    $kinds = $runtime ? DataSurfaceInterface::STATIC : DataSurfaceInterface::CODED;
    // A port holds what its container bound it to, read as a sibling is.
    // Decision: see docs/decisions.md#a-port-is-a-live-sibling-value.
    $ports = $declared->getDefinitions()->ports();
    $values = $declared->getPortValues() + $values;
    $refined = $declared->refine($values, $runtime ? DataSurfaceInterface::EVERY : $kinds);
    $cacheability->addCacheableDependency($refined);
    $advertised = $declared->getDefinitions();
    $now = $refined->getDefinitions();
    // What the static refiners alone answer for these values, which is
    // all a conditional may state: a runtime refiner is never run ahead.
    $static = $runtime && $this->refinedAtRuntime($advertised) ? $declared->refine($values, $kinds) : $refined;
    $ahead = $static->getDefinitions();
    // What the coded ones alone answer: whether their gate is open yet.
    $coded = $runtime && $this->hasRules($advertised) ? $declared->refine($values, DataSurfaceInterface::CODED)->getDefinitions() : $ahead;
    $properties = [];
    $required = [];
    $conditions = [];
    $shown = [];
    $ruled = [];
    foreach ($advertised->entries() as $name => $entry) {
      $name = (string) $name;
      $current = $now->entry($name) ?? $entry;
      $stated = $ahead->entry($name) ?? $entry;
      // Compared by definition: a refinement rebinds a child's ports on
      // its entry whether or not the definition moved.
      $answered = ($coded->entry($name) ?? $entry)->definition !== $entry->definition;
      // A def is the declaration, which no context has locked.
      $locked = ($entry->locked || $frozen) && !$declaring;
      if ($full && !$entry->isNested() && $this->watchesFree($advertised, $entry, $declaring)) {
        // A refiner that watches only a key an alter mounted is run even
        // while that key holds nothing. For any values, the key is the
        // one declared: what such a refiner answers is one moment's.
        $current = $entry;
        $stated = $entry;
        $answered = FALSE;
      }
      if (DoesNotApply::marks($current->definition)) {
        // Ruled out for these values: not a property, not required, no
        // value; the frame says what it depends on, so a client refines
        // when that changes. Decision: see
        // docs/decisions.md#a-key-that-does-not-apply.
        $ruled[$name] = $advertised->dependencies($name);
        continue;
      }
      $requires = static::required($current->definition);
      $depends = $advertised->dependencies($name);
      // Whether a static refiner has something to refine the key against
      // yet; while it has not, what it would answer is stated ahead.
      // A key holding a child is refined by the parent only to be ruled
      // out, and the variants' own deciding key is no
      // refiner's.
      $ahead_of_time = $entry->codedDependencies() !== [] && !$locked && ($entry->isNested() ? $entry->refiners !== [] && !$this->holds($entry, $values) : !$answered);
      // What a key reading a port that holds nothing answers is said where
      // the port is wired, at the site, never here: a def is reused, and
      // its container's keys are no properties of it.
      $at_site = $ahead_of_time && $this->readsEmptyPort($entry, $ports, $values);
      $ahead_of_time = $ahead_of_time && !$at_site;
      $enumerated = NULL;
      $stating = [];
      if ($entry->variants !== NULL) {
        [$properties[$name], $shown[$name], $branches] = $this->variants($declared, $entry, $values, $prefix . $name, $owner, $cacheability, $stale, $subschemas, $standing, $declaring, $locked, $blank);
        array_push($conditions, ...$branches);
        if ($ahead_of_time) {
          // A parent's refiner of a key with variants says only whether
          // it applies.
          $enumerated = $this->enumerate($declared, $static, $entry, $values, $properties[$name], $prefix . $name, $cacheability, $kinds, TRUE);
        }
      }
      elseif ($entry->list !== NULL) {
        [$properties[$name], $shown[$name]] = $this->collection($declared, $entry->list, $current, $entry, $values, $prefix . $name, $owner, $cacheability, $stale, $subschemas, $standing, $declaring, $locked, $depends);
        if ($ahead_of_time) {
          // A parent's refiner of the list watches a sibling that holds
          // nothing yet: its Count for every value that sibling offers.
          $enumerated = $this->enumerate($declared, $static, $entry, $values, $properties[$name], $prefix . $name, $cacheability, $kinds);
        }
      }
      elseif ($entry->attachment !== NULL) {
        $attachment = $entry->attachment;
        $fallback = $owner . '.' . $name;
        $key = $this->define($attachment, $fallback, $cacheability, $subschemas);
        if ($declaring) {
          $instance = $subschemas->get($key) ?? [];
          $reference = $this->reference($key, NULL, $subschemas);
          $shown[$name] = new \stdClass();
        }
        else {
          // Bound as the refinement of this frame bound it: a value its
          // own key refuses is no value for a port either.
          $child = ($current->attachment ?? $attachment)->bound($values);
          $held = $values[$name] ?? NULL;
          $inside = $full || $blank ? $this->known($child) : array_replace($child->getDefaultValues(), is_array($held) ? $held : []);
          // A child its access locked is described locked through and
          // through: every key inside it fixed to what it holds.
          [$instance, $shown[$name]] = $this->instance($child, $key, $inside, $prefix . $name . '.', $attachment->id ?? $fallback, $cacheability, $stale, $subschemas, $standing, $locked, $blank);
          $reference = $this->reference($key, $instance, $subschemas);
        }
        $properties[$name] = $this->describe($current->definition) + $reference;
        if ($locked) {
          $properties[$name]['readOnly'] = TRUE;
        }
        $properties[$name][self::EXTENSION] = $this->extension('fieldset', $locked, $depends, $this->anyRefined($instance));
        if ($ahead_of_time) {
          // A parent's refiner of an attached key says only whether it
          // applies.
          $enumerated = $this->enumerate($declared, $static, $entry, $values, $properties[$name], $prefix . $name, $cacheability, $kinds, TRUE);
        }
        if (!$declaring && $attachment->reads !== [] && !static::readsThrough($attachment, $ports)) {
          // Which of this frame's keys each port reads, and what the child
          // answers for them where they hold nothing yet: said here, at the
          // site, on this frame's allOf, never in the child's def.
          // Decision: see docs/decisions.md#a-port-is-a-live-sibling-value.
          $bound = $current->attachment ?? $attachment;
          $each = static::throughPorts($attachment->child) !== [];
          $properties[$name][self::EXTENSION]['reads'] = $each ? $this->eachReads($attachment) : $attachment->reads;
          if (!$locked) {
            [$branches, $markers] = $each
              ? $this->entrySites($declared, $static, $entry, $bound, $values, $prefix . $name, $cacheability, $kinds, $runtime)
              : $this->site($declared, $static, $entry, $bound, '', $values, $prefix . $name, $cacheability, $kinds, $runtime);
            array_push($conditions, ...$branches);
            $properties[$name][self::EXTENSION] += $markers;
          }
        }
      }
      else {
        // The value each element would be built with: a secret's is never
        // handed over, a locked key holds what the operation knows (and a
        // def has no operation), an orphan the stored value it stands
        // for, and anything else what it was given or failing that its
        // default.
        $secret = DefinitionMetadata::isSecret($current->definition);
        $orphan = !$secret && !$locked && array_key_exists($prefix . $name, $standing);
        $value = match (TRUE) {
          $secret, $declaring && $entry->locked => NULL,
          // Locked by its access, it holds what is stored: nothing, in
          // the full schema, which reads no stored value.
          $entry->held && ($full || $blank) => NULL,
          $entry->locked && !$entry->held => $declared->getDefault($name),
          // Inside a locked child, what it holds: nothing, in the full
          // schema, which reads no stored value, nor for a collection's
          // items, each of which holds its own.
          $frozen && ($full || $blank) => NULL,
          $orphan => $standing[$prefix . $name],
          default => $values[$name] ?? $declared->getDefault($name),
        };
        [$properties[$name], $shown[$name]] = $this->property($current->definition, $entry->definition, $value, $prefix . $name, $locked, $depends, $cacheability, $stale);
        if ($declaring && $entry->locked) {
          // What the context fixed it to is the instance's, not the
          // declaration's: the reference site says it.
          unset($properties[$name]['default']);
        }
        if ($orphan) {
          // The edit moved what this key refines against, away from the
          // stored value, which the new answer no longer offers: stale,
          // whatever its widget, and shown as nothing. The caller sends
          // the path back and an empty answer there stands for the stored
          // value again, as for any stale key.
          if (!$properties[$name][self::EXTENSION]['stale']) {
            $properties[$name][self::EXTENSION]['stale'] = TRUE;
            $stale[] = $prefix . $name;
          }
          if (isset($properties[$name][self::EXTENSION]['emptyOption']) && ($properties[$name][self::EXTENSION]['widget'] ?? NULL) !== 'radios') {
            // Refused by the save that moved what it refines against, not
            // kept: its empty option does not say "keep".
            // Decision: see docs/decisions.md#the-empty-option-rule.
            $properties[$name][self::EXTENSION]['emptyOption']['label'] = (string) (DefinitionMetadata::holdsValue($current->definition) ? $this->t('- Select -') : $this->t('- None -'));
          }
          $shown[$name] = NULL;
        }
        // A key its static refiners have not refined, because something
        // they watch holds nothing yet: what they would answer, for every
        // value they could be handed, or that it can only be asked.
        if ($ahead_of_time) {
          // Stated over the key as its static refiners leave it while
          // their gate is closed (as declared, less what a declared rule
          // narrowed for these values): a runtime refiner may have
          // narrowed it for the present moment already, and a conditional
          // says what the coded ones add.
          $ignored = [];
          $base = $current->definition === $stated->definition ? $properties[$name] : $this->property($stated->definition, NULL, NULL, $prefix . $name, FALSE, [], $cacheability, $ignored)[0];
          $enumerated = $this->enumerate($declared, $static, $entry, $values, $base, $prefix . $name, $cacheability, $kinds);
          if ($enumerated !== NULL && $current->definition === $stated->definition) {
            $properties[$name] = $enumerated['base'];
          }
        }
      }
      if ($full && !$declaring && $entry->runtimeAccess) {
        // An access answer only the subject decides was set aside for the
        // account alone: the key is as the static answers leave it, and
        // may be locked or left out once the subject is known, which the
        // schema for these answers settles.
        // Decision: see
        // docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability.
        $properties[$name][self::EXTENSION]['access'] = 'live';
      }
      if ($at_site) {
        // Not stated here, so wherever its coded refiners may rule it
        // out, it is not required here either.
        [$properties[$name], $requires] = $this->mayNotApply($entry, DataSurfaceInterface::CODED, $properties[$name], $requires);
      }
      if ($ahead_of_time) {
        if ($enumerated === NULL) {
          $properties[$name][self::EXTENSION]['refine'] = TRUE;
          // Not stated, so wherever its coded refiners may rule it out,
          // it is not required. Decision: see
          // docs/decisions.md#a-key-that-does-not-apply.
          [$properties[$name], $requires] = $this->mayNotApply($entry, DataSurfaceInterface::CODED, $properties[$name], $requires);
        }
        else {
          $properties[$name][self::EXTENSION]['enumerated'] = TRUE;
          $stating = $enumerated['branches'];
          $requires = $requires && $enumerated['required'];
        }
      }
      if (!$runtime && $entry->isDeclared()) {
        // Each rule as written: a conditional of its own, after what the
        // coded refiners answer, as the pipeline runs them. One reading a
        // port is the site's to state.
        // Decision: see docs/decisions.md#a-rule-declared-as-data.
        [$properties[$name], $stating, $requires, $stated] = $this->rules($entry, $current->definition, $properties[$name], $stating, $requires, $prefix . $name, $cacheability, array_keys($ports));
        if ($stated) {
          $properties[$name][self::EXTENSION]['declared'] = TRUE;
        }
      }
      if ($entry->mounted !== [] && !$declaring) {
        // The structures alters attached at their mounts: each module's
        // property a site of the structure's def, and what the structure
        // answers for keys its ports read stated here, on this frame.
        [$properties[$name], $branches] = $this->mounts($declared, $static, $entry, $current, $properties[$name], $values, $prefix, $owner, $cacheability, $subschemas, $standing, $locked, $blank, $kinds, $runtime);
        if (array_filter($branches, static fn (array $branch): bool => ($branch[self::EXTENSION]['declared'] ?? FALSE) === TRUE) !== []) {
          $properties[$name][self::EXTENSION]['declared'] = TRUE;
        }
        array_push($stating, ...$branches);
      }
      array_push($conditions, ...$stating);
      if ($entry->isRefinedAtRuntime()) {
        // Declared impossible to capture: never in a conditional, never
        // in the full schema, and only in this one for its values.
        $properties[$name][self::EXTENSION]['live'] = TRUE;
        if (!$runtime) {
          // Never run here, so wherever it may rule the key out, the key
          // is not required.
          [$properties[$name], $requires] = $this->mayNotApply($entry, DataSurfaceInterface::RUNTIME, $properties[$name], $requires);
        }
      }
      if ($requires) {
        $required[] = $name;
      }
    }
    $schema = [
      'type' => 'object',
      'properties' => $properties === [] ? new \stdClass() : $properties,
      'additionalProperties' => FALSE,
    ];
    if ($required !== []) {
      $schema['required'] = $required;
    }
    if ($conditions !== []) {
      $schema['allOf'] = $conditions;
    }
    if ($ruled !== []) {
      $schema[self::EXTENSION] = ['doesNotApply' => $ruled];
    }
    // A map of keys from the site reads a sibling map only to hand its
    // entries their ports: no key of its own reads it, and it is not listed.
    $listed = array_filter($ports, static fn (Port $port): bool => !$port->through);
    if ($listed !== []) {
      // What the frame reads from its container, by the names its keys'
      // `dependsOn` use; the site says which key of the container each is.
      $schema[self::EXTENSION]['ports'] = array_map(static fn (Port $port): array => [
        'type' => $port->type,
        'required' => $port->required,
      ] + ($port->shape === NULL ? [] : ['shape' => $port->shape]), $listed);
    }
    if ($advertised->frameConstraints() !== []) {
      // What a schema read into this frame says of the object as a whole,
      // which no keyword here states: checked by the pipeline.
      // Decision: see
      // docs/decisions.md#a-schema-can-be-read-back-into-a-structure.
      $schema[self::EXTENSION]['checkedOnServer'] = array_map(fn (array $foreign): array => static::foreign($foreign, $this->stringTranslation), $advertised->frameConstraints());
    }
    if ($advertised->mixins() !== []) {
      // Decision: see docs/decisions.md#a-mixin-is-merged-flat.
      $schema = $this->composed($advertised, $schema, $owner, $cacheability, $subschemas);
    }
    return [$schema, $shown === [] ? new \stdClass() : $shown];
  }

  /**
   * Composes a frame from the structures mixed into it and its own keys.
   *
   * The frame is first described flat, every key the data surface holds,
   * a structure's members among them, as the pipeline reads it. Each
   * structure mixed in is then written once, as a def, from the structure
   * built by itself, and the frame becomes `allOf` of a reference to each
   * def and its own `properties`, closed with `unevaluatedProperties`, so
   * the def stays reusable with one id. A member keeps its place in the
   * frame's `properties`: `true` where the def says all there is, what
   * differs where the owner narrows it here (a lock, a context, the
   * values of this document, a port read at the owner's key), and
   * `false` where it does not apply to these values. What the def
   * requires and the conditionals it states are said there, not again.
   *
   * Decision: see docs/decisions.md#a-mixin-is-merged-flat.
   *
   * @param \Drupal\data_surface\DefinitionMap $advertised
   *   The frame's definitions, which say which structures are mixed in.
   * @param array $schema
   *   The frame, described flat.
   * @param string $owner
   *   The id the frame was built from.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the description depends on.
   * @param \Drupal\data_surface\Contract\Subschemas $subschemas
   *   The document's defs.
   *
   * @return array
   *   The frame, composed.
   */
  protected function composed(DefinitionMap $advertised, array $schema, string $owner, CacheableMetadata $cacheability, Subschemas $subschemas): array {
    $flat = is_array($schema['properties']) ? $schema['properties'] : [];
    $required = $schema['required'] ?? [];
    $conditions = $schema['allOf'] ?? [];
    $references = [];
    $members = [];
    foreach ($advertised->mixins() as $mixin) {
      $key = $this->define($mixin->structure, $owner . '.' . $mixin->structure->source, $cacheability, $subschemas);
      // Read flat: a structure it mixes in itself says its members there.
      $def = SchemaResolver::inline($subschemas->get($key) ?? [], ['$defs' => $subschemas->all()]);
      $references[] = ['$ref' => self::DEFS . self::pointer($key)];
      $declared = is_array($def['properties'] ?? NULL) ? $def['properties'] : [];
      foreach ($mixin->keys as $name) {
        $members[$name] = $declared[$name] ?? [];
      }
      $required = array_values(array_diff($required, $def['required'] ?? []));
      $stated = is_array($def['allOf'] ?? NULL) ? $def['allOf'] : [];
      $conditions = array_values(array_filter($conditions, static fn (mixed $condition): bool => array_filter($stated, static fn (mixed $said): bool => Subschemas::same($said, $condition)) === []));
    }
    $properties = [];
    foreach ($advertised->names() as $name) {
      if (!array_key_exists($name, $flat)) {
        if (isset($members[$name])) {
          // Not applying to these values: the def holds it, so the frame
          // says it must not be there.
          $properties[$name] = FALSE;
        }
        continue;
      }
      if (!isset($members[$name])) {
        $properties[$name] = $flat[$name];
        continue;
      }
      $narrowing = is_array($flat[$name]) ? $this->narrowing($flat[$name], $members[$name], $subschemas->transient) : $flat[$name];
      $properties[$name] = $narrowing === [] ? TRUE : $narrowing;
    }
    $composed = [
      'type' => 'object',
      'properties' => $properties === [] ? new \stdClass() : $properties,
      'unevaluatedProperties' => FALSE,
    ];
    if ($required !== []) {
      $composed['required'] = $required;
    }
    $composed['allOf'] = [...$references, ...$conditions];
    $composing = ['type', 'properties', 'additionalProperties', 'required', 'allOf'];
    return $composed + array_diff_key($schema, array_flip($composing));
  }

  /**
   * Lists the structures a data surface mixes in, at any depth.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface.
   *
   * @return string[]
   *   Their ids: each one's def is written open in this document.
   */
  protected function mixedIn(DataSurfaceInterface $surface): array {
    // A sealed child never changes, and may be held at many keys.
    static $asked = NULL;
    $asked ??= new \WeakMap();
    if (isset($asked[$surface])) {
      return $asked[$surface];
    }
    $ids = [];
    foreach ($surface->getDefinitions()->mixins() as $mixin) {
      $ids[] = (string) ($mixin->structure->id ?? $mixin->structure->source);
      array_push($ids, ...$this->mixedIn($mixin->structure->child));
    }
    foreach ($surface->getDefinitions()->entries() as $entry) {
      $children = match (TRUE) {
        $entry->variants !== NULL => $entry->variants->variants,
        $entry->attachment !== NULL => [$entry->attachment],
        $entry->list !== NULL => [$entry->list->item],
        default => [],
      };
      foreach ([...$children, ...$entry->mounted] as $child) {
        array_push($ids, ...$this->mixedIn($child->child));
      }
    }
    return $asked[$surface] = array_values(array_unique($ids));
  }

  /**
   * Answers whether a key's coded refiners read a port that holds nothing.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The key.
   * @param array<string, \Drupal\data_surface\Port> $ports
   *   The frame's ports.
   * @param array $values
   *   The frame's values, its ports' among them.
   *
   * @return bool
   *   TRUE when what they answer depends on a port no container bound.
   */
  protected function readsEmptyPort(Entry $entry, array $ports, array $values): bool {
    foreach ($entry->codedDependencies() as $key) {
      if (isset($ports[$key]) && !ValueState::isConfigured($values[$key] ?? NULL)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * States, at a site, what a child answers for the ports it reads.
   *
   * The child's def never states what depends on a port: the port is no
   * property there, and the def is reused wherever the child is attached.
   * The container wires each port to a key of its own, so it can: here,
   * on its own `allOf`, an `if` on its key and a `then` reaching into
   * the child at the site. A coded refiner reading a port is enumerated
   * over the wired keys' values, as any key's refiners are over their
   * siblings', unless it also reads a key of the child's, whose value the
   * container does not state; a declared rule reading a port is stated as
   * written, its condition's port read at the wired key.
   *
   * Decision: see docs/decisions.md#a-port-is-a-live-sibling-value.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $declared
   *   The container's frame, as advertised.
   * @param \Drupal\data_surface\DataSurfaceInterface $static
   *   The same, refined for its values by its static refiners alone.
   * @param \Drupal\data_surface\Entry $entry
   *   The key holding the child, or the mounts.
   * @param \Drupal\data_surface\Attachment $attachment
   *   The child, wired.
   * @param string $below
   *   Where the child sits below the key: '' for an attached child, the
   *   module for a structure at a mount.
   * @param array $values
   *   The container's values.
   * @param string $path
   *   The key's dotted path.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the description depends on.
   * @param int $kinds
   *   The kinds of refiner a combination is answered by.
   * @param bool $runtime
   *   Whether the document is for one set of values, where a rule is
   *   evaluated, not stated.
   * @param array|null $inline
   *   The key's property as this frame states it inline, for a mount;
   *   NULL for an attached child, which is described here.
   *
   * @return array{0: array, 1: array<string, true>}
   *   The conditionals, and the markers the site carries: `enumerated`
   *   or `refine`, and `declared`.
   */
  protected function site(DataSurfaceInterface $declared, DataSurfaceInterface $static, Entry $entry, Attachment $attachment, string $below, array $values, string $path, CacheableMetadata $cacheability, int $kinds, bool $runtime, ?array $inline = NULL): array {
    $map = $attachment->child->getDefinitions();
    $ports = $map->ports();
    $held = $attachment->ports ?? $attachment->readFrom($values)->ports ?? [];
    $empty = array_filter($attachment->reads, static fn (string $port): bool => !ValueState::isConfigured($held[$port] ?? NULL), ARRAY_FILTER_USE_KEY);
    if ($empty === []) {
      // Every port holds a value: the instance is refined for them.
      return [[], []];
    }
    $definition = $static->getDefinition($entry->name) ?? $entry->definition;
    $base = $inline ?? $this->stated($entry, $definition, $path, $cacheability);
    $markers = [];
    $branches = [];
    if ($below === '') {
      $reading = FALSE;
      $mixed = FALSE;
      $ruled = [];
      foreach ($map->entries() as $name => $child) {
        $watched = $child->codedDependencies();
        if (array_intersect($watched, array_keys($empty)) === []) {
          continue;
        }
        $reading = TRUE;
        $mixed = $mixed || array_diff($watched, array_keys($ports)) !== [];
        foreach ($child->mayNotApply(DataSurfaceInterface::CODED) as $inside) {
          $ruled[] = $inside === '' ? (string) $name : $name . '.' . $inside;
        }
      }
      if ($reading) {
        // The def requires none of what may not apply; nor does the base a
        // conditional is read over.
        $enumerated = $mixed ? NULL : $this->enumerate($declared, $static, $entry, $values, static::requiringNone($base, $ruled), $path, $cacheability, $kinds);
        $markers[$enumerated === NULL ? 'refine' : 'enumerated'] = TRUE;
        array_push($branches, ...($enumerated['branches'] ?? []));
      }
    }
    if ($runtime) {
      return [$branches, $markers];
    }
    $links = [];
    foreach ($map->entries() as $name => $child) {
      foreach ($child->refiners as $chain) {
        foreach ($chain as $link) {
          if (!$link instanceof DeclaredRefiner) {
            continue;
          }
          foreach ($link->rulesFor((string) $name) as [$inner, $rule]) {
            $condition = $rule->condition();
            $keys = $condition?->keys() ?? [];
            $read = array_intersect($keys, array_keys($ports));
            if ($condition === NULL || $read === []) {
              continue;
            }
            $unwired = array_diff($read, array_keys($attachment->reads));
            if ($unwired !== []) {
              // A port no container wired holds nothing. A rule reading
              // nothing else is answered here, once: one that never holds
              // says nothing; any other is left to the server.
              if (array_values($unwired) !== array_values($keys) || $condition->holds(array_fill_keys($keys, NULL))) {
                $markers['refine'] = TRUE;
              }
              continue;
            }
            $paths = [];
            $site = $below === '' ? $entry->name : $entry->name . '.' . $below;
            foreach ($keys as $key) {
              $paths[$key] = isset($ports[$key]) ? $attachment->reads[$key] : $site . '.' . implode('.', $link->pathOf($key));
            }
            $at = ($below === '' ? '' : $below . '.') . $name . ($inner === '' ? '' : '.' . $inner);
            $links[] = new DeclaredRefiner([$entry->name => [$rule]], NULL, $paths, $at);
          }
        }
      }
    }
    if ($links !== []) {
      $pseudo = new Entry($entry->name, $definition, refiners: [DataSurfaceInterface::OWNER => $links]);
      [, $stated] = $this->rules($pseudo, $definition, $base, [], FALSE, $path, $cacheability);
      array_push($branches, ...$stated);
      $markers['declared'] = TRUE;
    }
    return [$branches, $markers];
  }

  /**
   * Lists the ports of a data surface that a map reads for its entries.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface.
   *
   * @return array<string, \Drupal\data_surface\Port>
   *   Its through ports, by name.
   */
  protected static function throughPorts(DataSurfaceInterface $surface): array {
    return array_filter($surface->getDefinitions()->ports(), static fn (Port $port): bool => $port->through);
  }

  /**
   * Answers whether a child reads its ports through a port of its frame.
   *
   * An entry of a map of keys from the site reads a sibling map's entry
   * through the map's own port: what depends on it is stated where the
   * map's port is wired, in the owner's frame, never in the map's.
   *
   * @param \Drupal\data_surface\Attachment $attachment
   *   The child, wired.
   * @param array<string, \Drupal\data_surface\Port> $ports
   *   The frame's ports.
   *
   * @return bool
   *   TRUE when a port of the child reads through one of the frame's.
   */
  protected static function readsThrough(Attachment $attachment, array $ports): bool {
    foreach ($attachment->reads as $path) {
      if (($ports[explode('.', $path)[0]] ?? NULL)?->through) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Spells how a map of keys from the site wires its entries' ports.
   *
   * As attachEach() was told: each port of the structure, by the sibling
   * map whose entry of the same id it reads.
   *
   * @param \Drupal\data_surface\Attachment $attachment
   *   The map, wired to its siblings.
   *
   * @return array<string, string>
   *   The sibling map, by port.
   */
  protected function eachReads(Attachment $attachment): array {
    $spelled = [];
    foreach ($attachment->child->getDefinitions()->entries() as $entry) {
      foreach ($entry->attachment->reads ?? [] as $port => $path) {
        $spelled[(string) $port] ??= $attachment->reads[explode('.', $path)[0]] ?? $path;
      }
    }
    return $spelled;
  }

  /**
   * States, per entry, what a map's entries answer for the ports they read.
   *
   * Each entry `<id>` of a map of keys from the site reads the entry of
   * the same id of a sibling map; what depends on it is stated as a site
   * at the entry, on this frame's `allOf`: an `if` on the sibling's entry
   * and a `then` reaching into the map at the entry. A locked entry states
   * its rules too: what is stored is kept, and what does not apply is no
   * key of it in either document.
   *
   * Decision: see docs/decisions.md#keys-that-come-from-the-site.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $declared
   *   This frame, as advertised.
   * @param \Drupal\data_surface\DataSurfaceInterface $static
   *   The same, refined for its values by its static refiners alone.
   * @param \Drupal\data_surface\Entry $entry
   *   The key holding the map.
   * @param \Drupal\data_surface\Attachment $attachment
   *   The map, wired to its siblings.
   * @param array $values
   *   This frame's values.
   * @param string $path
   *   The key's dotted path.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the description depends on.
   * @param int $kinds
   *   The kinds of refiner a combination is answered by.
   * @param bool $runtime
   *   Whether the document is for one set of values.
   *
   * @return array{0: array, 1: array<string, true>}
   *   The conditionals, and the markers the key carries.
   */
  protected function entrySites(DataSurfaceInterface $declared, DataSurfaceInterface $static, Entry $entry, Attachment $attachment, array $values, string $path, CacheableMetadata $cacheability, int $kinds, bool $runtime): array {
    $branches = [];
    $markers = [];
    foreach ($attachment->child->getDefinitions()->entries() as $id => $inner) {
      $child = $inner->attachment;
      if ($child === NULL || $child->reads === []) {
        continue;
      }
      // Each port at the sibling's entry, by its path in this frame.
      $reads = [];
      foreach ($child->reads as $port => $through) {
        [$first, $rest] = array_pad(explode('.', $through, 2), 2, '');
        $reads[(string) $port] = ($attachment->reads[$first] ?? $first) . '.' . $rest;
      }
      $site = new Attachment($child->child, $child->source, $child->id, $reads, $child->wired);
      [$stated, $marked] = $this->site($declared, $static, $entry, $site, (string) $id, $values, $path, $cacheability, $kinds, $runtime);
      array_push($branches, ...$stated);
      $markers += $marked;
      // A coded refiner reading a port is not enumerated at an entry:
      // the server answers it.
      $ports = $child->child->getDefinitions()->ports();
      foreach ($child->child->getDefinitions()->entries() as $key) {
        if (array_intersect($key->codedDependencies(), array_keys($ports)) !== [] && $this->readsEmptyPort($key, $ports, $site->readFrom($values)->ports ?? [])) {
          $markers['refine'] = TRUE;
        }
      }
    }
    return [$branches, $markers];
  }

  /**
   * Writes each structure an alter attached at its mount as a site.
   *
   * The module's property inside `third_party_settings` refers to the
   * structure's def, narrowed where this document's values refine it, and
   * says which of the owner's keys each port reads; what the structure
   * answers for a port that holds nothing is stated on this frame.
   *
   * Decision: see docs/decisions.md#an-alter-attaches-at-its-own-mount.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $declared
   *   The frame, as advertised.
   * @param \Drupal\data_surface\DataSurfaceInterface $static
   *   The same, refined for its values by its static refiners alone.
   * @param \Drupal\data_surface\Entry $entry
   *   The key holding the mounts.
   * @param \Drupal\data_surface\Entry $current
   *   The same key, as it stands.
   * @param array $property
   *   Its property, stated inline.
   * @param array $values
   *   The frame's values.
   * @param string $prefix
   *   The frame's path, with its trailing dot.
   * @param string $owner
   *   The frame's id.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the description depends on.
   * @param \Drupal\data_surface\Contract\Subschemas $subschemas
   *   The document's defs.
   * @param array<string, mixed> $standing
   *   The orphans of a refinement, by dotted path from the top.
   * @param bool $locked
   *   Whether the key is described locked.
   * @param bool $blank
   *   Whether the frame stands for every item of a collection.
   * @param int $kinds
   *   The kinds of refiner a combination is answered by.
   * @param bool $runtime
   *   Whether the document is for one set of values.
   *
   * @return array{0: array, 1: array}
   *   The property, each mount a site, and the conditionals for the
   *   frame's `allOf`.
   */
  protected function mounts(DataSurfaceInterface $declared, DataSurfaceInterface $static, Entry $entry, Entry $current, array $property, array $values, string $prefix, string $owner, CacheableMetadata $cacheability, Subschemas $subschemas, array $standing, bool $locked, bool $blank, int $kinds, bool $runtime): array {
    $branches = [];
    foreach ($entry->mounted as $module => $attachment) {
      $module = (string) $module;
      // Bound as the refinement of this frame bound it.
      $attachment = $current->mounted[$module] ?? $attachment;
      $inline = $property['properties'][$module] ?? NULL;
      if (!is_array($inline)) {
        continue;
      }
      $path = $prefix . $entry->name;
      $markers = [];
      if (!$locked) {
        [$stated, $markers] = $this->site($declared, $static, $entry, $attachment, $module, $values, $path, $cacheability, $kinds, $runtime, $property);
        array_push($branches, ...$stated);
      }
      $key = $this->define($attachment, $owner . '.' . $entry->name . '.' . $module, $cacheability, $subschemas);
      $child = $attachment->bound($values);
      $held = NestedArray::getValue($values, [$entry->name, $module]);
      $inside = $subschemas->full || $blank ? $this->known($child) : array_replace($child->getDefaultValues(), is_array($held) ? $held : []);
      $ignored = [];
      [$instance] = $this->instance($child, $key, $inside, $path . '.' . $module . '.', $attachment->id ?? $key, $cacheability, $ignored, $subschemas, $standing, $locked, $blank);
      $map = $current->definition instanceof ComplexDataDefinitionInterface ? $current->definition->getPropertyDefinition($module) : NULL;
      $site = ($map === NULL ? [] : $this->describe($map)) + $this->reference($key, $instance, $subschemas);
      if (isset($inline['readOnly'])) {
        $site['readOnly'] = $inline['readOnly'];
      }
      $site[self::EXTENSION] = (is_array($inline[self::EXTENSION] ?? NULL) ? $inline[self::EXTENSION] : []) + ['reads' => $attachment->reads] + $markers;
      $property['properties'][$module] = $site;
    }
    return [$property, $branches];
  }

  /**
   * Answers whether every key a key's static refiners watch holds a value.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The key.
   * @param array $values
   *   The values of its frame.
   *
   * @return bool
   *   TRUE when its refiners have run for these values.
   */
  protected function holds(Entry $entry, array $values): bool {
    foreach ($entry->codedDependencies() as $key) {
      if (!str_contains($key, '.') && !ValueState::isConfigured($values[$key] ?? NULL)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Stops requiring what a refiner not run here may rule out.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The key.
   * @param int $runtime
   *   The kind of refiner not run: DataSurfaceInterface::RUNTIME, or
   *   CODED when their answers cannot be listed.
   * @param array $property
   *   Its property schema.
   * @param bool $requires
   *   Whether the frame requires it so far.
   *
   * @return array{0: array, 1: bool}
   *   The property, with what may not apply inside it no longer required,
   *   and whether the frame still requires the key.
   */
  protected function mayNotApply(Entry $entry, int $runtime, array $property, bool $requires): array {
    $paths = $entry->mayNotApply($runtime);
    $inside = array_values(array_filter($paths, static fn (string $path): bool => $path !== ''));
    return [static::requiringNone($property, $inside), $requires && !in_array('', $paths, TRUE)];
  }

  /**
   * Answers whether a declared rule narrows any key of a frame.
   *
   * @param \Drupal\data_surface\DefinitionMap $advertised
   *   The frame's definitions.
   *
   * @return bool
   *   TRUE when one does, so what the coded refiners alone answer has to
   *   be asked separately.
   */
  protected function hasRules(DefinitionMap $advertised): bool {
    foreach ($advertised->entries() as $entry) {
      if ($entry->isDeclared()) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * States a key's declared rules as written: one conditional each.
   *
   * For any values, so in the full schema and a def. Each rule's `if` is
   * its condition's own spelling, and its `then` what the rule's effects
   * make of the key as the document states it, by the same reading a
   * conditional of the enumeration is: only what changed, `false` where
   * the key does not apply, `required` where it is required only here.
   * Each is listed by its index in the frame's `x-surface.rules` (settled()).
   *
   * A key a rule may rule out is required nowhere it might not apply: it
   * leaves the frame's `required` (or its map's, for a key an alter
   * mounted), and a conditional requires it wherever no rule rules it
   * out. A conditional that requires it, a rule's or a coded refiner's,
   * holds only there too, so no document is held to both.
   *
   * Decision: see docs/decisions.md#a-rule-declared-as-data.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key as the document states it.
   * @param array $property
   *   Its property schema.
   * @param array $branches
   *   The conditionals the coded refiners state for it.
   * @param bool $requires
   *   Whether the frame requires it so far.
   * @param string $path
   *   The key's dotted path.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the description depends on.
   * @param string[] $ports
   *   The ports of the frame: a rule reading one is not stated here, the
   *   site that wires it states it, and a key it may rule out is not
   *   required here.
   *
   * @return array{0: array, 1: array, 2: bool, 3: bool}
   *   The property, the key's conditionals, the coded ones first,
   *   whether the frame still requires the key, and whether any rule was
   *   stated here.
   */
  protected function rules(Entry $entry, DataDefinitionInterface $definition, array $property, array $branches, bool $requires, string $path, CacheableMetadata $cacheability, array $ports = []): array {
    $name = $entry->name;
    $applicability = $entry->isNested();
    $base = $applicability ? [] : $this->stated($entry, $definition, $path, $cacheability);
    $declared = [];
    $ruling = [];
    $stated = FALSE;
    foreach ($entry->refiners as $chain) {
      foreach ($chain as $link) {
        if (!$link instanceof DeclaredRefiner) {
          continue;
        }
        foreach ($link->rulesFor($name) as [$below, $rule]) {
          $condition = $rule->condition();
          assert($condition !== NULL);
          if (array_intersect($condition->keys(), $ports) !== []) {
            // Decision: see docs/decisions.md#a-port-is-a-live-sibling-value.
            if ($rule->rulesOut()) {
              if ($below === '') {
                $requires = FALSE;
              }
              else {
                $property = static::requiringNone($property, [(string) $below]);
              }
            }
            continue;
          }
          $stated = TRUE;
          $paths = [];
          foreach ($condition->keys() as $key) {
            $paths[$key] = $link->pathOf($key);
          }
          $if = $condition->schema($paths);
          if ($rule->rulesOut()) {
            $ruling[$below][] = $if;
            $declared[] = [$below, FALSE, ['if' => $if, 'then' => static::falseAt($name, $below)]];
            continue;
          }
          $then = [];
          if (!$applicability) {
            $answer = static::appliedAt(DataSurface::deepClone($definition), $below, $rule);
            $narrowed = static::onlyAdded($this->narrowed($this->stated($entry, $answer, $path, $cacheability), $base), $base);
            if ($narrowed !== []) {
              $then['properties'] = [$name => $narrowed];
            }
          }
          if ($below === '' && $rule->requires() && !$requires) {
            $then['required'] = [$name];
          }
          if ($then !== []) {
            $declared[] = [$below, $rule->requires(), ['if' => $if, 'then' => $then]];
          }
        }
      }
    }

    // Required only where no rule rules it out.
    $unless = array_map(static fn (array $ifs): array => static::negated(count($ifs) === 1 ? $ifs[0] : ['anyOf' => $ifs]), $ruling);
    $guard = static function (array $branch, string $below) use ($unless): array {
      if (isset($unless[$below])) {
        $branch['if'] = ['allOf' => [$branch['if'], $unless[$below]]];
      }
      return $branch;
    };
    foreach ($branches as $i => $branch) {
      if (in_array($name, $branch['then']['required'] ?? [], TRUE)) {
        $branches[$i] = $guard($branch, '');
      }
    }
    foreach ($declared as [$below, $requiring, $branch]) {
      $branches[] = ($requiring ? $guard($branch, $below) : $branch) + [self::EXTENSION => ['declared' => TRUE]];
    }
    foreach ($unless as $below => $not) {
      if ($below === '') {
        if ($requires) {
          $requires = FALSE;
          $branches[] = ['if' => $not, 'then' => ['required' => [$name]], self::EXTENSION => ['declared' => TRUE]];
        }
        continue;
      }
      // A key an alter mounted, inside its map.
      $parents = explode('.', (string) $below);
      $key = (string) array_pop($parents);
      $at = [];
      foreach ($parents as $parent) {
        array_push($at, 'properties', $parent);
      }
      $object = $at === [] ? $property : NestedArray::getValue($property, $at);
      if (is_array($object) && in_array($key, $object['required'] ?? [], TRUE)) {
        $property = static::requiringNone($property, [(string) $below]);
        $then = ['required' => [$key]];
        foreach (array_reverse($parents) as $parent) {
          $then = ['properties' => [$parent => $then]];
        }
        $branches[] = [
          'if' => $not,
          'then' => ['properties' => [$name => $then]],
          self::EXTENSION => ['declared' => TRUE],
        ];
      }
    }
    return [$property, $branches, $requires, $stated];
  }

  /**
   * Negates an `if`, writing `c` where it would write `not(not(c))`.
   *
   * A rule declared with unless(), or with when(Condition::not(...)),
   * rules a key out where its condition does not hold; the conditional
   * requiring the key wherever no rule rules it out then states the
   * condition itself rather than its double negation.
   *
   * @param array $if
   *   The `if`.
   *
   * @return array
   *   Its negation.
   */
  protected static function negated(array $if): array {
    return array_keys($if) === ['not'] && is_array($if['not']) ? $if['not'] : ['not' => $if];
  }

  /**
   * Spells a key that does not apply, at its place in a `then`.
   *
   * @param string $name
   *   The key.
   * @param string $below
   *   The dotted path of a key below it, or '' for the key itself.
   *
   * @return array
   *   The `then`.
   */
  protected static function falseAt(string $name, string $below): array {
    if ($below === '') {
      return ['properties' => [$name => FALSE]];
    }
    $schema = FALSE;
    foreach (array_reverse(explode('.', $below)) as $segment) {
      $schema = ['properties' => [$segment => $schema]];
    }
    return ['properties' => [$name => $schema]];
  }

  /**
   * Applies a rule's effects to a key, or to a key below it.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   A copy of the key.
   * @param string $below
   *   The dotted path of a key below it, or '' for the key itself.
   * @param \Drupal\data_surface\Refinement\Rule $rule
   *   The rule.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The key with the effects applied where they apply.
   */
  protected static function appliedAt(DataDefinitionInterface $definition, string $below, Rule $rule): DataDefinitionInterface {
    if ($below === '') {
      $applied = $rule->apply($definition);
      // An annotation the schema cannot place: kept whether or not the
      // rule tightens the key for the values it will meet.
      if ($rule->description() !== NULL && $applied instanceof DataDefinition && !DoesNotApply::marks($applied)) {
        $applied->setDescription($rule->description());
      }
      return $applied;
    }
    if (!$definition instanceof MapDataDefinition) {
      return $definition;
    }
    [$first, $rest] = array_pad(explode('.', $below, 2), 2, '');
    $inner = $definition->getPropertyDefinition($first);
    if ($inner !== NULL) {
      $definition->setPropertyDefinition($first, static::appliedAt($inner, $rest, $rule));
    }
    return $definition;
  }

  /**
   * Keeps of each `required` a narrowing states only the keys it adds.
   *
   * JSON Schema holds a document to every `required` it meets, so a
   * conditional that requires one more key says that key alone; restating
   * the rest would require again what the frame stopped requiring.
   *
   * @param array $narrowed
   *   What a conditional says about a key.
   * @param array $base
   *   The key as the document states it.
   *
   * @return array
   *   The same, each `required` holding only what it adds.
   */
  protected static function onlyAdded(array $narrowed, array $base): array {
    if (isset($narrowed['required']) && is_array($narrowed['required'])) {
      $added = array_values(array_diff($narrowed['required'], is_array($base['required'] ?? NULL) ? $base['required'] : []));
      if ($added === []) {
        unset($narrowed['required']);
      }
      else {
        $narrowed['required'] = $added;
      }
    }
    foreach (is_array($narrowed['properties'] ?? NULL) ? $narrowed['properties'] : [] as $key => $property) {
      if (is_array($property)) {
        $narrowed['properties'][$key] = static::onlyAdded($property, is_array($base['properties'][$key] ?? NULL) ? $base['properties'][$key] : []);
      }
    }
    return $narrowed;
  }

  /**
   * Describes a collection: an array whose items refer to the child's def.
   *
   * The list is the parent's key, so its own property says what the
   * parent says about it: its title and description, `minItems` and
   * `maxItems` from its Count as the parent's refiners left it, whether
   * it is locked, and `x-surface.list`. Its `items` refer to the child's
   * def, as an attached child's property does, narrowed by composition
   * only where every item differs from the def (a key the child's
   * operation locked, a list locked whole): described from the child's
   * locked keys alone, in both documents, so the schema says nothing one
   * item's answers decide, and what the module states as defaults stays
   * on the def. A locked list's items are `readOnly` and hold no
   * `const`, since each item holds its own value.
   * Each item's values, and anything stale inside one, are read item by
   * item in the child's own frame, into `values` and `stale`, under the
   * item's delta.
   *
   * Decision: see docs/decisions.md#a-list-of-child-invocations.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $declared
   *   The data surface the collection belongs to.
   * @param \Drupal\data_surface\Collection $list
   *   The child every item is.
   * @param \Drupal\data_surface\Entry $current
   *   The key as it stands, refined by the parent.
   * @param \Drupal\data_surface\Entry $entry
   *   The key as advertised.
   * @param array $values
   *   The values of the collection's frame.
   * @param string $path
   *   The collection's dotted path.
   * @param string $owner
   *   The id of the resource or structure it belongs to.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the description depends on.
   * @param string[] $stale
   *   Collects the dotted paths of stale values, an item's under its
   *   delta.
   * @param \Drupal\data_surface\Contract\Subschemas $subschemas
   *   The document's defs, and whether it is the full schema, which shows
   *   no values.
   * @param array<string, mixed> $standing
   *   The orphans of a refinement, by dotted path from the top.
   * @param bool $declaring
   *   Whether the collection is part of a def: its items are then a bare
   *   reference.
   * @param bool $locked
   *   Whether the list is locked: its items are then described locked.
   * @param string[] $depends
   *   The siblings the parent's refiners of the list watch.
   *
   * @return array{0: array, 1: mixed}
   *   The collection's property schema, and its value as shown: one map
   *   per item, or NULL for a list that holds nothing.
   */
  protected function collection(DataSurfaceInterface $declared, Collection $list, Entry $current, Entry $entry, array $values, string $path, string $owner, CacheableMetadata $cacheability, array &$stale, Subschemas $subschemas, array $standing, bool $declaring, bool $locked, array $depends): array {
    $child = $list->child();
    $fallback = $owner . '.' . $entry->name;
    $key = $this->define($list->item, $fallback, $cacheability, $subschemas);
    if ($declaring) {
      $items = $this->reference($key, NULL, $subschemas);
    }
    else {
      $ignored = [];
      [$instance] = $this->instance($child, $key, $this->known($child), $path . '.*.', $list->item->id ?? $fallback, $cacheability, $ignored, $subschemas, [], $locked, TRUE);
      $items = $this->reference($key, $instance, $subschemas);
    }
    $definition = $current->definition;
    [$schema, $extras] = $this->listKeywords($definition);
    $schema['items'] = $items;
    if (DefinitionMetadata::hasDefaultValue($definition) && !$declaring) {
      $schema['default'] = DefinitionMetadata::getDefaultValue($definition);
    }
    if ($locked) {
      $schema['readOnly'] = TRUE;
    }
    $schema[self::EXTENSION] = $this->extension('list', $locked, $depends, $this->signature($entry->definition) !== $this->signature($definition)) + ['list' => TRUE] + $extras;

    $shown = NULL;
    $held = $values[$entry->name] ?? $declared->getDefault($entry->name);
    if (!$subschemas->full && !$declaring && is_array($held)) {
      // Each item as its element would be built with, read in the
      // child's own frame: a secret not handed over, a stale value shown
      // as nothing and listed under its delta.
      $shown = [];
      foreach (array_values($held) as $delta => $item) {
        $inside = array_replace($child->getDefaultValues(), is_array($item) ? $item : []);
        [, $shown[]] = $this->frame($child, $inside, $path . '.' . $delta . '.', $list->item->id ?? $fallback, $cacheability, $stale, $subschemas, $standing, FALSE, $locked);
      }
    }
    return [$schema, $shown];
  }

  /**
   * States what a collection's own definition says, without its items.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The list, as the parent says it.
   *
   * @return array{0: array, 1: array}
   *   Its title, description, `type` and keywords (`minItems`,
   *   `maxItems`); and what goes under `x-surface` beside them: the
   *   constraints no keyword states.
   */
  protected function listKeywords(DataDefinitionInterface $definition): array {
    $schema = $this->describe($definition) + ['type' => $this->nullable('array', $definition)] + $this->keywords($definition, 'array');
    $extras = [];
    if (isset($schema[self::EXTENSION . '-checked'])) {
      $extras['checkedOnServer'] = $schema[self::EXTENSION . '-checked'];
    }
    unset($schema[self::EXTENSION . '-checked'], $schema[self::EXTENSION . '-pattern-message']);
    return [$schema, $extras];
  }

  /**
   * Writes a child's def, once, and answers the key it is filed under.
   *
   * The def is the child's frame as declared: described for no values,
   * with nothing locked, every child inside it a reference of its own. A
   * child already written, here or at another key, is not written again;
   * nor is one reached inside its own def, which is referred to, the def
   * being written, so a shape that ever contained itself would end there
   * instead of looping. (The build step refuses a resource or structure
   * attached inside itself, and a sealed data surface cannot hold itself,
   * so that is a guard, not a case.)
   *
   * @param \Drupal\data_surface\Attachment $attachment
   *   The child at its key.
   * @param string $fallback
   *   The id to define it under when it has no `#[Resource]` or
   *   `#[Structure]` id of its own.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the description depends on.
   * @param \Drupal\data_surface\Contract\Subschemas $subschemas
   *   The document's defs.
   *
   * @return string
   *   The key under `$defs`.
   */
  protected function define(Attachment $attachment, string $fallback, CacheableMetadata $cacheability, Subschemas $subschemas): string {
    $child = $attachment->child;
    $key = $subschemas->keyOf($child);
    if ($key !== NULL) {
      return $key;
    }
    $writing = $subschemas->writing($child);
    if ($writing !== NULL) {
      return $writing;
    }
    $id = $attachment->id ?? $fallback;
    $subschemas->begin($id, $child);
    $ignored = [];
    [$def] = $this->frame($child, [], '', $id, $cacheability, $ignored, $subschemas, [], TRUE);
    if ($subschemas->isOpen($id)) {
      // Mixed in somewhere in this document: open, so an owner composes it
      // with keys of its own, and closed by every other place it is used.
      // Decision: see docs/decisions.md#a-mixin-is-merged-flat.
      unset($def['additionalProperties'], $def['unevaluatedProperties']);
    }
    $def = $this->versioned($child, $def, FALSE);
    return $subschemas->add($id, $def, $child);
  }

  /**
   * Describes a child as it is at one site: its instance.
   *
   * In the full schema a child with nothing locked anywhere inside it is
   * its own def, so its def is read back rather than described twice.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $child
   *   The child.
   * @param string $key
   *   The key its def is filed under.
   * @param array $values
   *   Its values.
   * @param string $prefix
   *   Its dotted path from the top, with the trailing dot.
   * @param string $owner
   *   Its id.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the description depends on.
   * @param string[] $stale
   *   Collects the dotted paths of stale values.
   * @param \Drupal\data_surface\Contract\Subschemas $subschemas
   *   The document's defs.
   * @param array<string, mixed> $standing
   *   The orphans of a refinement, by dotted path from the top.
   * @param bool $frozen
   *   Whether the child is locked whole, by its access or its parent's.
   * @param bool $blank
   *   Whether the child stands for every item of a collection.
   *
   * @return array{0: array, 1: array|\stdClass}
   *   The child's object schema here, and its values as shown.
   */
  protected function instance(DataSurfaceInterface $child, string $key, array $values, string $prefix, string $owner, CacheableMetadata $cacheability, array &$stale, Subschemas $subschemas, array $standing, bool $frozen = FALSE, bool $blank = FALSE): array {
    $def = $subschemas->get($key);
    $bound = array_filter($child->getPortValues(), ValueState::isConfigured(...)) !== [];
    if ($subschemas->full && $def !== NULL && !$frozen && !$bound && !$this->locks($child)) {
      return [$def, new \stdClass()];
    }
    return $this->frame($child, $values, $prefix, $owner, $cacheability, $stale, $subschemas, $standing, FALSE, $frozen, $blank);
  }

  /**
   * Answers whether a context locked anything in a data surface.
   *
   * Or whether access marked anything in it as decided at runtime: either
   * makes a child differ from its def, so it is described where it sits.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface.
   *
   * @return bool
   *   TRUE when a key of it, or of a child inside it, is locked or marked.
   */
  protected function locks(DataSurfaceInterface $surface): bool {
    static $asked = NULL;
    $asked ??= new \WeakMap();
    return $asked[$surface] ??= $this->locksBelow($surface);
  }

  /**
   * Answers locks() for a data surface not asked about before.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface.
   *
   * @return bool
   *   TRUE when a key of it, or of a child inside it, is locked or marked.
   */
  protected function locksBelow(DataSurfaceInterface $surface): bool {
    foreach ($surface->getDefinitions()->entries() as $entry) {
      $children = match (TRUE) {
        $entry->variants !== NULL => $entry->variants->variants,
        $entry->attachment !== NULL => [$entry->attachment],
        $entry->list !== NULL => [$entry->list->item],
        default => [],
      };
      // A key a runtime access answer may still lock or leave out is
      // marked at its site, so it is said there too.
      if ($entry->locked || $entry->runtimeAccess || array_filter($children, fn (Attachment $child): bool => $this->locks($child->child)) !== []) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Writes the reference a site makes to a def, narrowed where it must be.
   *
   * A site whose instance says what its def says refers to it and nothing
   * more. One that differs (a key the site's context locked, a key
   * refined for the values in this document) composes the def with what
   * differs: `allOf: [{$ref}, {narrowing}]`, the narrowing holding only
   * the keywords that changed. Refinement only narrows, so a validator
   * applying both accepts what the instance accepts.
   *
   * @param string $key
   *   The def's key.
   * @param array|null $instance
   *   The child's schema at this site, or NULL to refer to the def alone.
   * @param \Drupal\data_surface\Contract\Subschemas|null $subschemas
   *   The document's defs, to compare the instance with.
   *
   * @return array
   *   `$ref`, or `allOf` of it and the narrowing.
   */
  protected function reference(string $key, ?array $instance = NULL, ?Subschemas $subschemas = NULL): array {
    $reference = ['$ref' => self::DEFS . self::pointer($key)];
    // A def written open, for an owner to mix it in, is closed where it is
    // used whole. Decision: see docs/decisions.md#a-mixin-is-merged-flat.
    $closed = $subschemas !== NULL && $subschemas->isOpen($key) ? ['unevaluatedProperties' => FALSE] : [];
    $def = $subschemas?->get($key);
    if ($instance === NULL || $def === NULL) {
      return $reference + $closed;
    }
    if ($closed !== []) {
      // The site closes it: the narrowing says only what differs.
      unset($instance['additionalProperties'], $instance['unevaluatedProperties']);
    }
    $narrowing = $this->narrowing($instance, $def, $subschemas->transient);
    return ($narrowing === [] ? $reference : ['allOf' => [$reference, $narrowing]]) + $closed;
  }

  /**
   * Keeps only what an instance says differently from its def.
   *
   * The same reading as narrowed(), for a whole frame: a frame's
   * properties are compared one by one; its conditionals keep only those
   * the def does not already state; and a property's `x-surface`, which
   * a renderer reads whole, is written whole wherever it differs, less
   * what the document strips anyway.
   *
   * @param array $instance
   *   The instance.
   * @param array $def
   *   The def.
   * @param string[] $transient
   *   The `x-surface` keywords not compared.
   *
   * @return array
   *   The keywords that differ; empty when none do.
   */
  protected function narrowing(array $instance, array $def, array $transient): array {
    $narrowing = [];
    foreach ($instance as $keyword => $value) {
      if ($keyword === self::EXTENSION) {
        $kept = array_diff_key((array) $value, array_flip($transient));
        if (!Subschemas::same($kept, array_diff_key((array) ($def[$keyword] ?? []), array_flip($transient)))) {
          $narrowing[$keyword] = $value;
        }
        continue;
      }
      if ($keyword === 'properties' && (is_array($value) || $value instanceof \stdClass) && is_array($def['properties'] ?? NULL)) {
        // A frame with no key left that applies writes its properties as
        // an empty object.
        $value = (array) $value;
        $properties = [];
        foreach ($def['properties'] + $value as $name => $property) {
          if (!array_key_exists($name, $value)) {
            // Ruled out at this site: it must not be there.
            $properties[$name] = FALSE;
            continue;
          }
          if (!is_array($value[$name]) || !is_array($def['properties'][$name] ?? [])) {
            // A mixed-in structure's member, `true` or `false`, said whole.
            if (!Subschemas::same($value[$name], $def['properties'][$name] ?? NULL)) {
              $properties[$name] = $value[$name];
            }
            continue;
          }
          $changed = $this->narrowing($value[$name], $def['properties'][$name] ?? [], $transient);
          if ($changed !== []) {
            $properties[$name] = $changed;
          }
        }
        if ($properties !== []) {
          $narrowing['properties'] = $properties;
        }
        continue;
      }
      if ($keyword === 'allOf' && is_array($value) && is_array($def['allOf'] ?? NULL)) {
        $added = array_values(array_filter($value, static fn (mixed $condition): bool => array_filter($def['allOf'], static fn (mixed $stated): bool => Subschemas::same($stated, $condition)) === []));
        if ($added !== []) {
          $narrowing['allOf'] = $added;
        }
        continue;
      }
      if ($keyword === 'required' && is_array($value) && is_array($def['required'] ?? NULL)) {
        // JSON Schema holds a document to every `required` it meets: the
        // def's already holds, so the narrowing says what it adds.
        $added = array_values(array_diff($value, $def['required']));
        if ($added !== []) {
          $narrowing['required'] = $added;
        }
        continue;
      }
      if (!array_key_exists($keyword, $def) || !Subschemas::same($def[$keyword], $value)) {
        $narrowing[$keyword] = $value;
      }
    }
    return $narrowing;
  }

  /**
   * Spells a def's key as a JSON pointer segment inside a URI fragment.
   *
   * @param string $key
   *   The key.
   *
   * @return string
   *   The segment: `~` and `/` escaped as JSON Pointer says, and anything
   *   a URI fragment cannot carry percent-encoded.
   */
  public static function pointer(string $key): string {
    $escaped = str_replace(['~', '/'], ['~0', '~1'], $key);
    return (string) preg_replace_callback('/[^A-Za-z0-9\-._~!$&\'()*+,;=:@]/', static fn (array $match): string => rawurlencode($match[0]), $escaped);
  }

  /**
   * Gives the document its `$defs`, when it has any.
   *
   * @param \Drupal\data_surface\Contract\Subschemas $subschemas
   *   The defs written.
   *
   * @return array
   *   `$defs`, or nothing.
   */
  protected function definitions(Subschemas $subschemas): array {
    $defs = $subschemas->all();
    return $defs === [] ? [] : ['$defs' => $defs];
  }

  /**
   * Describes variants: their union, and one conditional per variant.
   *
   * The key's own property says what holds whatever is chosen: its
   * label, and that it is one of the variants (`anyOf` of a reference to
   * each variant's def, and null where the key may be cleared, so a type
   * generator writes the union; an object with no keys where there are
   * no variants yet), and, under
   * `x-surface`, which key decides, each value's def, and which variant
   * is chosen now. Each variant's shape is a `then` on the parent, under
   * an `if` naming the deciding key's value as a `const`, so a validator,
   * and the React app, read the shape the chosen value selects: the union
   * is never narrower than the conditional that applies. The `then`
   * refers to the variant's def, beside the key's label, and carries no
   * `x-surface`; the chosen variant is described refined against what
   * the key holds, which narrows its reference where that differs, and
   * the others as they are advertised.
   * Decision: see docs/decisions.md#contract-2.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $declared
   *   The data surface the variants belong to.
   * @param \Drupal\data_surface\Entry $entry
   *   The entry of the key the variants are attached at.
   * @param array $values
   *   The values of the variants' frame.
   * @param string $path
   *   The key's dotted path.
   * @param string $owner
   *   The id of the resource or structure the variants belong to.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the description depends on.
   * @param string[] $stale
   *   Collects the dotted paths of stale values.
   * @param \Drupal\data_surface\Contract\Subschemas $subschemas
   *   The document's defs, and whether it is the full schema: every
   *   variant is then described from its own locked keys, none as the
   *   chosen one.
   * @param array<string, mixed> $standing
   *   The orphans of a refinement, by dotted path from the top; only the
   *   chosen variant's are its own.
   * @param bool $declaring
   *   Whether the variants are part of a def: every variant is then a bare
   *   reference, and a locked deciding key chooses nothing.
   * @param bool $frozen
   *   Whether the variants are inside a child locked whole: every variant is
   *   then described locked.
   * @param bool $blank
   *   Whether the variants are inside a collection's items: no variant is
   *   then chosen, and every one is described from its locked keys.
   *
   * @return array{0: array, 1: mixed, 2: array}
   *   The key's property schema, its value as shown, and the
   *   conditionals for the parent's `allOf`.
   */
  protected function variants(DataSurfaceInterface $declared, Entry $entry, array $values, string $path, string $owner, CacheableMetadata $cacheability, array &$stale, Subschemas $subschemas, array $standing = [], bool $declaring = FALSE, bool $frozen = FALSE, bool $blank = FALSE): array {
    $full = $subschemas->full;
    $variants = $entry->variants;
    assert($variants !== NULL);
    $by = $declared->getDefinitions()->get($variants->by);
    $deciding = $values[$variants->by] ?? ($declaring && $declared->isLocked($variants->by) ? NULL : $declared->getDefault($variants->by));
    $chosen = $variants->chosen($deciding);
    $held = $values[$entry->name] ?? NULL;
    $shell = $this->describe($variants->shell);
    $property = $shell + ['type' => $this->nullable('object', $variants->shell)];
    $depends = array_values(array_unique([$variants->by, ...$entry->dependencies]));
    if ($frozen) {
      $property['readOnly'] = TRUE;
    }
    $property[self::EXTENSION] = $this->extension('variants', $frozen, $depends, $chosen !== NULL) + [
      'by' => $variants->by,
      'variants' => [],
      'chosen' => $chosen,
    ];
    $union = [];
    // Variants nothing has chosen keep what the key holds: it has no
    // element, so it says nothing about it.
    $shown = $held;
    $branches = [];
    foreach ($variants->variants as $id => $variant) {
      $id = (string) $id;
      $child = $variant->child;
      $fallback = $owner . '.' . $entry->name . '.' . $id;
      $key = $this->define($variant, $fallback, $cacheability, $subschemas);
      $is_chosen = !$full && !$declaring && !$blank && $id === $chosen;
      if ($declaring) {
        $instance = $subschemas->get($key) ?? [];
        $reference = $this->reference($key, NULL, $subschemas);
      }
      else {
        $own = $is_chosen ? ($variants->held($id, $held) ?? []) : [];
        $variant_stale = [];
        $inside = $full || $blank ? $this->known($child) : array_replace($child->getDefaultValues(), $own);
        [$instance, $variant_values] = $this->instance($child, $key, $inside, $path . '.', $variant->id ?? $fallback, $cacheability, $variant_stale, $subschemas, $is_chosen ? $standing : [], $frozen, $blank);
        if ($is_chosen) {
          $shown = $variant_values;
          array_push($stale, ...$variant_stale);
        }
        $reference = $this->reference($key, $instance, $subschemas);
      }
      $schema = $shell + $reference;
      // Which def each value chooses, said on the key: nothing below the
      // `then` carries `x-surface`.
      $property[self::EXTENSION]['variants'][$id] = self::DEFS . self::pointer($key);
      $union[] = $this->reference($key, NULL, $subschemas);
      $branches[] = [
        'if' => [
          'properties' => [$variants->by => ['const' => $by === NULL ? $id : $this->cast($id, $by)]],
          'required' => [$variants->by],
        ],
        'then' => [
          'properties' => [$entry->name => $schema],
        ],
      ];
    }
    if ($union !== []) {
      if (in_array('null', (array) $property['type'], TRUE)) {
        $union[] = ['type' => 'null'];
      }
      // The union says the type: each variant's def is an object, and
      // null is its own member. A `type` beside it would only be
      // intersected with it by a type generator.
      $extension = $property[self::EXTENSION];
      unset($property[self::EXTENSION], $property['type']);
      $property += ['anyOf' => $union, self::EXTENSION => $extension];
    }
    // A map whose keys are numeric strings is still a map.
    $property[self::EXTENSION]['variants'] = (object) $property[self::EXTENSION]['variants'];
    return [$property, $shown, $branches];
  }

  /**
   * States what a key's refiners answer for every value they could see.
   *
   * Static refiners are pure functions of the values they watch, so where
   * every sibling they watch that holds nothing yet offers a finite list
   * of values, the refined key can be written down for each combination
   * ahead of time: one conditional per combination, on the parent, its
   * `if` naming each watched sibling's value as a `const` and its `then`
   * what the key's schema says differently from the unrefined one. A
   * watched sibling that holds a value is held at it.
   *
   * Each combination is answered by refining the whole data surface
   * against the values with the combination over them, the call /refine
   * makes with the runtime refiners left out, so a conditional states
   * exactly what /refine would answer less what only the runtime knows. A
   * runtime refiner is never called here: what it answers is not a
   * function of the combination, and the key carries `live` instead.
   * With assertions on, the first combination is refined a second time
   * and must answer the same (answersAgain()).
   * Decision: see
   * docs/decisions.md#static-by-default-runtime-by-declaration.
   * Siblings are taken in declaration order, and each one's list is read
   * from the data surface refined for the combination so far, so a chain
   * composes: a bundle's list is read under each entity type.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $declared
   *   The data surface of this frame, as advertised.
   * @param \Drupal\data_surface\DataSurfaceInterface $refined
   *   The same data surface refined against the frame's values by its
   *   static refiners alone.
   * @param \Drupal\data_surface\Entry $entry
   *   The key, unrefined.
   * @param array $values
   *   The values of the frame.
   * @param array $base
   *   The key's schema as emitted, unrefined.
   * @param string $path
   *   The key's dotted path.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what every list read and every refinement depends on.
   * @param int $kinds
   *   The kinds of refiner each combination is answered by: the coded
   *   ones for the full schema, which states a declared rule as written,
   *   and the declared ones beside them for one set of values, which a
   *   declared rule is evaluated for.
   * @param bool $applicability
   *   Whether only whether the key applies is stated: a key holding a
   *   child, whose parent may say nothing else about it.
   *
   * @return array{branches: array, base: array, required: bool}|null
   *   The conditionals, none for a combination that changes nothing; the
   *   key's schema with what may not apply inside it no longer required;
   *   and whether the frame may require the key, which it may not where
   *   any combination rules it out. NULL when the key cannot be
   *   enumerated: it watches a key an alter mounted, a list, a child or a
   *   key with no list of values, or it would take more than
   *   ENUMERATION_CAP combinations.
   */
  protected function enumerate(DataSurfaceInterface $declared, DataSurfaceInterface $refined, Entry $entry, array $values, array $base, string $path, CacheableMetadata $cacheability, int $kinds = DataSurfaceInterface::STATIC, bool $applicability = FALSE): ?array {
    $order = array_flip($declared->getDefinitions()->names());
    $ports = $declared->getDefinitions()->ports();
    $watched = $entry->codedDependencies();
    foreach ($watched as $i => $key) {
      if (!isset($order[$key])) {
        if (isset($ports[$key]) && ValueState::isConfigured($values[$key] ?? NULL)) {
          // A port its container bound holds its value, as a held
          // sibling does.
          unset($watched[$i]);
          continue;
        }
        // A key an alter mounted, by its dotted path: a free value, or one
        // a conditional on the parent cannot name without restating the
        // mount around it.
        return NULL;
      }
    }
    $watched = array_values($watched);
    usort($watched, static fn (string $a, string $b): int => $order[$a] <=> $order[$b]);
    $combinations = [[[], $refined]];
    foreach ($watched as $key) {
      $next = [];
      foreach ($combinations as [$combination, $surface]) {
        $domain = $this->domain($surface, $key, $values, $cacheability);
        if ($domain === NULL) {
          return NULL;
        }
        foreach ($domain as $value) {
          if (count($next) === self::ENUMERATION_CAP) {
            return NULL;
          }
          $next[] = [$combination + [$key => $value], $surface];
        }
      }
      foreach ($next as $i => [$combination]) {
        $next[$i][1] = $this->refinedFor($declared, array_replace($values, $combination), $kinds);
        $cacheability->addCacheableDependency($next[$i][1]);
      }
      $combinations = $next;
    }

    // What each combination answers: the key's schema, FALSE where it does
    // not apply, and what may not apply inside it, for the base to stop
    // requiring.
    // Decision: see docs/decisions.md#a-key-that-does-not-apply.
    $answers = [];
    $ruled = FALSE;
    $vanishing = [];
    foreach ($combinations as $i => [$combination, $surface]) {
      $definition = $surface->getDefinition($entry->name) ?? $entry->definition;
      $schema = $this->answer($entry, $definition, $path, $cacheability, $applicability);
      // In development and tests only, and for one combination per key:
      // asked again, a static refiner answers the same.
      assert($i > 0 || $this->answersAgain($declared, $entry, array_replace($values, $combination), $schema, $path, $cacheability, $kinds, $applicability));
      if ($schema === FALSE) {
        $ruled = TRUE;
      }
      else {
        array_push($vanishing, ...static::vanished($base, $schema));
      }
      $answers[] = [$combination, $definition, $schema];
    }
    $base = static::requiringNone($base, $vanishing);
    $required = !$ruled && static::required($entry->definition);

    $branches = [];
    foreach ($answers as [$combination, $definition, $schema]) {
      $then = [];
      if ($schema === FALSE) {
        $then['properties'] = [$entry->name => FALSE];
      }
      else {
        $narrowed = $applicability ? [] : $this->narrowed($schema, $base);
        if ($narrowed !== []) {
          $then['properties'] = [$entry->name => $narrowed];
        }
        if (static::required($definition) && !$required) {
          // Required here, and only here: a then may say so.
          $then['required'] = [$entry->name];
        }
      }
      if ($then === []) {
        continue;
      }
      $conditions = [];
      foreach ($combination as $key => $value) {
        $conditions[$key] = ['const' => $value];
      }
      $branches[] = [
        'if' => [
          'properties' => $conditions,
          'required' => array_keys($combination),
        ],
        'then' => $then,
      ];
    }
    return ['branches' => $branches, 'base' => $base, 'required' => $required];
  }

  /**
   * Describes what a key answers for one combination, as enumerate() reads it.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   Its definition, refined for the combination.
   * @param string $path
   *   The key's dotted path.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the description depends on.
   * @param bool $applicability
   *   Whether only whether it applies is asked: a key holding a child,
   *   whose parent may say nothing else about it.
   *
   * @return array|false
   *   The key's schema, FALSE where it does not apply.
   */
  protected function answer(Entry $entry, DataDefinitionInterface $definition, string $path, CacheableMetadata $cacheability, bool $applicability = FALSE): array|false {
    if (DoesNotApply::marks($definition)) {
      return FALSE;
    }
    return $applicability ? [] : $this->stated($entry, $definition, $path, $cacheability);
  }

  /**
   * Lists the properties a base states that an answer leaves out.
   *
   * @param array $base
   *   The key's schema, unrefined.
   * @param array $schema
   *   What one combination answered.
   * @param string $prefix
   *   The path so far, as property names joined by dots.
   *
   * @return string[]
   *   The dotted paths of the properties the answer ruled out.
   */
  protected static function vanished(array $base, array $schema, string $prefix = ''): array {
    $vanished = [];
    $properties = is_array($schema['properties'] ?? NULL) ? $schema['properties'] : [];
    foreach (is_array($base['properties'] ?? NULL) ? $base['properties'] : [] as $name => $property) {
      $path = $prefix . $name;
      if (!array_key_exists($name, $properties)) {
        $vanished[] = $path;
        continue;
      }
      if (is_array($property) && is_array($properties[$name])) {
        array_push($vanished, ...static::vanished($property, $properties[$name], $path . '.'));
      }
    }
    return $vanished;
  }

  /**
   * Takes properties that may not apply out of a schema's `required`.
   *
   * A key required whenever it applies is required only under the
   * conditionals where it applies; required everywhere, the schema would
   * refuse it absent where the pipeline does not ask for it.
   *
   * @param array $schema
   *   A key's schema.
   * @param string[] $paths
   *   Dotted paths of properties below it.
   *
   * @return array
   *   The schema, none of them in a `required`.
   */
  protected static function requiringNone(array $schema, array $paths): array {
    foreach (array_unique($paths) as $path) {
      $parents = explode('.', $path);
      $name = array_pop($parents);
      $at = [];
      foreach ($parents as $parent) {
        array_push($at, 'properties', $parent);
      }
      $object = $at === [] ? $schema : NestedArray::getValue($schema, $at);
      if (!is_array($object) || !is_array($object['required'] ?? NULL)) {
        continue;
      }
      $required = array_values(array_diff($object['required'], [$name]));
      if ($required === []) {
        NestedArray::unsetValue($schema, [...$at, 'required']);
      }
      else {
        NestedArray::setValue($schema, [...$at, 'required'], $required);
      }
    }
    return $schema;
  }

  /**
   * Describes a key the way a conditional is diffed from.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   Its definition: as declared, or refined for a combination.
   * @param string $path
   *   The key's dotted path.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the description depends on.
   *
   * @return array
   *   A collection's own keywords, its items being the child's, which no
   *   parent's refiner reaches; any other key's property schema.
   */
  protected function stated(Entry $entry, DataDefinitionInterface $definition, string $path, CacheableMetadata $cacheability): array {
    $ignored = [];
    return $entry->list !== NULL
      ? $this->listKeywords($definition)[0]
      : $this->property($definition, NULL, NULL, $path, FALSE, [], $cacheability, $ignored)[0];
  }

  /**
   * Describes one key as a conditional on it states it, in plain schema.
   *
   * What an agreement check compares: the key as declared, with a
   * conditional's `then` laid over it, against the key refined afresh
   * for the conditional's values, each described by this.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The key, from the data surface's definition map.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $definition
   *   The definition to describe; NULL for the entry's own.
   *
   * @return array|false
   *   The schema, with no `x-surface` at any depth; FALSE for a key that
   *   does not apply.
   */
  public function describeKey(Entry $entry, ?DataDefinitionInterface $definition = NULL): array|false {
    $definition ??= $entry->definition;
    return DoesNotApply::marks($definition) ? FALSE : $this->withoutExtension($this->stated($entry, $definition, $entry->name, new CacheableMetadata()));
  }

  /**
   * Refines a surface for one combination, once per document.
   *
   * Static refiners are pure functions of what they watch, which
   * answersAgain() holds them to, so the same surface refined against the
   * same values by the same kinds answers the same: keys that watch the
   * same sibling (a field's settings and its storage's settings both watch
   * its type) share one refine per combination instead of one each.
   *
   * Decision: see docs/decisions.md#what-is-paid-once-and-what-per-request.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $declared
   *   The data surface of the frame, as advertised.
   * @param array $values
   *   The values, with the combination over them.
   * @param int $kinds
   *   The kinds of refiner.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The refined surface.
   */
  protected function refinedFor(DataSurfaceInterface $declared, array $values, int $kinds): DataSurfaceInterface {
    try {
      $key = serialize([$kinds, $values]);
    }
    catch (\Throwable) {
      $key = NULL;
    }
    if ($this->refined === NULL || $key === NULL) {
      $this->refineCounts['refined']++;
      return $declared->refine($values, $kinds);
    }
    $kept = $this->refined[$declared] ?? [];
    if (isset($kept[$key])) {
      $this->refineCounts['reused']++;
      return $kept[$key];
    }
    $this->refineCounts['refined']++;
    $kept[$key] = $declared->refine($values, $kinds);
    $this->refined[$declared] = $kept;
    return $kept[$key];
  }

  /**
   * Reports how many combinations were refined and how many remembered.
   *
   * For a performance budget, never for behavior: counts since the
   * service was made.
   *
   * @return array{refined: int, reused: int}
   *   The counts.
   *
   * @internal
   */
  public function refineCounts(): array {
    return $this->refineCounts;
  }

  /**
   * Refines once more for one combination, and refuses a different answer.
   *
   * A static refiner is a pure function of what it watches, so asked
   * again for the same values it answers the same; one that read
   * something else (a counter, the time, storage that changed between the
   * two calls) does not, and its conditional would be a schema that lies.
   * Called under assert() only, for the first combination of each key
   * enumerated, so a hidden dependency shows in development at the cost
   * of one refinement per key, and production pays nothing.
   *
   * Decision: see docs/decisions.md#static-by-default-runtime-by-declaration.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $declared
   *   The data surface of this frame, as advertised.
   * @param \Drupal\data_surface\Entry $entry
   *   The key, unrefined.
   * @param array $values
   *   The values of the frame with the combination laid over them.
   * @param array|false $schema
   *   What the first refinement answered for the key; FALSE where it does
   *   not apply.
   * @param string $path
   *   The key's dotted path.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the description depends on.
   * @param int $kinds
   *   The kinds of refiner the combination was answered by.
   * @param bool $applicability
   *   Whether only whether the key applies was asked.
   *
   * @return bool
   *   TRUE; a disagreement throws.
   *
   * @throws \LogicException
   *   Naming the key's static refiners and the values.
   */
  protected function answersAgain(DataSurfaceInterface $declared, Entry $entry, array $values, array|false $schema, string $path, CacheableMetadata $cacheability, int $kinds = DataSurfaceInterface::STATIC, bool $applicability = FALSE): bool {
    $again = $this->answer($entry, $declared->refine($values, $kinds)->getDefinition($entry->name) ?? $entry->definition, $path, $cacheability, $applicability);
    if (json_encode($again === FALSE ? FALSE : $this->withoutExtension($again)) === json_encode($schema === FALSE ? FALSE : $this->withoutExtension($schema))) {
      return TRUE;
    }
    $refiners = [];
    foreach ($entry->refiners as $chain) {
      foreach ($chain as $link) {
        if ($link instanceof RefinesInputRefiner) {
          array_push($refiners, ...$link->describes($entry->name));
        }
      }
    }
    throw new \LogicException(sprintf(
      'The refiner %s is declared static but answered differently when run again, for "%s" with %s: a refiner whose answer depends on anything but the siblings it watches must declare runtime: TRUE.',
      $refiners === [] ? 'of "' . $entry->name . '"' : implode(' or ', $refiners),
      $path,
      json_encode(array_intersect_key($values, array_flip($entry->codedDependencies()))),
    ));
  }

  /**
   * Lists the values a watched sibling can take, to enumerate over.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface, refined for the combination so far.
   * @param string $key
   *   The watched sibling.
   * @param array $values
   *   The values of the frame.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the list's cacheability.
   *
   * @return array|null
   *   The one value it holds, or every value its list offers, each in its
   *   key's type; NULL when it holds nothing and offers no list: a free
   *   value, a list of values, a child, or more values than
   *   ENUMERATION_CAP.
   */
  protected function domain(DataSurfaceInterface $surface, string $key, array $values, CacheableMetadata $cacheability): ?array {
    $entry = $surface->getDefinitions()->entry($key);
    if ($entry === NULL || $entry->isNested() || $entry->definition instanceof ListDataDefinitionInterface) {
      return NULL;
    }
    $value = $values[$key] ?? NULL;
    if (ValueState::isConfigured($value)) {
      return [is_int($value) || is_string($value) ? $this->cast($value, $entry->definition) : $value];
    }
    $set = $this->optionSet($entry->definition, $cacheability);
    if ($set === NULL || count($set->options) > self::ENUMERATION_CAP) {
      return NULL;
    }
    return array_map(fn (int|string $option): int|float|string|bool => $this->cast($option, $entry->definition), array_keys($set->options));
  }

  /**
   * Keeps only what a refined schema says differently from the unrefined.
   *
   * A conditional applies on top of the unrefined schema, and refinement
   * only narrows, so what changed is all it needs to say: the narrowed
   * `oneOf`, the lower `maximum`, the description naming the reason. A
   * map's properties are compared one by one.
   *
   * @param array $schema
   *   The refined schema.
   * @param array $base
   *   The unrefined schema.
   *
   * @return array
   *   The keywords that differ, without `x-surface`; empty when none do.
   */
  protected function narrowed(array $schema, array $base): array {
    $narrowed = [];
    foreach ($schema as $keyword => $value) {
      if ($keyword === self::EXTENSION) {
        continue;
      }
      if ($keyword === 'properties' && is_array($value) && is_array($base['properties'] ?? NULL)) {
        $properties = [];
        foreach ($base['properties'] + $value as $name => $property) {
          if (!array_key_exists($name, $value)) {
            // Ruled out for this combination: it must not be there.
            // Decision: see docs/decisions.md#a-key-that-does-not-apply.
            $properties[$name] = FALSE;
            continue;
          }
          $changed = $this->narrowed($value[$name], $base['properties'][$name] ?? []);
          if ($changed !== []) {
            $properties[$name] = $changed;
          }
        }
        if ($properties !== []) {
          $narrowed['properties'] = $properties;
        }
        continue;
      }
      if (!array_key_exists($keyword, $base) || $base[$keyword] !== $value) {
        $narrowed[$keyword] = is_array($value) ? $this->withoutExtension($value) : $value;
      }
    }
    return $narrowed;
  }

  /**
   * Describes one key that is not a child, recursing through maps.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key as it stands, refined.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $advertised
   *   The key as advertised, to tell whether it is narrowed now; NULL
   *   when there is nothing to compare with.
   * @param mixed $value
   *   The value the key's element would be built with.
   * @param string $path
   *   The key's dotted path.
   * @param bool $locked
   *   Whether the key is locked.
   * @param string[] $depends
   *   The sibling keys its refiners watch; a mounted one by its dotted
   *   path in the frame.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the description depends on.
   * @param string[] $stale
   *   Collects the dotted paths of stale values.
   *
   * @return array{0: array, 1: mixed}
   *   The property schema, and its value as shown.
   */
  protected function property(DataDefinitionInterface $definition, ?DataDefinitionInterface $advertised, mixed $value, string $path, bool $locked, array $depends, CacheableMetadata $cacheability, array &$stale): array {
    $schema = $this->describe($definition);
    $shown = $value;
    $set = $this->optionSet($definition instanceof ListDataDefinitionInterface ? $definition->getItemDefinition() : $definition, $cacheability);
    // A list of no values is still a list: a select offering nothing.
    // Decision: see docs/decisions.md#a-key-that-does-not-apply.
    $listed = $set !== NULL;
    $refined = $advertised !== NULL && $this->signature($advertised) !== $this->signature($definition);

    if ($definition instanceof ListDataDefinitionInterface) {
      $item = $definition->getItemDefinition();
      $ignored = [];
      [$items] = $this->property($item, NULL, NULL, $path . '.*', FALSE, [], $cacheability, $ignored);
      $schema += ['type' => $this->nullable('array', $definition), 'items' => static::neverNull($items)] + $this->keywords($definition, 'array');
      // Decision: see docs/decisions.md#a-widget-is-chosen-from-the-definition.
      $checkboxes = $set !== NULL && CheckboxesWidget::drawsList($definition, $set);
      $extension = match (TRUE) {
        $checkboxes => $this->extension('checkboxes', $locked, $depends, $refined),
        $listed => $this->extension('select', $locked, $depends, $refined) + ['multiple' => TRUE],
        default => $this->extension('list', $locked, $depends, $refined),
      };
      $shown = is_array($value) ? array_values($value) : $value;
    }
    elseif (!$listed && $definition instanceof ComplexDataDefinitionInterface && $definition->getPropertyDefinitions() !== []) {
      $properties = [];
      $required = [];
      $nested = [];
      $before = $advertised instanceof ComplexDataDefinitionInterface ? $advertised->getPropertyDefinitions() : [];
      foreach ($definition->getPropertyDefinitions() as $name => $property) {
        $name = (string) $name;
        if (DoesNotApply::marks($property)) {
          // A key an alter mounted, ruled out: as at the top of a frame.
          continue;
        }
        [$properties[$name], $nested[$name]] = $this->property($property, $before[$name] ?? NULL, is_array($value) ? ($value[$name] ?? NULL) : NULL, $path . '.' . $name, FALSE, [], $cacheability, $stale);
        if (static::required($property)) {
          $required[] = $name;
        }
      }
      $schema += [
        'type' => $this->nullable('object', $definition),
        // Every key ruled out leaves an object still.
        'properties' => $properties === [] ? new \stdClass() : $properties,
        'additionalProperties' => FALSE,
      ];
      if ($required !== []) {
        $schema['required'] = $required;
      }
      $extension = $this->extension('fieldset', $locked, $depends, $refined || $this->anyRefined($schema));
      if (DefinitionMetadata::isGrouping($definition)) {
        // Only groups the fieldsets inside it, as on the form: no title
        // and no description to draw, and `group` says to draw none.
        unset($schema['title'], $schema['description']);
        $extension['group'] = TRUE;
      }
      $after = DefinitionMetadata::getPlacedAfter($definition);
      if ($after !== NULL) {
        // A module's fieldset its alter placed: drawn after that key of
        // the frame its group sits in. Only drawn there; its properties
        // stay where its values are posted.
        $extension['after'] = $after;
      }
      // A map whose every key is ruled out is still a map.
      $shown = $nested === [] ? new \stdClass() : $nested;
    }
    else {
      $type = $this->type($definition);
      if (isset($type['type'])) {
        $type['type'] = $this->nullable($type['type'], $definition);
      }
      $schema += $type + $this->keywords($definition, $type['type'] ?? NULL);
      $radios = $listed && RadiosWidget::drawsOne($definition);
      $fixed = $listed && OptionsWidget::drawsFixed($definition, $set, $value);
      // A reference with too many candidates to list is looked up.
      // Decision: see
      // docs/decisions.md#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up.
      $widget = match (TRUE) {
        $fixed => 'fixed',
        $listed => $radios ? 'radios' : 'select',
        // A bare reference's id is drawn once its entity type is chosen:
        // listed, or looked up.
        AutocompleteWidget::draws($definition), $definition->getSetting(EntityReferenceStructure::TARGETS) !== NULL => 'autocomplete',
        default => $this->widget($definition),
      };
      $extension = $this->extension($widget, $locked, $depends, $refined);
      $reference = static::referenceMarker($definition);
      if ($reference !== NULL) {
        $extension['reference'] = $reference;
      }
      if ($fixed) {
        // A deciding key whose variants offer one value: that value, and
        // the label it is offered as, which the form draws fixed.
        // Decision: see
        // docs/decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter.
        $only = $this->choices($set, $definition)[0];
        $schema['const'] = $only['const'];
        $extension['fixed'] = $only['title'];
      }
      elseif ($listed) {
        $schema['oneOf'] = $this->choices($set, $definition);
        // Decision: see docs/decisions.md#the-empty-option-rule.
        $configured = ValueState::isConfigured($value);
        $chosen = $configured && $set->allows($value);
        $holds = DefinitionMetadata::holdsValue($definition);
        // Radios show nothing checked where a select shows its empty
        // option, so their "- None -" is there only where choosing
        // nothing is an answer.
        // A select standing for a stale value says it keeps it, as the
        // form's does; frame() words an orphan's as it was.
        $extension['emptyOption'] = [
          'show' => $radios ? !$holds : !($chosen && $holds),
          'label' => (string) match (TRUE) {
            !$radios && $configured && !$chosen => OptionsWidget::keepLabel($value),
            $holds && !$radios => $this->t('- Select -'),
            default => $this->t('- None -'),
          },
        ];
        if ($configured && !$chosen) {
          // Shown on the empty option, standing for the stored value,
          // which stays on the server: the caller sends the path back
          // and an empty answer there keeps what is stored.
          $extension['stale'] = TRUE;
          $stale[] = $path;
          $shown = NULL;
        }
      }
    }

    if (DefinitionMetadata::isSecret($definition)) {
      $schema['writeOnly'] = TRUE;
    }
    elseif (!$definition instanceof ComplexDataDefinitionInterface) {
      $default = DefinitionMetadata::hasDefaultValue($definition) ? DefinitionMetadata::getDefaultValue($definition) : NULL;
      if ($default !== NULL) {
        $schema['default'] = $default;
      }
      $examples = DefinitionMetadata::getExamples($definition);
      if ($examples !== []) {
        $schema['examples'] = array_values($examples);
      }
    }
    if ($locked) {
      $schema['readOnly'] = TRUE;
      if (ValueState::isConfigured($value)) {
        $schema['const'] = $value;
      }
    }
    $computed = Computed::of($definition);
    if ($computed !== NULL && !$locked) {
      // Shown, never offered: a value nobody types, with no `const`, since
      // what it holds follows the keys it is computed from.
      // Decision: see
      // docs/decisions.md#a-computed-key-is-a-pure-function-of-its-sources.
      $schema['readOnly'] = TRUE;
      $extension['computed'] = $computed->marker();
    }
    if (isset($schema[self::EXTENSION . '-checked'])) {
      $extension['checkedOnServer'] = $schema[self::EXTENSION . '-checked'];
      unset($schema[self::EXTENSION . '-checked']);
    }
    if (isset($schema[self::EXTENSION . '-pattern-message'])) {
      $extension['patternMessage'] = $schema[self::EXTENSION . '-pattern-message'];
      unset($schema[self::EXTENSION . '-pattern-message']);
    }
    $arranged = $this->arrangement($definition);
    if ($arranged !== []) {
      // A widget hint: how the key is drawn, never what it accepts.
      // Decision: see docs/decisions.md#arrangement-is-declared-once.
      $extension['arrangement'] = $arranged;
    }
    $schema[self::EXTENSION] = $extension;
    $shape = DefinitionMetadata::shapeDefinition($definition);
    if ($shape !== NULL && !isset($schema['properties']) && !isset($schema['items'])) {
      // Written whole here, so a conditional, a site and a def compare it
      // keyword by keyword as any key; named() refers to the shape at the
      // end. Decision: see docs/decisions.md#a-shape-is-a-named-definition.
      $ignored = [];
      $schema[self::SHAPED] = [
        'id' => (string) DefinitionMetadata::shapeOf($definition),
        'def' => $this->shapeDef($this->property($shape, NULL, NULL, $path, FALSE, [], $cacheability, $ignored)[0]),
      ];
    }
    return [$schema, $shown];
  }

  /**
   * Keeps what a shape's def says: the shape's own, not a key's.
   *
   * The shape is described as an optional key is, so its def allows null,
   * since whether a key may hold nothing is the key's; a required key's
   * site says `type` without it. Of `x-surface`, only what the shape
   * checks is kept: the message of its pattern and what only the server
   * checks. The widget, the lock and what a key depends on are each
   * key's.
   *
   * @param array $schema
   *   The shape, described as a key.
   *
   * @return array
   *   Its def.
   */
  protected function shapeDef(array $schema): array {
    $extension = array_intersect_key($schema[self::EXTENSION] ?? [], array_flip(['patternMessage', 'checkedOnServer']));
    unset($schema[self::EXTENSION]);
    if ($extension !== []) {
      $schema[self::EXTENSION] = $extension;
    }
    return $schema;
  }

  /**
   * Refers every key declared with a shape to the shape's def.
   *
   * Each shape is written once, under `$defs` by its id, as its alters
   * left it, and each key declared with it becomes `$ref` to it first,
   * then the key's title, then what the key says differently from the
   * def (its type without null where it is required, a default, a
   * constraint it narrows, a description of its own) and its whole
   * `x-surface`, as every property's. `$ref` first, so a reader that lays
   * the def where the reference stood lays the key over it. A key a
   * refinement or a conditional narrows is compared as written, so a
   * `then` says only what it narrows and refers to nothing.
   *
   * Decision: see docs/decisions.md#a-shape-is-a-named-definition.
   *
   * @param array $schema
   *   The document's schema, its `$defs` written.
   *
   * @return array
   *   The schema, each shape a def.
   */
  protected function named(array $schema): array {
    $defs = is_array($schema['$defs'] ?? NULL) ? $schema['$defs'] : [];
    $named = [];
    $schema = $this->referToShapes($schema, $named, array_keys($defs));
    if ($named !== []) {
      $schema['$defs'] = (is_array($schema['$defs'] ?? NULL) ? $schema['$defs'] : []) + $named;
    }
    return $schema;
  }

  /**
   * Refers the keys declared with a shape, at any depth, to its def.
   *
   * @param array $schema
   *   A schema, or any array inside one.
   * @param array<string, array> $named
   *   The shapes' defs filed so far, by key.
   * @param string[] $taken
   *   The keys the document's other defs hold.
   *
   * @return array
   *   The same, each such key a reference.
   */
  protected function referToShapes(array $schema, array &$named, array $taken): array {
    foreach ($schema as $keyword => $value) {
      if (is_array($value) && $keyword !== self::SHAPED) {
        $schema[$keyword] = $this->referToShapes($value, $named, $taken);
      }
    }
    $shape = $schema[self::SHAPED] ?? NULL;
    if (!is_array($shape) || !is_string($shape['id'] ?? NULL) || !is_array($shape['def'] ?? NULL)) {
      return $schema;
    }
    ['id' => $id, 'def' => $def] = $shape;
    unset($schema[self::SHAPED]);
    $key = $id;
    for ($n = 2; in_array($key, $taken, TRUE) || (isset($named[$key]) && !Subschemas::same($named[$key], $def)); $n++) {
      $key = $id . '@' . $n;
    }
    $named[$key] ??= $def;
    $site = ['$ref' => self::DEFS . self::pointer($key)];
    foreach ($schema as $keyword => $value) {
      if ($keyword === 'title' || $keyword === self::EXTENSION || !array_key_exists($keyword, $def) || !Subschemas::same($def[$keyword], $value)) {
        $site[$keyword] = $value;
      }
    }
    return $site;
  }

  /**
   * Describes the outputs a data surface declares, if it declares any.
   *
   * Outputs are never refined, never rendered and never locked, so they
   * carry no `x-surface`.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the description depends on.
   *
   * @return array|null
   *   The output schema, or NULL for a data surface with no outputs.
   */
  protected function outputs(DataSurfaceInterface $surface, CacheableMetadata $cacheability): ?array {
    $properties = [];
    $required = [];
    $ignored = [];
    foreach ($surface->getOutputDefinitions() as $name => $definition) {
      [$schema] = $this->property($definition, NULL, NULL, (string) $name, FALSE, [], $cacheability, $ignored);
      $properties[(string) $name] = $this->withoutExtension($schema);
      if ($definition->isRequired()) {
        $required[] = (string) $name;
      }
    }
    if ($properties === []) {
      return NULL;
    }
    return [
      '$schema' => self::DIALECT,
      'type' => 'object',
      'properties' => $properties,
    ] + ($required === [] ? [] : ['required' => $required]);
  }

  /**
   * Builds the `x-surface` keyword's always-present part.
   *
   * @param string|null $widget
   *   The widget hint, or NULL when no widget would claim the key.
   * @param bool $locked
   *   Whether the key is locked.
   * @param string[] $depends
   *   The sibling keys its refiners watch; a mounted one by its dotted
   *   path in the frame.
   * @param bool $refined
   *   Whether it is narrowed right now.
   *
   * @return array
   *   The keyword.
   */
  protected function extension(?string $widget, bool $locked, array $depends, bool $refined): array {
    return [
      'widget' => $widget,
      'locked' => $locked,
      'dependsOn' => array_values($depends),
      'refined' => $refined,
      'stale' => FALSE,
    ];
  }

  /**
   * Says what a key that refers to an entity refers to.
   *
   * `{entityType, bundles?}` for a key that names one entity type, as
   * `entity:<type>[:<bundle>]` builds it and a bare `entity` key's id is
   * once its entity type is chosen; `{by: "entity_type"}` for that id
   * before it is, its entity type its sibling's value. The id is the value
   * either way.
   *
   * Decision: see
   * docs/decisions.md#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key.
   *
   * @return array|null
   *   The marker, or NULL for a key that refers to nothing.
   */
  protected static function referenceMarker(DataDefinitionInterface $definition): ?array {
    $target = References::target($definition);
    if ($target !== NULL) {
      return $target['bundles'] === [] ? ['entityType' => $target['entityType']] : $target;
    }
    return $definition->getSetting(EntityReferenceStructure::TARGETS) !== NULL ? ['by' => 'entity_type'] : NULL;
  }

  /**
   * Reads a definition's title and description.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return array
   *   `title` and `description`, each only when the definition has one,
   *   as plain text (PlainText).
   */
  protected function describe(DataDefinitionInterface $definition): array {
    $described = [];
    $label = $definition->getLabel();
    if ($label !== NULL && (string) $label !== '') {
      $described['title'] = PlainText::of($label);
    }
    $description = $definition->getDescription();
    if ($description !== NULL && (string) $description !== '') {
      $described['description'] = PlainText::of($description);
    }
    return $described;
  }

  /**
   * Names the JSON type, and format, a data type is.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return array
   *   `type`, and `format` where the data type has one; empty for a type
   *   JSON Schema says nothing about, such as `any`.
   */
  protected function type(DataDefinitionInterface $definition): array {
    return match ($definition->getDataType()) {
      'string' => ['type' => 'string'],
      // Drupal's validator admits a non-ASCII local part, which `email`
      // (RFC 5321) refuses and `idn-email` (RFC 6531) admits.
      'email' => ['type' => 'string', 'format' => 'idn-email'],
      'uri' => ['type' => 'string', 'format' => 'uri'],
      'datetime_iso8601' => ['type' => 'string', 'format' => 'date-time'],
      'integer', 'timestamp' => ['type' => 'integer'],
      'float' => ['type' => 'number'],
      'boolean' => ['type' => 'boolean'],
      default => [],
    };
  }

  /**
   * Answers whether a key is in its object's `required`.
   *
   * A key the resource requires must be present and not null. A secret is
   * always optional, whatever it requires: left out or null, the stored
   * secret stands, and a required one that was never set is refused by
   * the pipeline as missing.
   *
   * Decision: see docs/decisions.md#a-secret-is-optional-never-nullable.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key.
   *
   * @return bool
   *   Whether the schema requires it.
   */
  protected static function required(DataDefinitionInterface $definition): bool {
    // Nor is a computed key asked of a caller.
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
  protected function nullable(string|array $type, DataDefinitionInterface $definition): string|array {
    if (DefinitionMetadata::holdsValue($definition) || DefinitionMetadata::isSecret($definition) || is_array($type)) {
      return $type;
    }
    return [$type, 'null'];
  }

  /**
   * Says an item of a list as never null.
   *
   * An item is not a key a caller clears: accept() drops an empty item
   * before anything reads the list, so null never reaches the accepted
   * document, and a schema that offered it would offer a value no list
   * holds. The item's type loses null, and a list of choices its untitled
   * null entry, unless that entry is all there is: a list of no values,
   * whose items nothing satisfies.
   *
   * Decision: see docs/decisions.md#an-item-is-never-null.
   *
   * @param array $item
   *   The item, as a key would be said.
   *
   * @return array
   *   The same, never null.
   */
  protected static function neverNull(array $item): array {
    if (is_array($item['type'] ?? NULL)) {
      $types = array_values(array_diff($item['type'], ['null']));
      $item['type'] = count($types) === 1 ? $types[0] : $types;
    }
    if (isset($item['oneOf'])) {
      $choices = array_values(array_filter($item['oneOf'], static fn (mixed $choice): bool => $choice !== ['const' => NULL]));
      if ($choices !== []) {
        $item['oneOf'] = $choices;
      }
    }
    return $item;
  }

  /**
   * Picks the widget the Form API mapping would, for a key with no list.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return string|null
   *   The widget hint, or NULL when no stock widget claims the type.
   */
  protected function widget(DataDefinitionInterface $definition): ?string {
    $type = $definition->getDataType();
    return match (TRUE) {
      TimeWidget::draws($definition) => 'time',
      in_array($type, ['string', 'email', 'uri'], TRUE) => match (TRUE) {
        (bool) $definition->getSetting('multiline') => 'textarea',
        $type === 'email' => 'email',
        default => 'text',
      },
      in_array($type, ['integer', 'float'], TRUE) => 'number',
      $type === 'boolean' => 'checkbox',
      default => NULL,
    };
  }

  /**
   * States a definition's constraints as JSON Schema keywords.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param string|array|null $type
   *   The JSON type it was given.
   *
   * @return array
   *   The keywords; under `x-surface-pattern-message` a Regex's own
   *   message, which property() moves into `x-surface.patternMessage`;
   *   and under `x-surface-checked` each constraint no keyword states, as
   *   checked(), which property() moves into `x-surface.checkedOnServer`.
   */
  protected function keywords(DataDefinitionInterface $definition, string|array|null $type): array {
    $keywords = [];
    $unstated = [];
    $types = (array) $type;
    $numeric = array_intersect($types, ['integer', 'number']) !== [];
    foreach ($definition->getConstraints() as $name => $options) {
      switch ($name) {
        case 'Range':
          // A bound is a number on the wire, though a definition may hold
          // it as a string (core's timestamp field does).
          if ($numeric && isset($options['min']) && is_numeric($options['min'])) {
            $keywords['minimum'] = $options['min'] + 0;
          }
          if ($numeric && isset($options['max']) && is_numeric($options['max'])) {
            $keywords['maximum'] = $options['max'] + 0;
          }
          break;

        case 'Length':
          if (isset($options['min'])) {
            $keywords['minLength'] = (int) $options['min'];
          }
          if (isset($options['max'])) {
            $keywords['maxLength'] = (int) $options['max'];
          }
          break;

        case 'Count':
          if (isset($options['min'])) {
            $keywords['minItems'] = (int) $options['min'];
          }
          if (isset($options['max'])) {
            $keywords['maxItems'] = (int) $options['max'];
          }
          break;

        case 'Email':
          $keywords['format'] = 'idn-email';
          break;

        case 'NotBlank':
          $keywords['minLength'] = max(1, $keywords['minLength'] ?? 1);
          break;

        case 'Regex':
          $pattern = $this->pattern($options);
          if ($pattern === NULL) {
            $unstated[] = $this->checked((string) $name, $options);
          }
          elseif (($options['match'] ?? TRUE) === FALSE) {
            $keywords['not'] = ['pattern' => $pattern];
          }
          else {
            $keywords['pattern'] = $pattern;
          }
          // A pattern is for machines; a person is told the message, in
          // the document's language: the source core's validator
          // translates when it refuses a value, read as plain text.
          $message = $options['message'] ?? NULL;
          if (is_string($message) && $message !== '') {
            // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
            $message = $this->t($message);
          }
          if ($message instanceof TranslatableMarkup) {
            $keywords[self::EXTENSION . '-pattern-message'] = PlainText::of($message);
          }
          break;

        // Core's two composites: every part, in order, or at least one.
        // Decision: see docs/decisions.md#a-composite-is-its-parts.
        case 'Sequentially':
        case 'AtLeastOneOf':
          $parts = $this->parts($options, $type);
          $combinator = $name === 'AtLeastOneOf' ? 'anyOf' : 'allOf';
          if ($parts === NULL) {
            $unstated[] = $this->checked((string) $name, $options);
          }
          else {
            $keywords[$combinator] = [...($keywords[$combinator] ?? []), ...$parts];
          }
          break;

        default:
          // A constraint whose resolver says it is its list is the `oneOf`
          // the list is written as.
          if (!in_array($name, self::STATED, TRUE) && !$this->options->isStatedByList((string) $name)) {
            $unstated[] = $this->checked((string) $name, $options);
          }
      }
    }
    if ($unstated !== []) {
      $keywords[self::EXTENSION . '-checked'] = $unstated;
    }
    return $keywords;
  }

  /**
   * Names a constraint only the server checks, and says what it checks.
   *
   * A client told only a plugin id learns nothing it can act on, so each
   * entry carries a sentence: the constraint's own message where it was
   * given one (a Regex's is how a person is told what it allows), else
   * the label its plugin declares, else the id again.
   * Decision: see docs/decisions.md#contract-2.
   *
   * @param string $name
   *   The constraint's plugin id.
   * @param mixed $options
   *   Its options.
   *
   * @return array{constraint: string, message: string}
   *   The entry.
   */
  protected function checked(string $name, mixed $options): array {
    if ($name === 'JsonSchema' && is_array($options)) {
      // What a structure read from a schema kept of it and no keyword of
      // this document states: the keywords, and the schema itself, so a
      // reader reads it back as the same constraint.
      // Decision: see
      // docs/decisions.md#a-schema-can-be-read-back-into-a-structure.
      return static::foreign(is_array($options['schema'] ?? NULL) ? $options['schema'] : [], $this->stringTranslation);
    }
    $message = is_array($options) ? ($options['message'] ?? NULL) : NULL;
    if (is_string($message) && $message !== '') {
      // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
      $message = $this->t($message);
    }
    if (!$message instanceof \Stringable || (string) $message === '') {
      $label = $this->constraints?->getDefinitions()[$name]['label'] ?? NULL;
      $message = $label instanceof \Stringable || (is_string($label) && $label !== '') ? $label : $name;
    }
    return ['constraint' => $name, 'message' => PlainText::of($message)];
  }

  /**
   * Names a JSON Schema only the server checks, and says which keywords.
   *
   * @param array $schema
   *   The schema a JsonSchema constraint holds a value, or a frame, to.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The string translation service, for the message.
   *
   * @return array{constraint: string, message: string, schema: array|\stdClass}
   *   The entry: `checkedOnServer`'s, with the schema beside the message.
   */
  public static function foreign(array $schema, TranslationInterface $translation): array {
    $keywords = JsonSchemaConstraint::keywords($schema);
    return [
      'constraint' => 'JsonSchema',
      'message' => PlainText::of(new TranslatableMarkup('Checked on the server against the schema it was read from: @keywords.', ['@keywords' => implode(', ', $keywords)], [], $translation)),
      'schema' => $schema === [] ? new \stdClass() : $schema,
    ];
  }

  /**
   * States the parts of a composite constraint, each as its own schema.
   *
   * Each part is translated as a constraint on its own is, by keywords().
   * A part no keyword states (one checked on the server, a Regex no
   * pattern carries, a Range on a string, a list of allowed values the
   * options resolver never reads inside a composite) leaves the whole
   * composite unstated: an `anyOf` short of a part would refuse what that
   * part allows, and an `allOf` is kept whole or not at all, so what is
   * stated is what is checked. A part's message is the server's to say.
   *
   * @param mixed $options
   *   The composite's options, its parts under `constraints`, each a
   *   constraint's name and options.
   * @param string|array|null $type
   *   The JSON type the key was given.
   *
   * @return array[]|null
   *   One schema per part, in order; NULL when any part cannot be stated.
   */
  protected function parts(mixed $options, string|array|null $type): ?array {
    $constraints = is_array($options) ? ($options['constraints'] ?? NULL) : NULL;
    if (!is_array($constraints) || $constraints === []) {
      return NULL;
    }
    $parts = [];
    foreach ($constraints as $constraint) {
      if (!is_array($constraint) || count($constraint) !== 1) {
        return NULL;
      }
      $name = (string) array_key_first($constraint);
      $part = $this->keywords((new DataDefinition())->addConstraint($name, $constraint[$name]), $type);
      unset($part[self::EXTENSION . '-pattern-message']);
      if ($part === [] || isset($part[self::EXTENSION . '-checked'])) {
        return NULL;
      }
      $parts[] = $part;
    }
    return $parts;
  }

  /**
   * Turns a PHP regular expression into an ECMA one, when it can be.
   *
   * @param array $options
   *   The Regex constraint's options.
   *
   * @return string|null
   *   The pattern without its delimiters, or NULL when it uses anything
   *   the two engines do not read alike.
   *
   * @see \Drupal\data_surface\Contract\PatternTranslation
   */
  protected function pattern(array $options): ?string {
    $regex = $options['pattern'] ?? $options['value'] ?? NULL;
    return is_string($regex) ? PatternTranslation::ecma($regex) : NULL;
  }

  /**
   * Spells an option set as `oneOf` entries, labels and help included.
   *
   * A key that may be cleared allows null besides, as its own entry with
   * no title: the empty option is a presentation, said under
   * `x-surface.emptyOption`, and never an option with a label.
   *
   * @param \Drupal\data_surface\Options\OptionSet $set
   *   The values the key allows.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key.
   *
   * @return array
   *   The entries.
   */
  protected function choices(OptionSet $set, DataDefinitionInterface $definition): array {
    $choices = [];
    foreach ($set->options as $value => $label) {
      // A Choice of numbers is labeled by them, as numbers.
      $title = PlainText::of(is_int($label) || is_float($label) ? (string) $label : $label);
      $choice = ['const' => $this->cast($value, $definition), 'title' => $title];
      if (isset($set->descriptions[$value])) {
        $choice['description'] = PlainText::of($set->descriptions[$value]);
      }
      $choices[] = $choice;
    }
    if ($choices === [] || (!DefinitionMetadata::holdsValue($definition) && !DefinitionMetadata::isSecret($definition))) {
      // Null clears an optional key that may be cleared. A list of no
      // values offers it alone, which a required key's type refuses too:
      // nothing is allowed.
      $choices[] = ['const' => NULL];
    }
    return $choices;
  }

  /**
   * Writes an option value in the type its key declares.
   *
   * An option list is keyed by value, and PHP turns a numeric string key
   * into an integer, so the type a value arrives in says nothing; the
   * definition says what it is.
   *
   * @param int|string $value
   *   The value.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key it is a value of.
   *
   * @return int|float|string|bool
   *   The value in the key's type.
   */
  protected function cast(int|string $value, DataDefinitionInterface $definition): int|float|string|bool {
    return match ($this->type($definition)['type'] ?? NULL) {
      'integer' => is_numeric($value) ? (int) $value : $value,
      'number' => is_numeric($value) ? $value + 0 : $value,
      'boolean' => (bool) $value,
      default => (string) $value,
    };
  }

  /**
   * Resolves the values a definition offers, and notes what they rest on.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the list's cacheability.
   *
   * @return \Drupal\data_surface\Options\OptionSet|null
   *   The set, or NULL when the definition names no list.
   */
  protected function optionSet(DataDefinitionInterface $definition, CacheableMetadata $cacheability): ?OptionSet {
    $set = $this->options->resolve($definition);
    if ($set !== NULL) {
      $cacheability->addCacheableDependency($set);
    }
    return $set;
  }

  /**
   * Sums up what a definition allows, to tell a narrowed one apart.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return string
   *   A fingerprint of its requiredness, its stated constraints and the
   *   values it offers.
   */
  protected function signature(DataDefinitionInterface $definition): string {
    $set = $this->options->resolve($definition instanceof ListDataDefinitionInterface ? $definition->getItemDefinition() : $definition);
    $properties = [];
    if ($definition instanceof ComplexDataDefinitionInterface) {
      foreach ($definition->getPropertyDefinitions() as $name => $property) {
        $properties[$name] = $this->signature($property);
      }
    }
    return serialize([
      $definition->getDataType(),
      $definition->isRequired(),
      $this->keywords($definition, $this->type($definition)['type'] ?? NULL),
      $set === NULL ? NULL : array_keys($set->options),
      $properties,
    ]);
  }

  /**
   * Answers whether anything inside an object schema is narrowed now.
   *
   * @param array $schema
   *   An object schema this emitter wrote.
   *
   * @return bool
   *   TRUE when a property says it is refined.
   */
  protected function anyRefined(array $schema): bool {
    // An object with no properties holds an empty object here, which
    // iterates as nothing.
    foreach ($schema['properties'] ?? [] as $property) {
      // A mixed-in structure's member the owner says nothing more of is
      // `true`, and one ruled out `false`.
      if (is_array($property) && !empty($property[self::EXTENSION]['refined'])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Strips what is only true of one moment from a schema, at every depth.
   *
   * The full schema is written by the same pass as the schema for a set
   * of values, and so carries that pass's reading of each key for the
   * values it was run with, here none. Taken out afterwards, as the
   * widget hints are, that pass stays the one place a key is described.
   *
   * @param array $schema
   *   A schema this emitter wrote.
   *
   * @return array
   *   The schema, its `x-surface` keywords without `refined`, `stale`,
   *   `emptyOption` and the variants' `chosen`.
   */
  protected function withoutPresent(array $schema): array {
    foreach ($schema as $key => $value) {
      if (!is_array($value)) {
        continue;
      }
      if ($key === self::EXTENSION) {
        unset($value['refined'], $value['stale'], $value['emptyOption'], $value['chosen']);
        $schema[$key] = $value;
      }
      else {
        $schema[$key] = $this->withoutPresent($value);
      }
    }
    return $schema;
  }

  /**
   * States how a key is arranged where it is drawn, as plain text.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition.
   *
   * @return array
   *   Its arrangement as Arrangement::toArray() states it, the group's
   *   label and every text read as plain text; empty for none.
   */
  protected function arrangement(DataDefinitionInterface $definition): array {
    $arrangement = DefinitionMetadata::arrangementOf($definition);
    if ($arrangement === NULL) {
      return [];
    }
    $arranged = $arrangement->toArray();
    if ($arrangement->group !== NULL) {
      $arranged['group']['label'] = PlainText::of($arrangement->group->label);
    }
    foreach (Arrangement::TEXTS as $text) {
      if (isset($arranged[$text])) {
        $arranged[$text] = PlainText::of((string) $arrangement->{$text});
      }
    }
    return $arranged;
  }

  /**
   * Strips the widget hints from a schema, at every depth.
   *
   * The hints are written while the schema is built, because the widget
   * a key gets is read from the same definition and option list as its
   * shape; taking them out afterwards keeps that one pass.
   *
   * @param array $schema
   *   A schema this emitter wrote.
   *
   * @return array
   *   The schema, its `x-surface` keywords without `widget`, `multiple`
   *   and `arrangement`.
   */
  protected function withoutWidgets(array $schema): array {
    foreach ($schema as $key => $value) {
      if (!is_array($value)) {
        continue;
      }
      if ($key === self::EXTENSION) {
        unset($value['widget'], $value['multiple'], $value['arrangement']);
        $schema[$key] = $value;
      }
      else {
        $schema[$key] = $this->withoutWidgets($value);
      }
    }
    return $schema;
  }

  /**
   * Strips the extension keywords from a schema, at every depth.
   *
   * @param array $schema
   *   The schema.
   *
   * @return array
   *   The schema in plain JSON Schema.
   */
  protected function withoutExtension(array $schema): array {
    unset($schema[self::EXTENSION], $schema[self::EXTENSION . '-checked'], $schema[self::EXTENSION . '-pattern-message'], $schema[self::SHAPED]);
    foreach ($schema as $key => $value) {
      if (is_array($value)) {
        $schema[$key] = $this->withoutExtension($value);
      }
    }
    return $schema;
  }

}
