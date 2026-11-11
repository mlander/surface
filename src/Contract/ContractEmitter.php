<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use Drupal\surface\ResourceBuild\ResourceRegistry;
use Drupal\surface\ResourceBuild\ServedPathsInterface;
use Drupal\surface\Reference\References;
use Drupal\Component\Utility\NestedArray;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\Validation\ConstraintManager;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\DefinitionMap;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Plugin\Validation\Constraint\JsonSchemaConstraint;
use Drupal\surface\Form\SurfaceFormBuilderInterface;
use Drupal\surface\Options\SurfaceOptions;
use Drupal\surface\Pipeline\ValueState;
use Drupal\surface\Refinement\DeclaredRefiner;
use Drupal\surface\Refinement\DoesNotApply;
use Drupal\surface\Attachment;
use Drupal\surface\Port;
use Drupal\surface\Entry;
use Drupal\surface\Collection;
use Drupal\surface\Pipeline\ResultDocument;
use Drupal\surface\Widget\SurfaceWidgetManager;
use PortableSchema\Builder\Schema;

/**
 * Turns a sealed data surface and its values into the served contract.
 *
 * The contract is a Portable Schema document for the inputs (DIALECT, a
 * dialect of JSON Schema 2020-12), with one extension keyword,
 * `x-surface`, on every property for what neither has a word for, plus
 * the values the schema describes right now. What only the server checks
 * and a message for an error code are the dialect's own keywords,
 * `portable-check` and `x-portable-messages` (PortableKeywords). It is the
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
 *   take, as conditionals on the parent beside the variants'
 *   (Conditionals::enumerate()), so a static contract is exact wherever
 *   the answers are finite; where
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
 * `allOf: [{$ref}, {narrowing}]` (PropertySchema::reference()):
 * refinement only narrows,
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
 * it (emit()'s $widgets). Then `x-portable-ui.widget` mirrors
 * SurfaceWidgetManager's selection in its weight order, so a
 * renderer such as surface_examples_react's app draws what the operation
 * form would: a non-empty option list is a select (a list of them, a
 * multiple select, said by `options.multiple`); a map with properties is a
 * fieldset; a string is a textarea when its definition says multiline,
 * a time input when it asks for one, an email input for the email type,
 * a text input otherwise; a number is a number input; a boolean a
 * checkbox. A list of values is checkboxes or a multiple select, and one
 * value radios when it asks for them, by the widgets' own rule
 * (CheckboxesWidget::drawsList(), RadiosWidget::drawsOne()); a list with
 * no option list is `list`, rows to add and remove. A key a module's
 * widget claims (SurfaceWidgetManager::claimed()) is that widget's
 * word, ahead of the stock rule, unless its owner asked for one with the
 * `widget` setting. One hint has no
 * Form API counterpart here: `variants`, for a key with variants, whose
 * shape a sibling chooses. Beside the widget, `x-portable-ui` carries a
 * key's arrangement (`group`, `weight`, its texts as `options`, each
 * group declared once under its frame's `groups`) and, whatever is
 * asked, a select's `options.emptyOption` (PortableUi).
 *
 * Both documents are written in one version of the wire format, the
 * contract's own (CONTRACT, stated as `x-surface.contract`), distinct
 * from a resource's version. In contract 2 no `x-surface` sits below an
 * applicator (`allOf`, `anyOf`, `oneOf`, `if`, `then`, `else`, `not`):
 * below an applicator a node keeps the dialect's three keywords
 * (`portable-check`, `x-portable-messages`, `x-portable-ui`), which a
 * plain validator ignores. The object owning the applicator says which
 * of its conditionals are declared rules (`x-surface.rules`) and what a
 * narrowing says of a key inside it (`x-surface.within`, by JSON
 * pointer), and every narrowing states the declared `type` beside a
 * keyword only one type reads (DocumentHead::settled()).
 * Decision: see docs/decisions.md#contract-2.
 *
 * This class is the walk: it visits every frame, key, child, variant,
 * collection item and mount, and files each child's def. Three internal
 * classes write what the walk asks for: PropertySchema describes one key
 * as a property, Conditionals writes what a key's refiners answer as
 * conditionals, and DocumentHead writes what sits above the properties.
 * Both documents share the walk, so the seam is the responsibility and
 * not the document.
 * Decision: see
 * docs/decisions.md#the-emitter-is-one-walk-and-three-writers.
 */
final class ContractEmitter {

  use StringTranslationTrait;

  /**
   * The dialect every schema this emitter writes declares.
   *
   * Portable Schema, a dialect of JSON Schema 2020-12: its profile of the
   * standard keywords and its own vocabulary, of which the documents use
   * `portable-check`, `x-portable-messages`, `x-portable-ui` and
   * `x-portable-links` (PortableKeywords).
   * Decision: see
   * docs/decisions.md#the-served-document-is-a-portable-schema-document.
   */
  public const DIALECT = 'https://portable-schema.dev/v1/schema';

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
   * 2 until 2026-10-22, contract 3 (the operation is the method, every
   * path a JSON Pointer) until 2026-10-24; contract 4 cut the `x-surface`
   * keywords that said nothing or said it twice, and moved no path, until
   * 2026-11-11; contract 5 is a Portable Schema document, what only the
   * server checks and a message for an error code in the dialect's own
   * keywords, and an email address `format: email`.
   * Decision: see
   * docs/decisions.md#contract-4-fewer-keywords-nothing-new-on-the-wire,
   * docs/decisions.md#the-served-document-is-a-portable-schema-document.
   */
  public const CONTRACT = 5;

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
   * The full schema leaves them out, and PRESENT_OPTIONS
   * (Conditionals::withoutPresent()).
   */
  protected const PRESENT = ['refined', 'stale', 'chosen'];

  /**
   * The `x-portable-ui` options only true of one moment: the empty option.
   *
   * The full schema leaves them out (Conditionals::withoutPresent()); the
   * schema for these answers carries them whether or not the widget hints
   * (PortableUi::HINTS) are asked for.
   */
  protected const PRESENT_OPTIONS = ['emptyOption'];

  /**
   * The `x-portable-ui` members and options that are widget hints.
   *
   * Written when asked for (emit()'s $widgets): `widget`, `group`,
   * `weight`, `groups`, and the options `multiple`, `placeholder`,
   * `prefix`, `suffix` and `help`; PropertySchema::withoutWidgets() strips
   * them otherwise.
   */
  protected const WIDGET_HINTS = PortableUi::HINTS;

  /**
   * Where every subschema a document defines is, as a JSON pointer.
   */
  public const DEFS = '#/$defs/';

  /**
   * The choices the full schema being written states in full, if any.
   */
  protected ?Choices $choices = NULL;

  /**
   * The URL of the full schema, which a deferred key's `choose` link adds to.
   *
   * NULL while a document no route serves is written: the link is then
   * only its query, read against the full schema's URL.
   */
  protected ?string $fetchBase = NULL;

  /**
   * The lookup for the operation's keys, which a key's `lookup` link names.
   *
   * NULL while a document no lookup answers is written: no HTTP door
   * (surface_http), no operation, an operation `GET` does not serve at its
   * path, an id the registry does not know, or a lookup whose path takes a
   * parameter no URL was given for (lookup()).
   */
  protected ?string $lookup = NULL;

  /**
   * Where the frame being described is.
   *
   * Its pointer, the choices on the way to it, each a pointer and a
   * value, and whether the lookup finds a key of it: through attached
   * children alone, as References::definitionAt() walks, never into a
   * collection's item, a module's mount or a variant.
   *
   * @var array{pointer: string, steps: list<array{0: string, 1: string}>, reached: bool}
   */
  protected array $site = ['pointer' => '', 'steps' => [], 'reached' => TRUE];

  /**
   * Describes one key as a property, and a child's site as a reference.
   */
  protected readonly PropertySchema $propertySchema;

  /**
   * Writes what a key's refiners answer as conditionals.
   */
  protected readonly Conditionals $conditionals;

  /**
   * Writes what sits above a document's properties.
   */
  protected readonly DocumentHead $documentHead;

  /**
   * Constructs a ContractEmitter.
   *
   * @param \Drupal\surface\Options\SurfaceOptions $options
   *   The options service, the one place a constraint is read as a list.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service, for the empty option's labels.
   * @param \Drupal\Core\Validation\ConstraintManager|null $constraints
   *   The constraint plugin manager, for what a constraint only the server
   *   checks says it checks (its label); without it, its id.
   * @param \Drupal\surface\Contract\SiteListBudget|null $budget
   *   How many entries of a list the site decides are spelled out; without
   *   it, every one.
   * @param \Drupal\surface\Widget\SurfaceWidgetManager|null $widgets
   *   The widget manager, which says what a module's widget claims;
   *   without it, the stock rule alone names the widget.
   * @param \Drupal\surface\ResourceBuild\ServedPathsInterface|null $servedPaths
   *   The API's paths, which a reference key's lookup link names; NULL
   *   on a site that serves no API (surface_http is not installed), whose
   *   documents carry no lookup link.
   */
  public function __construct(
    SurfaceOptions $options,
    TranslationInterface $string_translation,
    ?ConstraintManager $constraints = NULL,
    protected readonly ?SiteListBudget $budget = NULL,
    ?SurfaceWidgetManager $widgets = NULL,
    protected readonly ?ServedPathsInterface $servedPaths = NULL,
  ) {
    $this->stringTranslation = $string_translation;
    $this->propertySchema = new PropertySchema($options, $string_translation, $constraints, $widgets);
    $this->conditionals = new Conditionals($this->propertySchema);
    $this->documentHead = new DocumentHead($this->propertySchema);
  }

  /**
   * Emits the contract a data surface serves for a set of values.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface as built in its context, not refined: what it
   *   advertises.
   * @param array $values
   *   The values to describe it for, keyed by key: what the storage holds
   *   over the defaults, with any in-progress answers over that. A key
   *   not given shows its default. A refinement overlay
   *   (SurfaceFormBuilderInterface::refinementOverlay()) is read the
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
   *   Whether to add the renderer's widget hints, `x-portable-ui` but for
   *   `options.emptyOption` (WIDGET_HINTS). Off by default:
   *   the contract itself names no widget, and a renderer that draws
   *   from it, as the HTTP door does for the React example, asks for
   *   them.
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
   * @param string|null $schema_url
   *   The URL the operation's full schema is served at, which a deferred
   *   key's `choose` link is written against; NULL writes the template
   *   as its query alone.
   * @param string|null $lookup_url
   *   The URL of the lookup for the operation's keys, the request's
   *   parameters in it, which a reference key's `lookup` link is written
   *   against; NULL writes the lookup's path where it takes no parameter,
   *   and no link where it does (lookup()).
   *
   * @return \Drupal\surface\Contract\ServedContract
   *   The contract, and what it depends on.
   */
  public function emit(SurfaceInterface $surface, array $values, string $resource_id, ?string $operation_id = NULL, string|\Stringable|null $label = NULL, bool $widgets = FALSE, array $operation = [], ?string $id = NULL, ?string $schema_url = NULL, ?string $lookup_url = NULL): ServedContract {
    // Refined surfaces are remembered for this one document.
    // Decision: see docs/decisions.md#what-is-paid-once-and-what-per-request.
    $was = [$this->conditionals->remember(new \WeakMap()), $this->choices, $this->fetchBase, $this->site, $this->lookup];
    $this->choices = NULL;
    $this->fetchBase = $schema_url;
    $this->site = ['pointer' => '', 'steps' => [], 'reached' => TRUE];
    $this->lookup = $this->lookup($resource_id, $operation_id, $lookup_url);
    try {
      $cacheability = new CacheableMetadata();
      $standing = $values[SurfaceFormBuilderInterface::STANDING_KEY] ?? [];
      unset($values[SurfaceFormBuilderInterface::STANDING_KEY]);
      $subschemas = new Subschemas(FALSE, [], $this->mixedIn($surface), $widgets ? [] : self::WIDGET_HINTS);
      $emission = new Emission($subschemas, $cacheability, new \ArrayObject(), $resource_id, standing: is_array($standing) ? $standing : []);
      [$schema, $shown] = $this->frame($surface, $values, $emission);
      $schema = PortableUi::settled($this->propertySchema->named($this->definitions($schema, $subschemas)));
      if (!$widgets) {
        $schema = $this->propertySchema->withoutWidgets($schema);
      }
      $schema = $this->documentHead->top($surface, $schema, $label, $operation, $id);
      $document = [
        'resource' => $resource_id,
        'operation' => $operation_id,
        'label' => $label === NULL ? NULL : PlainText::of($label),
        'schema' => Schemas::arrayed($schema),
        'values' => $shown,
        // Each a JSON Pointer, the one spelling a path has on the wire.
        // Decision: see docs/decisions.md#one-result-at-every-door.
        'stale' => array_map(ResultDocument::pointer(...), $emission->stale->getArrayCopy()),
      ];
      $outputs = $this->documentHead->outputs($surface, $emission);
      if ($outputs !== NULL) {
        $document['outputs'] = Schemas::arrayed($outputs);
      }
      return new ServedContract($document, $cacheability, $schema, $outputs);
    }
    finally {
      [$refined, $this->choices, $this->fetchBase, $this->site, $this->lookup] = $was;
      $this->conditionals->remember($refined);
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
   *   answer locked is `readOnly`, one it left out is not there, and one a
   *   runtime answer may still lock or leave out is there, as the static
   *   answers leave it, marked `live`, as a key a runtime refiner narrows
   *   is; the schema for these answers settles it;
   * - a list the site decides past the site list limit is `deferred`:
   *   open, `deferred` (`true`, or the entries of a map of keys from the
   *   site), its `choose` link a template naming the choice that states
   *   one of its entries; with choices, each one chosen
   *   is stated in full at its key, as under the limit, and every other
   *   entry is still left out;
   * - `x-surface` carries only what holds for any values: `dependsOn`,
   *   `enumerated`, `deferred`, `refine`, `live`, the variants' `by` and
   *   `variants`, `group`, `after`, each only where it says something;
   *   `portable-check`, `x-portable-messages`, `x-portable-links` and,
   *   when asked for, the widget hints in `x-portable-ui` beside it hold
   *   for any values too. What is only true of a moment (`refined`,
   *   `stale`, the variants' `chosen`, and
   *   `x-portable-ui.options.emptyOption`) is not written,
   *   and there is no `values` and no `stale` beside the schema.
   *
   * Decision: see
   * docs/decisions.md#two-documents-never-stricter-than-the-runtime.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
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
   * @param \Drupal\surface\Contract\Choices|null $choices
   *   The choices to state in full (`?choose=`), resolved against the same
   *   data surface; NULL or none for the plain full schema.
   * @param string|null $schema_url
   *   The URL of the plain full schema, which a deferred key's `choose`
   *   template is written against; NULL for `$id`.
   * @param string|null $lookup_url
   *   The URL of the lookup, as for emit().
   *
   * @return \Drupal\surface\Contract\ServedContract
   *   The document (`resource`, `operation`, `label`, `schema` and, when
   *   the data surface declares outputs, `outputs`), and what it depends
   *   on: the data surface and every option list read, never a stored
   *   value.
   */
  public function emitFull(SurfaceInterface $surface, string $resource_id, ?string $operation_id = NULL, string|\Stringable|null $label = NULL, bool $widgets = FALSE, array $operation = [], ?string $id = NULL, ?Choices $choices = NULL, ?string $schema_url = NULL, ?string $lookup_url = NULL): ServedContract {
    // Refined surfaces are remembered for this one document.
    // Decision: see docs/decisions.md#what-is-paid-once-and-what-per-request.
    $was = [$this->conditionals->remember(new \WeakMap()), $this->choices, $this->fetchBase, $this->site, $this->lookup];
    $this->choices = $choices === NULL || $choices->isEmpty() ? NULL : $choices;
    $this->fetchBase = $schema_url ?? $id;
    $this->site = ['pointer' => '', 'steps' => [], 'reached' => TRUE];
    $this->lookup = $this->lookup($resource_id, $operation_id, $lookup_url);
    try {
      $cacheability = new CacheableMetadata();
      $ui = [...self::PRESENT_OPTIONS, ...($widgets ? [] : self::WIDGET_HINTS)];
      $subschemas = new Subschemas(TRUE, self::PRESENT, $this->mixedIn($surface), $ui);
      $emission = new Emission($subschemas, $cacheability, new \ArrayObject(), $resource_id);
      [$schema] = $this->frame($surface, $this->known($surface), $emission);
      $schema = PortableUi::settled($this->propertySchema->named($this->definitions($schema, $subschemas)));
      $schema = $this->conditionals->withoutPresent($schema);
      if (!$widgets) {
        $schema = $this->propertySchema->withoutWidgets($schema);
      }
      $schema = $this->documentHead->top($surface, $schema, $label, $operation, $id);
      $document = [
        'resource' => $resource_id,
        'operation' => $operation_id,
        'label' => $label === NULL ? NULL : PlainText::of($label),
        'schema' => Schemas::arrayed($schema),
      ];
      $outputs = $this->documentHead->outputs($surface, $emission);
      if ($outputs !== NULL) {
        $document['outputs'] = Schemas::arrayed($outputs);
      }
      return new ServedContract($document, $cacheability, $schema, $outputs);
    }
    finally {
      [$refined, $this->choices, $this->fetchBase, $this->site, $this->lookup] = $was;
      $this->conditionals->remember($refined);
    }
  }

  /**
   * Reads the values an operation knows and nothing can change.
   *
   * The only values the full schema is described for: a locked key holds
   * its identity whatever is stored or typed, so a key that watches only
   * locked siblings has one answer.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   A data surface frame, as advertised.
   *
   * @return array
   *   The value of each locked key, by key.
   */
  protected function known(SurfaceInterface $surface): array {
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
   * Describes one frame: the top level, or one child.
   *
   * @param \Drupal\surface\SurfaceInterface $declared
   *   The data surface of this frame, as advertised.
   * @param array $values
   *   The values of this frame.
   * @param \Drupal\surface\Contract\Emission $emission
   *   What the walk carries: where this frame is (its path and the id it
   *   was built from), the orphans of a refinement, whether it is a def,
   *   locked whole or stands for every item of a collection, and the
   *   document's defs, cacheability and stale paths, which every frame
   *   inside collects into. In the full schema $values are the locked
   *   keys the frame knows and nothing else, every frame inside is
   *   described from its own locked keys instead of its defaults, and a
   *   key that watches anything a value could change is described as
   *   declared.
   *
   * @return array{0: \PortableSchema\Builder\Schema, 1: array|\stdClass}
   *   The object schema of the frame, and its values as shown.
   */
  protected function frame(SurfaceInterface $declared, array $values, Emission $emission): array {
    $full = $emission->full();
    $prefix = $emission->prefix;
    $owner = $emission->owner;
    $cacheability = $emission->cacheability;
    $subschemas = $emission->subschemas;
    $standing = $emission->standing;
    $declaring = $emission->declaring;
    $frozen = $emission->frozen;
    $blank = $emission->blank;
    // Decision: see docs/decisions.md#static-by-default-runtime-by-declaration.
    // The present moment is refined by every refiner; the full schema and
    // a def, which are for any values, by the static ones alone.
    // A declared rule is stated as written, as a conditional of its own,
    // in the full schema and a def; for one set of values it is evaluated
    // like any refiner. Decision: see
    // docs/decisions.md#a-rule-declared-as-data.
    $runtime = $emission->runtime();
    $kinds = $emission->kinds();
    // A port holds what its container bound it to, read as a sibling is.
    // Decision: see docs/decisions.md#a-port-is-a-live-sibling-value.
    $ports = $declared->getDefinitions()->ports();
    $values = $declared->getPortValues() + $values;
    $refined = $declared->refine($values, $runtime ? SurfaceInterface::EVERY : $kinds);
    $cacheability->addCacheableDependency($refined);
    $advertised = $declared->getDefinitions();
    $now = $refined->getDefinitions();
    // What the static refiners alone answer for these values, which is
    // all a conditional may state: a runtime refiner is never run ahead.
    $static = $runtime && $this->conditionals->refinedAtRuntime($advertised) ? $declared->refine($values, $kinds) : $refined;
    $ahead = $static->getDefinitions();
    // What the coded ones alone answer: whether their gate is open yet.
    $coded = $runtime && $this->conditionals->hasRules($advertised) ? $declared->refine($values, SurfaceInterface::CODED)->getDefinitions() : $ahead;
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
      if ($full && !$entry->isNested() && $this->conditionals->watchesFree($advertised, $entry, $declaring)) {
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
      $requires = PropertySchema::required($current->definition);
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
        [$properties[$name], $shown[$name], $branches] = $this->variants($declared, $entry, $values, $locked, $emission);
        array_push($conditions, ...$branches);
        if ($ahead_of_time) {
          // A parent's refiner of a key with variants says only whether
          // it applies.
          $enumerated = $this->conditionals->enumerate($declared, $static, $entry, $values, $properties[$name], $emission, TRUE);
        }
      }
      elseif ($entry->list !== NULL) {
        [$properties[$name], $shown[$name]] = $this->collection($declared, $entry->list, $current, $entry, $values, $locked, $depends, $emission);
        if ($ahead_of_time) {
          // A parent's refiner of the list watches a sibling that holds
          // nothing yet: its Count for every value that sibling offers.
          $enumerated = $this->conditionals->enumerate($declared, $static, $entry, $values, $properties[$name], $emission);
        }
      }
      elseif ($entry->attachment !== NULL && ($full || $declaring) && $this->budget?->over($entry, $cacheability)) {
        // Keys from the site, more than the site's list budget: an open
        // map, `deferred`, its entries named by id and label alone, and
        // how to fetch one entry's structure. The schema for these answers
        // states every one; a full schema with a choice here, the entry
        // chosen.
        // Decision: see
        // docs/decisions.md#a-list-left-out-for-size-is-deferred-not-refined.
        $properties[$name] = $this->propertySchema->describe($current->definition)->with('type', $this->propertySchema->nullable('object', $current->definition));
        if ($locked) {
          $properties[$name] = $properties[$name]->with('readOnly', TRUE);
        }
        $pointer = $this->site['pointer'] . '/' . Choices::escape($name);
        $picked = $this->choices?->entries($entry->attachment->child, $pointer) ?? [];
        if ($picked !== []) {
          [$stated, $requiring] = $this->entriesChosen($name, $entry->attachment, $owner . '.' . $name, $picked, $emission);
          $properties[$name] = $properties[$name]->with('properties', $stated);
          if ($requiring !== []) {
            $properties[$name] = $properties[$name]->with('required', $requiring);
          }
        }
        $entries = [];
        foreach ($entry->attachment->child->getDefinitions()->entries() as $id => $each) {
          $entries[] = ['id' => (string) $id, 'label' => (string) $each->definition->getLabel()];
        }
        $properties[$name] = PortableUi::set($properties[$name], PortableUi::write('fieldset'));
        // The fetch is a link, `{value}` an entry's id, which the entries
        // list gives; written at the site, never in a def, which several
        // sites share.
        if (!$declaring) {
          $properties[$name] = PortableLinks::set($properties[$name], PortableLinks::choose($this->fetch($pointer), NULL));
        }
        $properties[$name] = $properties[$name]->with(self::EXTENSION, $this->propertySchema->extension($depends, FALSE) + [
          'deferred' => ['entries' => $entries],
        ]);
        $shown[$name] = new \stdClass();
        if ($ahead_of_time) {
          $enumerated = $this->conditionals->enumerate($declared, $static, $entry, $values, $properties[$name], $emission, TRUE);
        }
      }
      elseif ($entry->attachment !== NULL && ResourceRegistry::isMap($entry->attachment->source)) {
        // An anonymous map whose keys needed a frame: a structure with no
        // id, written inline where it sits, as a plain map is, with no def.
        // Decision: see docs/decisions.md#an-anonymous-map.
        $attachment = $entry->attachment;
        $child = $declaring ? $attachment->child : ($current->attachment ?? $attachment)->bound($values);
        $held = $values[$name] ?? NULL;
        $inside = $declaring || $full || $blank ? $this->known($child) : array_replace($child->getDefaultValues(), is_array($held) ? $held : []);
        $around = $this->enter($name);
        try {
          [$instance, $shown[$name]] = $this->frame($child, $inside, $emission->withPrefix($prefix . $name . '.')->withOwner($attachment->id ?? $owner . '.' . $name)->withFrozen($locked));
        }
        finally {
          $this->site = $around;
        }
        if ($declaring) {
          $shown[$name] = new \stdClass();
        }
        $instance = $instance->with('type', $this->propertySchema->nullable('object', $current->definition));
        $properties[$name] = Schemas::first($instance, $this->propertySchema->describe($current->definition)->all());
        if ($locked) {
          $properties[$name] = $properties[$name]->with('readOnly', TRUE);
        }
        $properties[$name] = PortableUi::set($properties[$name], PortableUi::write($this->propertySchema->asked($current->definition) ?? 'fieldset'));
        $properties[$name] = $properties[$name]->with(self::EXTENSION, (array) $instance->get(self::EXTENSION) + $this->propertySchema->extension($depends, $this->propertySchema->anyRefined($instance)));
        if ($ahead_of_time) {
          $enumerated = $this->conditionals->enumerate($declared, $static, $entry, $values, $properties[$name], $emission, TRUE);
        }
      }
      elseif ($entry->attachment !== NULL) {
        $attachment = $entry->attachment;
        $fallback = $owner . '.' . $name;
        $around = $this->enter($name);
        try {
          $key = $this->define($attachment, $fallback, $emission);
          if ($declaring) {
            $instance = $subschemas->get($key) ?? Schema::any();
            $reference = $this->propertySchema->reference($key, NULL, $subschemas);
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
            [$instance, $shown[$name]] = $this->instance($child, $key, $inside, $emission->withPrefix($prefix . $name . '.')->withOwner($attachment->id ?? $fallback)->withFrozen($locked));
            $reference = $this->propertySchema->reference($key, $instance, $subschemas);
          }
        }
        finally {
          $this->site = $around;
        }
        $properties[$name] = Schemas::first($reference, $this->propertySchema->describe($current->definition)->all());
        if ($locked) {
          $properties[$name] = $properties[$name]->with('readOnly', TRUE);
        }
        $properties[$name] = PortableUi::set($properties[$name], PortableUi::write($this->propertySchema->asked($current->definition) ?? 'fieldset'));
        $properties[$name] = $properties[$name]->with(self::EXTENSION, $this->propertySchema->extension($depends, $this->propertySchema->anyRefined($instance)));
        if ($ahead_of_time) {
          // A parent's refiner of an attached key says only whether it
          // applies.
          $enumerated = $this->conditionals->enumerate($declared, $static, $entry, $values, $properties[$name], $emission, TRUE);
        }
        if (!$declaring && $attachment->reads !== [] && !static::readsThrough($attachment, $ports)) {
          // Which of this frame's keys each port reads, and what the child
          // answers for them where they hold nothing yet: said here, at the
          // site, on this frame's allOf, never in the child's def.
          // Decision: see docs/decisions.md#a-port-is-a-live-sibling-value.
          $bound = $current->attachment ?? $attachment;
          $each = static::throughPorts($attachment->child) !== [];
          $properties[$name] = static::extended($properties[$name], ['reads' => $each ? $this->eachReads($attachment) : $attachment->reads]);
          if (!$locked) {
            [$branches, $markers] = $each
              ? $this->entrySites($declared, $static, $entry, $bound, $values, $emission)
              : $this->site($declared, $static, $entry, $bound, '', $values, $emission);
            array_push($conditions, ...$branches);
            $properties[$name] = $properties[$name]->with(self::EXTENSION, (array) $properties[$name]->get(self::EXTENSION) + $markers);
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
        [$properties[$name], $shown[$name]] = $this->propertySchema->property($current->definition, $entry->definition, $value, $prefix . $name, $locked, $depends, $emission);
        $properties[$name] = $this->linked($properties[$name], $current->definition, $name, $declaring);
        if ($declaring && $entry->locked) {
          // What the context fixed it to is the instance's, not the
          // declaration's: the reference site says it.
          $properties[$name] = $properties[$name]->without('default');
        }
        if ($orphan) {
          // The edit moved what this key refines against, away from the
          // stored value, which the new answer no longer offers: stale,
          // whatever its widget, and shown as nothing. The caller sends
          // the path back and an empty answer there stands for the stored
          // value again, as for any stale key.
          if (empty(((array) $properties[$name]->get(self::EXTENSION))['stale'])) {
            $properties[$name] = static::extended($properties[$name], ['stale' => TRUE]);
            $emission->markStale($prefix . $name);
          }
          $ui = $properties[$name]->get(PortableUi::KEYWORD);
          if (isset($ui['options']['emptyOption']) && ($ui['widget'] ?? NULL) !== 'radios') {
            // Refused by the save that moved what it refines against, not
            // kept: its empty option does not say "keep".
            // Decision: see docs/decisions.md#the-empty-option-rule.
            $ui['options']['emptyOption']['label'] = (string) (DefinitionMetadata::holdsValue($current->definition) ? $this->t('- Select -') : $this->t('- None -'));
            $properties[$name] = $properties[$name]->with(PortableUi::KEYWORD, $ui);
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
          $base = $current->definition === $stated->definition ? $properties[$name] : $this->propertySchema->property($stated->definition, NULL, NULL, $prefix . $name, FALSE, [], $emission->withoutStale())[0];
          $enumerated = $this->conditionals->enumerate($declared, $static, $entry, $values, $base, $emission);
          if ($enumerated !== NULL && $current->definition === $stated->definition) {
            $properties[$name] = $enumerated['base'];
          }
        }
      }
      if ($full && !$declaring && $entry->runtimeAccess) {
        // An access answer only the subject decides was set aside for the
        // account alone: the key is as the static answers leave it, and
        // may be locked or left out once the subject is known, which the
        // schema for these answers settles: `live`, as a key a runtime
        // refiner narrows, since a client does the same for both.
        // Decision: see
        // docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability.
        $properties[$name] = static::extended($properties[$name], ['live' => TRUE]);
      }
      if ($at_site) {
        // Not stated here, so wherever its coded refiners may rule it
        // out, it is not required here either.
        [$properties[$name], $requires] = $this->conditionals->mayNotApply($entry, SurfaceInterface::CODED, $properties[$name], $requires);
      }
      if ($ahead_of_time) {
        if ($enumerated === NULL) {
          $properties[$name] = static::extended($properties[$name], ['refine' => TRUE]);
          // Not stated, so wherever its coded refiners may rule it out,
          // it is not required. Decision: see
          // docs/decisions.md#a-key-that-does-not-apply.
          [$properties[$name], $requires] = $this->conditionals->mayNotApply($entry, SurfaceInterface::CODED, $properties[$name], $requires);
        }
        else {
          $properties[$name] = static::extended($properties[$name], ['enumerated' => TRUE]);
          $stating = $enumerated['branches'];
          $requires = $requires && $enumerated['required'];
        }
      }
      if (!$runtime && $entry->isDeclared()) {
        // Each rule as written: a conditional of its own, after what the
        // coded refiners answer, as the pipeline runs them. One reading a
        // port is the site's to state.
        // Decision: see docs/decisions.md#a-rule-declared-as-data.
        // The frame's `x-surface.rules` lists each conditional that states
        // one; the key itself carries no marker.
        [$properties[$name], $stating, $requires] = $this->conditionals->rules($entry, $current->definition, $properties[$name], $stating, $requires, $emission, array_keys($ports));
      }
      if ($entry->mounted !== [] && !$declaring) {
        // The structures alters attached at their mounts: each module's
        // property a site of the structure's def, and what the structure
        // answers for keys its ports read stated here, on this frame.
        [$properties[$name], $branches] = $this->mounts($declared, $static, $entry, $current, $properties[$name], $values, $locked, $emission);
        array_push($stating, ...$branches);
      }
      array_push($conditions, ...$stating);
      if ($entry->isRefinedAtRuntime()) {
        // Declared impossible to capture: never in a conditional, never
        // in the full schema, and only in this one for its values.
        $properties[$name] = static::extended($properties[$name], ['live' => TRUE]);
        if (!$runtime) {
          // Never run here, so wherever it may rule the key out, the key
          // is not required.
          [$properties[$name], $requires] = $this->conditionals->mayNotApply($entry, SurfaceInterface::RUNTIME, $properties[$name], $requires);
        }
      }
      if ($requires) {
        $required[] = $name;
      }
    }
    $schema = Schema::object()->with('properties', $properties)->with('additionalProperties', FALSE);
    if ($required !== []) {
      $schema = $schema->with('required', $required);
    }
    if ($conditions !== []) {
      $schema = $schema->with('allOf', $conditions);
    }
    if ($ruled !== []) {
      $schema = $schema->with(self::EXTENSION, ['doesNotApply' => $ruled]);
    }
    // A map of keys from the site reads a sibling map only to hand its
    // entries their ports: no key of its own reads it, and it is not listed.
    $listed = array_filter($ports, static fn (Port $port): bool => !$port->through);
    if ($listed !== []) {
      // What the frame reads from its container, by the names its keys'
      // `dependsOn` use; the site says which key of the container each is.
      $read = array_map(static fn (Port $port): array => [
        'type' => $port->type,
        'required' => $port->required,
      ] + ($port->shape === NULL ? [] : ['shape' => $port->shape]), $listed);
      $schema = static::extended($schema, ['ports' => $read]);
    }
    if ($advertised->frameConstraints() !== []) {
      // What a schema read into this frame says of the object as a whole,
      // which no keyword here states: checked by the pipeline.
      // Decision: see
      // docs/decisions.md#a-schema-can-be-read-back-into-a-structure.
      // Several are one check, their schemas held together, named by what
      // all of them say, so reading the document back reads that check.
      $frame = $advertised->frameConstraints();
      $together = count($frame) === 1 ? reset($frame) : ['allOf' => array_values($frame)];
      $schema = PortableKeywords::before($schema, PortableKeywords::write([static::foreign($together, $this->stringTranslation)]));
    }
    if ($advertised->mixins() !== []) {
      // Decision: see docs/decisions.md#a-mixin-is-merged-flat.
      $schema = $this->composed($advertised, $schema, $emission);
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
   * @param \Drupal\surface\DefinitionMap $advertised
   *   The frame's definitions, which say which structures are mixed in.
   * @param \PortableSchema\Builder\Schema $schema
   *   The frame, described flat.
   * @param \Drupal\surface\Contract\Emission $emission
   *   The walk, at the frame: its owner is the id the frame was built from.
   *
   * @return \PortableSchema\Builder\Schema
   *   The frame, composed.
   */
  protected function composed(DefinitionMap $advertised, Schema $schema, Emission $emission): Schema {
    $owner = $emission->owner;
    $subschemas = $emission->subschemas;
    $flat = $schema->get('properties') ?? [];
    $required = $schema->get('required') ?? [];
    $conditions = $schema->get('allOf') ?? [];
    $references = [];
    $members = [];
    foreach ($advertised->mixins() as $mixin) {
      $key = $this->define($mixin->structure, $owner . '.' . $mixin->structure->source, $emission);
      // Read flat: a structure it mixes in itself says its members there.
      $def = SchemaResolver::inline($subschemas->get($key) ?? Schema::any(), Schema::any()->with('$defs', $subschemas->all()));
      $references[] = Schema::any()->with('$ref', self::DEFS . self::pointer($key));
      $declared = is_array($def->get('properties')) ? $def->get('properties') : [];
      foreach ($mixin->keys as $name) {
        $members[$name] = $declared[$name] ?? Schema::any();
      }
      $required = array_values(array_diff($required, $def->get('required') ?? []));
      $stated = is_array($def->get('allOf')) ? $def->get('allOf') : [];
      $conditions = array_values(array_filter($conditions, static fn (Schema $condition): bool => array_filter($stated, static fn (mixed $said): bool => Schemas::same($said, $condition)) === []));
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
      if (static::childAsDeclared($advertised, $name, $flat[$name], $members[$name], $subschemas)) {
        $properties[$name] = TRUE;
        continue;
      }
      $narrowing = $flat[$name]->isBoolean() ? $flat[$name] : $this->propertySchema->narrowing($flat[$name], $members[$name], $subschemas->transient, $subschemas->ui);
      $properties[$name] = $narrowing->all() === [] && !$narrowing->isBoolean() ? TRUE : $narrowing;
    }
    $composed = Schema::object()->with('properties', $properties)->with('unevaluatedProperties', FALSE);
    if ($required !== []) {
      $composed = $composed->with('required', $required);
    }
    $composed = $composed->with('allOf', [...$references, ...$conditions]);
    $composing = ['type', 'properties', 'additionalProperties', 'required', 'allOf'];
    return Schemas::defaults($composed, array_diff_key($schema->all(), array_flip($composing)));
  }

  /**
   * Answers whether a member holding a child is here what its def says.
   *
   * A child a mixin brings is the owner's child at the key, the same
   * child the mixin's own build holds, and variants it holds at a key are
   * the owner's there, the same variants; so the owner's frame and the
   * def each name them by reference. The def is
   * read flat, its references followed, so the member is followed too
   * before the two are compared: the same, it says nothing the def does
   * not.
   *
   * Decision: see docs/decisions.md#a-mixin-is-merged-flat.
   *
   * @param \Drupal\surface\DefinitionMap $advertised
   *   The frame's definitions.
   * @param string $name
   *   The member.
   * @param \PortableSchema\Builder\Schema $property
   *   What the frame, described flat, says of it.
   * @param \PortableSchema\Builder\Schema $def
   *   What the def says of it, read flat.
   * @param \Drupal\surface\Contract\Subschemas $subschemas
   *   The defs written so far, the child's among them.
   *
   * @return bool
   *   TRUE for a member holding a child or variants that the frame states
   *   as its def does; FALSE otherwise, a plain member among them.
   */
  protected static function childAsDeclared(DefinitionMap $advertised, string $name, Schema $property, Schema $def, Subschemas $subschemas): bool {
    $entry = $advertised->entry($name);
    if ($property->isBoolean() || $def->isBoolean() || ($entry?->attachment === NULL && $entry?->list === NULL && $entry?->variants === NULL)) {
      return FALSE;
    }
    // Followed as a property, as the def's is: a union of variants is
    // what the conditionals state, and read flat it is not said.
    $followed = SchemaResolver::inline(Schema::any()->with('properties', [$name => $property]), Schema::any()->with('$defs', $subschemas->all()))->get('properties')[$name] ?? NULL;
    return Schemas::isObjectSchema($followed) && Schemas::same($followed, $def);
  }

  /**
   * Lists the structures a data surface mixes in, at any depth.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param array<int, true> $seen
   *   The frames walked, by object id.
   *
   * @return string[]
   *   Their ids: each one's def is written open in this document.
   */
  protected function mixedIn(SurfaceInterface $surface, array &$seen = []): array {
    // A sealed child may be held at many keys: each is walked once.
    if (isset($seen[spl_object_id($surface)])) {
      return [];
    }
    $seen[spl_object_id($surface)] = TRUE;
    $ids = [];
    foreach ($surface->getDefinitions()->mixins() as $mixin) {
      $ids[] = (string) ($mixin->structure->id ?? $mixin->structure->source);
      array_push($ids, ...$this->mixedIn($mixin->structure->child, $seen));
    }
    foreach ($surface->getDefinitions()->entries() as $entry) {
      $children = match (TRUE) {
        // A list the site decides past its budget is stated by value, and
        // a variant of it only where a door chose it, which walks it then
        // (Subschemas::openAlso()): one not built yet is not built here.
        // Decision: see
        // docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
        $entry->variants !== NULL && $this->budget?->variantsOver($entry->variants) => array_filter($entry->variants->variants, static fn (Attachment $variant): bool => $variant->isSettled()),
        $entry->variants !== NULL => $entry->variants->variants,
        $entry->attachment !== NULL => [$entry->attachment],
        $entry->list !== NULL => [$entry->list->item],
        default => [],
      };
      foreach ([...$children, ...$entry->mounted] as $child) {
        array_push($ids, ...$this->mixedIn($child->child, $seen));
      }
    }
    return array_values(array_unique($ids));
  }

  /**
   * Answers whether a key's coded refiners read a port that holds nothing.
   *
   * @param \Drupal\surface\Entry $entry
   *   The key.
   * @param array<string, \Drupal\surface\Port> $ports
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
   * @param \Drupal\surface\SurfaceInterface $declared
   *   The container's frame, as advertised.
   * @param \Drupal\surface\SurfaceInterface $static
   *   The same, refined for its values by its static refiners alone.
   * @param \Drupal\surface\Entry $entry
   *   The key holding the child, or the mounts.
   * @param \Drupal\surface\Attachment $attachment
   *   The child, wired.
   * @param string $below
   *   Where the child sits below the key: '' for an attached child, the
   *   module for a structure at a mount.
   * @param array $values
   *   The container's values.
   * @param \Drupal\surface\Contract\Emission $emission
   *   The walk, at the container's frame. Whether it is for one set of
   *   values decides whether a rule is evaluated, not stated.
   * @param \PortableSchema\Builder\Schema|null $inline
   *   The key's property as this frame states it inline, for a mount;
   *   NULL for an attached child, which is described here.
   *
   * @return array{0: \PortableSchema\Builder\Schema[], 1: array<string, true>}
   *   The conditionals, and the markers the site carries: `enumerated`
   *   or `refine`.
   */
  protected function site(SurfaceInterface $declared, SurfaceInterface $static, Entry $entry, Attachment $attachment, string $below, array $values, Emission $emission, ?Schema $inline = NULL): array {
    $map = $attachment->child->getDefinitions();
    $ports = $map->ports();
    $held = $attachment->ports ?? $attachment->readFrom($values)->ports ?? [];
    $empty = array_filter($attachment->reads, static fn (string $port): bool => !ValueState::isConfigured($held[$port] ?? NULL), ARRAY_FILTER_USE_KEY);
    if ($empty === []) {
      // Every port holds a value: the instance is refined for them.
      return [[], []];
    }
    $definition = $static->getDefinition($entry->name) ?? $entry->definition;
    $base = $inline ?? $this->conditionals->stated($entry, $definition, $emission);
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
        foreach ($child->mayNotApply(SurfaceInterface::CODED) as $inside) {
          $ruled[] = $inside === '' ? (string) $name : $name . '.' . $inside;
        }
      }
      if ($reading) {
        // The def requires none of what may not apply; nor does the base a
        // conditional is read over.
        $enumerated = $mixed ? NULL : $this->conditionals->enumerate($declared, $static, $entry, $values, Conditionals::requiringNone($base, $ruled), $emission);
        $markers[$enumerated === NULL ? 'refine' : 'enumerated'] = TRUE;
        array_push($branches, ...($enumerated['branches'] ?? []));
      }
    }
    if ($emission->runtime()) {
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
      $pseudo = new Entry($entry->name, $definition, refiners: [SurfaceInterface::OWNER => $links]);
      [, $stated] = $this->conditionals->rules($pseudo, $definition, $base, [], FALSE, $emission);
      array_push($branches, ...$stated);
    }
    return [$branches, $markers];
  }

  /**
   * Lists the ports of a data surface that a map reads for its entries.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   *
   * @return array<string, \Drupal\surface\Port>
   *   Its through ports, by name.
   */
  protected static function throughPorts(SurfaceInterface $surface): array {
    return array_filter($surface->getDefinitions()->ports(), static fn (Port $port): bool => $port->through);
  }

  /**
   * Answers whether a child reads its ports through a port of its frame.
   *
   * An entry of a map of keys from the site reads a sibling map's entry
   * through the map's own port: what depends on it is stated where the
   * map's port is wired, in the owner's frame, never in the map's.
   *
   * @param \Drupal\surface\Attachment $attachment
   *   The child, wired.
   * @param array<string, \Drupal\surface\Port> $ports
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
   * @param \Drupal\surface\Attachment $attachment
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
   * @param \Drupal\surface\SurfaceInterface $declared
   *   This frame, as advertised.
   * @param \Drupal\surface\SurfaceInterface $static
   *   The same, refined for its values by its static refiners alone.
   * @param \Drupal\surface\Entry $entry
   *   The key holding the map.
   * @param \Drupal\surface\Attachment $attachment
   *   The map, wired to its siblings.
   * @param array $values
   *   This frame's values.
   * @param \Drupal\surface\Contract\Emission $emission
   *   The walk, at this frame.
   *
   * @return array{0: \PortableSchema\Builder\Schema[], 1: array<string, true>}
   *   The conditionals, and the markers the key carries.
   */
  protected function entrySites(SurfaceInterface $declared, SurfaceInterface $static, Entry $entry, Attachment $attachment, array $values, Emission $emission): array {
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
      $site = $child->withReads($reads, $child->wired);
      [$stated, $marked] = $this->site($declared, $static, $entry, $site, (string) $id, $values, $emission);
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
   * @param \Drupal\surface\SurfaceInterface $declared
   *   The frame, as advertised.
   * @param \Drupal\surface\SurfaceInterface $static
   *   The same, refined for its values by its static refiners alone.
   * @param \Drupal\surface\Entry $entry
   *   The key holding the mounts.
   * @param \Drupal\surface\Entry $current
   *   The same key, as it stands.
   * @param \PortableSchema\Builder\Schema $property
   *   Its property, stated inline.
   * @param array $values
   *   The frame's values.
   * @param bool $locked
   *   Whether the key is described locked.
   * @param \Drupal\surface\Contract\Emission $emission
   *   The walk, at the frame.
   *
   * @return array{0: \PortableSchema\Builder\Schema, 1: \PortableSchema\Builder\Schema[]}
   *   The property, each mount a site, and the conditionals for the
   *   frame's `allOf`.
   */
  protected function mounts(SurfaceInterface $declared, SurfaceInterface $static, Entry $entry, Entry $current, Schema $property, array $values, bool $locked, Emission $emission): array {
    $subschemas = $emission->subschemas;
    $branches = [];
    foreach ($entry->mounted as $module => $attachment) {
      $module = (string) $module;
      // Bound as the refinement of this frame bound it.
      $attachment = $current->mounted[$module] ?? $attachment;
      $inline = ($property->get('properties') ?? [])[$module] ?? NULL;
      if (!$inline instanceof Schema || $inline->isBoolean()) {
        continue;
      }
      $path = $emission->at($entry->name);
      $markers = [];
      if (!$locked) {
        [$stated, $markers] = $this->site($declared, $static, $entry, $attachment, $module, $values, $emission, $property);
        array_push($branches, ...$stated);
      }
      $around = $this->enter($entry->name, $module);
      try {
        $key = $this->define($attachment, $emission->owner . '.' . $entry->name . '.' . $module, $emission);
        $child = $attachment->bound($values);
        $held = NestedArray::getValue($values, [$entry->name, $module]);
        $inside = $emission->full() || $emission->blank ? $this->known($child) : array_replace($child->getDefaultValues(), is_array($held) ? $held : []);
        [$instance] = $this->instance($child, $key, $inside, $emission->withPrefix($path . '.' . $module . '.')->withOwner($attachment->id ?? $key)->withFrozen($locked)->withoutStale());
      }
      finally {
        $this->site = $around;
      }
      $map = $current->definition instanceof ComplexDataDefinitionInterface ? $current->definition->getPropertyDefinition($module) : NULL;
      $site = Schemas::first($this->propertySchema->reference($key, $instance, $subschemas), $map === NULL ? [] : $this->propertySchema->describe($map)->all());
      if ($inline->get('readOnly') !== NULL) {
        $site = $site->with('readOnly', $inline->get('readOnly'));
      }
      $ui = $inline->get(PortableUi::KEYWORD);
      $site = PortableUi::set($site, is_array($ui) ? $ui : []);
      $extension = $inline->get(self::EXTENSION);
      $site = $site->with(self::EXTENSION, (is_array($extension) ? $extension : []) + ['reads' => $attachment->reads] + $markers);
      $properties = $property->get('properties');
      $properties[$module] = $site;
      $property = $property->with('properties', $properties);
    }
    return [$property, $branches];
  }

  /**
   * Answers whether every key a key's static refiners watch holds a value.
   *
   * @param \Drupal\surface\Entry $entry
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
   * Describes a collection: an array whose items refer to the child's def.
   *
   * The list is the parent's key, so its own property says what the
   * parent says about it: its title and description, `minItems` and
   * `maxItems` from its Count as the parent's refiners left it, whether
   * it is locked, and `x-surface.collection`. Its `items` refer to the child's
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
   * @param \Drupal\surface\SurfaceInterface $declared
   *   The data surface the collection belongs to.
   * @param \Drupal\surface\Collection $list
   *   The child every item is.
   * @param \Drupal\surface\Entry $current
   *   The key as it stands, refined by the parent.
   * @param \Drupal\surface\Entry $entry
   *   The key as advertised.
   * @param array $values
   *   The values of the collection's frame.
   * @param bool $locked
   *   Whether the list is locked: its items are then described locked.
   * @param string[] $depends
   *   The siblings the parent's refiners of the list watch.
   * @param \Drupal\surface\Contract\Emission $emission
   *   The walk, at the collection's frame: its owner is the id of the
   *   resource or structure it belongs to, a stale value inside an item
   *   is listed under its delta, and a def (declaring) has its items as a
   *   bare reference.
   *
   * @return array{0: \PortableSchema\Builder\Schema, 1: mixed}
   *   The collection's property schema, and its value as shown: one map
   *   per item, or NULL for a list that holds nothing.
   */
  protected function collection(SurfaceInterface $declared, Collection $list, Entry $current, Entry $entry, array $values, bool $locked, array $depends, Emission $emission): array {
    $path = $emission->at($entry->name);
    $owner = $emission->owner;
    $declaring = $emission->declaring;
    $subschemas = $emission->subschemas;
    $child = $list->child();
    $fallback = $owner . '.' . $entry->name;
    // Every item is one structure: a key inside one is named by the
    // first item's index.
    $around = $this->enter($entry->name, '0');
    try {
      $key = $this->define($list->item, $fallback, $emission);
      if ($declaring) {
        $items = $this->propertySchema->reference($key, NULL, $subschemas);
      }
      else {
        [$instance] = $this->instance($child, $key, $this->known($child), $emission->withPrefix($path . '.*.')->withOwner($list->item->id ?? $fallback)->withStanding([])->withFrozen($locked)->withBlank(TRUE)->withoutStale());
        $items = $this->propertySchema->reference($key, $instance, $subschemas);
      }
    }
    finally {
      $this->site = $around;
    }
    $definition = $current->definition;
    [$schema, $checks] = $this->propertySchema->listKeywords($definition);
    $schema = $schema->with('items', $items);
    if (DefinitionMetadata::hasDefaultValue($definition) && !$declaring) {
      $schema = $schema->with('default', DefinitionMetadata::getDefaultValue($definition));
    }
    if ($locked) {
      $schema = $schema->with('readOnly', TRUE);
    }
    $schema = Schemas::defaults($schema, $checks);
    $schema = PortableUi::set($schema, PortableUi::write('list'));
    $schema = $schema->with(self::EXTENSION, $this->propertySchema->extension($depends, $this->propertySchema->signature($entry->definition) !== $this->propertySchema->signature($definition)) + ['collection' => TRUE]);

    $shown = NULL;
    $held = $values[$entry->name] ?? $declared->getDefault($entry->name);
    if (!$emission->full() && !$declaring && is_array($held)) {
      // Each item as its element would be built with, read in the
      // child's own frame: a secret not handed over, a stale value shown
      // as nothing and listed under its delta.
      $shown = [];
      foreach (array_values($held) as $delta => $item) {
        $inside = array_replace($child->getDefaultValues(), is_array($item) ? $item : []);
        [, $shown[]] = $this->frame($child, $inside, $emission->withPrefix($path . '.' . $delta . '.')->withOwner($list->item->id ?? $fallback)->withFrozen($locked)->withBlank(FALSE));
      }
    }
    return [$schema, $shown];
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
   * @param \Drupal\surface\Attachment $attachment
   *   The child at its key.
   * @param string $fallback
   *   The id to define it under when it has no `#[Resource]` or
   *   `#[Structure]` id of its own.
   * @param \Drupal\surface\Contract\Emission $emission
   *   The walk: the document's defs the def is written to, and the
   *   cacheability it collects into.
   *
   * @return string
   *   The key under `$defs`.
   */
  protected function define(Attachment $attachment, string $fallback, Emission $emission): string {
    $subschemas = $emission->subschemas;
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
    [$def] = $this->frame($child, [], $emission->forDef($id));
    if ($subschemas->isOpen($id)) {
      // Mixed in somewhere in this document: open, so an owner composes it
      // with keys of its own, and closed by every other place it is used.
      // Decision: see docs/decisions.md#a-mixin-is-merged-flat.
      $def = $def->without('additionalProperties')->without('unevaluatedProperties');
    }
    $def = $this->documentHead->versioned($child, $def, FALSE);
    return $subschemas->add($id, $def, $child, $attachment->label, $attachment->description);
  }

  /**
   * Describes a child as it is at one site: its instance.
   *
   * In the full schema a child with nothing locked anywhere inside it is
   * its own def, so its def is read back rather than described twice;
   * unless a link is written below it (linksBelow()), which a def never
   * holds: the site's narrowing carries it.
   *
   * @param \Drupal\surface\SurfaceInterface $child
   *   The child.
   * @param string $key
   *   The key its def is filed under.
   * @param array $values
   *   Its values.
   * @param \Drupal\surface\Contract\Emission $emission
   *   The walk, at the child: its path from the top, its id, the orphans
   *   standing in it, whether it is locked whole (by its access or its
   *   parent's) and whether it stands for every item of a collection. Not
   *   a def.
   *
   * @return array{0: \PortableSchema\Builder\Schema, 1: array|\stdClass}
   *   The child's object schema here, and its values as shown.
   */
  protected function instance(SurfaceInterface $child, string $key, array $values, Emission $emission): array {
    $def = $emission->subschemas->get($key);
    $bound = array_filter($child->getPortValues(), ValueState::isConfigured(...)) !== [];
    if ($emission->full() && $def !== NULL && !$emission->frozen && !$bound && !$this->locks($child) && !$this->linksBelow($child, $this->site['reached'], $emission->cacheability)) {
      return [$def, new \stdClass()];
    }
    return $this->frame($child, $values, $emission);
  }

  /**
   * Answers whether a context locked anything in a data surface.
   *
   * Or whether access marked anything in it as decided at runtime: either
   * makes a child differ from its def, so it is described where it sits.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   *
   * @return bool
   *   TRUE when a key of it, or of a child inside it, is locked or marked.
   */
  protected function locks(SurfaceInterface $surface): bool {
    static $asked = NULL;
    $asked ??= new \WeakMap();
    return $asked[$surface] ??= $this->locksBelow($surface);
  }

  /**
   * Answers locks() for a data surface not asked about before.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   *
   * @return bool
   *   TRUE when a key of it, or of a child inside it, is locked or marked.
   */
  protected function locksBelow(SurfaceInterface $surface): bool {
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
   * Moves where the frame being described is, one key down.
   *
   * @param string $name
   *   The key the next frame is at.
   * @param string|null $below
   *   A segment past the key: a collection's item, a mount's module.
   * @param string|null $chosen
   *   The variant the next frame is, reached by choosing it at the key.
   *
   * @return array{pointer: string, steps: list<array{0: string, 1: string}>, reached: bool}
   *   Where the frame was, to put back once the next one is described.
   */
  protected function enter(string $name, ?string $below = NULL, ?string $chosen = NULL): array {
    $was = $this->site;
    $pointer = $was['pointer'] . '/' . Choices::escape($name);
    $steps = $was['steps'];
    if ($chosen !== NULL) {
      $steps[] = [$pointer, $chosen];
    }
    $this->site = [
      'pointer' => $below === NULL ? $pointer : $pointer . '/' . Choices::escape($below),
      'steps' => $steps,
      'reached' => $was['reached'] && $below === NULL && $chosen === NULL,
    ];
    return $was;
  }

  /**
   * Names the lookup a document's reference keys link to, where one answers.
   *
   * The URL the caller gives, the request's parameters in it, as the full
   * schema's is given for a `choose` link; without one, the lookup's path,
   * where it takes no parameter, since a variable the document does not
   * explain is never written.
   *
   * Decision: see
   * docs/decisions.md#a-reference-and-a-deferred-list-are-the-dialects-check-options-and-links.
   *
   * @param string $resource_id
   *   The id the document is written for.
   * @param string|null $operation_id
   *   Its operation, or NULL for none.
   * @param string|null $url
   *   The lookup's URL for the request, or NULL.
   *
   * @return string|null
   *   The URL or path; NULL with no HTTP door, no operation, an operation
   *   `GET` does not serve at its path (ServedPathsInterface::lookupPath()),
   *   an id the registry does not know (a data surface built in place, a
   *   document read back), or a path that takes a parameter and no URL.
   */
  protected function lookup(string $resource_id, ?string $operation_id, ?string $url): ?string {
    if ($this->servedPaths === NULL || $operation_id === NULL) {
      return NULL;
    }
    try {
      $path = $this->servedPaths->lookupPath($resource_id, $operation_id);
    }
    catch (\InvalidArgumentException | RouteNotFoundException) {
      return NULL;
    }
    if ($path === NULL) {
      return NULL;
    }
    if ($url !== NULL) {
      return $url;
    }
    return str_contains($path, '{') ? NULL : $path;
  }

  /**
   * Answers whether a child holds a key a link is written at.
   *
   * A key that refers to an entity, where the lookup reaches it, or a list
   * deferred for its size, whose `choose` link names its site. A def holds
   * neither, so the site's narrowing does.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The child.
   * @param bool $reached
   *   Whether the lookup reaches its keys.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   What the budget's answer depends on.
   *
   * @return bool
   *   TRUE when a link is written below it.
   */
  protected function linksBelow(SurfaceInterface $surface, bool $reached, CacheableMetadata $cacheability): bool {
    foreach ($surface->getDefinitions()->entries() as $entry) {
      foreach ($entry->mounted as $mount) {
        if ($this->linksBelow($mount->child, FALSE, $cacheability)) {
          return TRUE;
        }
      }
      if ($entry->variants !== NULL) {
        if ($this->budget?->variantsOver($entry->variants, $cacheability)) {
          return TRUE;
        }
        foreach ($entry->variants->variants as $variant) {
          if ($this->linksBelow($variant->child, FALSE, $cacheability)) {
            return TRUE;
          }
        }
      }
      elseif ($entry->list !== NULL) {
        if ($this->linksBelow($entry->list->item->child, FALSE, $cacheability)) {
          return TRUE;
        }
      }
      elseif ($entry->attachment !== NULL) {
        if ($this->budget?->over($entry, $cacheability) || $this->linksBelow($entry->attachment->child, $reached, $cacheability)) {
          return TRUE;
        }
      }
      elseif ($reached && $this->lookup !== NULL && (References::target($entry->definition) !== NULL || $entry->definition->getSetting(References::TARGETS) !== NULL)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Adds the lookup link to a key that refers to an entity.
   *
   * Every such key the lookup finds, listed or not: one that names its
   * entity type by its pointer, and an id whose entity type a sibling
   * names with `type` read from that sibling. Nothing where no lookup is
   * served or the lookup does not reach the frame, and nothing in a def:
   * the pointer is a site's, and several sites share a def, so the site's
   * narrowing carries the link.
   *
   * Decision: see
   * docs/decisions.md#a-reference-and-a-deferred-list-are-the-dialects-check-options-and-links.
   *
   * @param \PortableSchema\Builder\Schema $property
   *   The key's schema.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key as it stands.
   * @param string $name
   *   The key.
   * @param bool $declaring
   *   Whether the frame is a def.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema, with the link where it has one.
   */
  protected function linked(Schema $property, DataDefinitionInterface $definition, string $name, bool $declaring): Schema {
    if ($this->lookup === NULL || !$this->site['reached'] || $declaring) {
      return $property;
    }
    $pointer = $this->site['pointer'] . '/' . Choices::escape($name);
    return match (TRUE) {
      $definition->getSetting(References::TARGETS) !== NULL => PortableLinks::set($property, PortableLinks::lookup($this->lookup, $pointer, References::TYPE_KEY)),
      References::target($definition) !== NULL => PortableLinks::set($property, PortableLinks::lookup($this->lookup, $pointer)),
      default => $property,
    };
  }

  /**
   * Writes how to fetch the full schema with one entry of a key stated.
   *
   * An RFC 6570 template: the full schema's URL with `choose` added for
   * every variant on the way to the key and one for the key itself, its
   * value the expression `{value}`, which a client fills with the
   * deciding key's value or the entry's id: the `href` of the key's
   * `choose` link (PortableLinks::choose()).
   *
   * Decision: see
   * docs/decisions.md#a-list-left-out-for-size-is-deferred-not-refined.
   *
   * @param string $pointer
   *   The key's JSON Pointer.
   *
   * @return string
   *   The template.
   */
  protected function fetch(string $pointer): string {
    $choices = [];
    foreach ($this->site['steps'] as [$at, $value]) {
      $choices[$at][] = $value;
    }
    $query = Choices::query($choices);
    $query .= ($query === '' ? '' : '&') . Choices::PARAMETER . '=' . Choices::encode($pointer) . '=' . Choices::VALUE;
    $base = $this->fetchBase ?? '';
    return $base . (str_contains($base, '?') ? '&' : '?') . $query;
  }

  /**
   * States the entries chosen of a map of keys from the site.
   *
   * Each as the map's def states it under the limit, read from the map's
   * own frame for no values; the rest are left out.
   *
   * @param string $name
   *   The map's key.
   * @param \Drupal\surface\Attachment $attachment
   *   The map, attached at its key.
   * @param string $fallback
   *   The id to describe it under when it has none of its own.
   * @param list<string> $picked
   *   The ids chosen.
   * @param \Drupal\surface\Contract\Emission $emission
   *   The walk: the document's defs, which an entry's structure is filed
   *   in, and the cacheability it collects into.
   *
   * @return array{0: array<string, \PortableSchema\Builder\Schema>, 1: list<string>}
   *   The entries' properties, in the map's order, and those required.
   */
  protected function entriesChosen(string $name, Attachment $attachment, string $fallback, array $picked, Emission $emission): array {
    $around = $this->enter($name);
    try {
      [$map] = $this->frame($attachment->child, [], $emission->forDef($attachment->id ?? $fallback));
    }
    finally {
      $this->site = $around;
    }
    $chosen = array_flip($picked);
    $properties = array_intersect_key($map->get('properties') ?? [], $chosen);
    $required = array_values(array_filter($map->get('required') ?? [], static fn (mixed $id): bool => isset($chosen[(string) $id])));
    return [$properties, $required];
  }

  /**
   * Gives the document its `$defs`, last, when it has any.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The top frame.
   * @param \Drupal\surface\Contract\Subschemas $subschemas
   *   The defs written.
   *
   * @return \PortableSchema\Builder\Schema
   *   The top frame, with `$defs` where there are any.
   */
  protected function definitions(Schema $schema, Subschemas $subschemas): Schema {
    $defs = $subschemas->named();
    return $defs === [] ? $schema : Schemas::defaults($schema, ['$defs' => $defs]);
  }

  /**
   * Adds members to a node's `x-surface`: each replaces one of its name.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The node.
   * @param array $members
   *   The members.
   *
   * @return \PortableSchema\Builder\Schema
   *   The node; a member it had keeps its place, a new one goes last.
   */
  protected static function extended(Schema $node, array $members): Schema {
    return $node->with(self::EXTENSION, array_replace((array) $node->get(self::EXTENSION), $members));
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
   * @param \Drupal\surface\SurfaceInterface $declared
   *   The data surface the variants belong to.
   * @param \Drupal\surface\Entry $entry
   *   The entry of the key the variants are attached at.
   * @param array $values
   *   The values of the variants' frame.
   * @param bool $locked
   *   Whether the key is locked, or inside a child locked whole: every
   *   variant is then described locked.
   * @param \Drupal\surface\Contract\Emission $emission
   *   The walk, at the variants' frame. Its owner is the id of the
   *   resource or structure the variants belong to; only the chosen
   *   variant's orphans of its standing are its own; in the full schema
   *   every variant is described from its own locked keys, none as the
   *   chosen one; in a def (declaring) every variant is a bare reference
   *   and a locked deciding key chooses nothing; inside a collection's
   *   items (blank) no variant is chosen and every one is described from
   *   its locked keys.
   *
   * @return array{0: \PortableSchema\Builder\Schema, 1: mixed, 2: \PortableSchema\Builder\Schema[]}
   *   The key's property schema, its value as shown, and the
   *   conditionals for the parent's `allOf`.
   */
  protected function variants(SurfaceInterface $declared, Entry $entry, array $values, bool $locked, Emission $emission): array {
    $full = $emission->full();
    $path = $emission->at($entry->name);
    $owner = $emission->owner;
    $cacheability = $emission->cacheability;
    $subschemas = $emission->subschemas;
    $declaring = $emission->declaring;
    $blank = $emission->blank;
    $variants = $entry->variants;
    assert($variants !== NULL);
    $by = $declared->getDefinitions()->get($variants->by);
    $deciding = $values[$variants->by] ?? ($declaring && $declared->isLocked($variants->by) ? NULL : $declared->getDefault($variants->by));
    $chosen = $variants->chosen($deciding);
    $held = $values[$entry->name] ?? NULL;
    $shell = $this->propertySchema->describe($variants->shell);
    $property = $shell->with('type', $this->propertySchema->nullable('object', $variants->shell));
    $depends = array_values(array_unique([$variants->by, ...$entry->dependencies]));
    if ($locked) {
      $property = $property->with('readOnly', TRUE);
    }
    $property = PortableUi::set($property, PortableUi::write('variants'));
    $property = $property->with(self::EXTENSION, $this->propertySchema->extension($depends, $chosen !== NULL) + [
      'by' => $variants->by,
      'variants' => [],
    ]);
    if ($chosen !== NULL) {
      $property = static::extended($property, ['chosen' => $chosen]);
    }
    // Variants nothing has chosen keep what the key holds: it has no
    // element, so it says nothing about it.
    $shown = $held;
    $listed = $variants->variants;
    $deferred = FALSE;
    if ($this->budget?->variantsOver($variants, $cacheability)) {
      // More than the site's list budget: no def and no conditional per
      // variant. With nothing chosen (and in the full schema, which chooses
      // nothing) the key is an open object, `deferred`, its deciding key
      // named and how to fetch one variant written beside it; a full
      // schema fetched with a choice here states the variants chosen, each
      // its def and its conditional, and leaves the key open for the rest.
      // With a value chosen, the schema for these answers states the
      // chosen variant alone.
      // Decision: see
      // docs/decisions.md#a-list-left-out-for-size-is-deferred-not-refined.
      if ($full || $declaring || $blank || $chosen === NULL) {
        $pointer = $this->site['pointer'] . '/' . Choices::escape($entry->name);
        // The fetch is a link, `{value}` the deciding sibling's value;
        // written at the site, never in a def, which several sites share.
        $property = static::extended($property, ['deferred' => TRUE]);
        if (!$declaring) {
          $property = PortableLinks::set($property, PortableLinks::choose($this->fetch($pointer), $variants->by));
        }
        $picked = $this->choices?->variants($variants, $pointer) ?? [];
        if ($picked === []) {
          $extension = array_diff_key((array) $property->get(self::EXTENSION), ['variants' => TRUE]);
          return [$property->with(self::EXTENSION, $extension), $shown, []];
        }
        $deferred = TRUE;
        $listed = array_intersect_key($variants->variants, array_flip($picked));
      }
      else {
        $listed = [$chosen => $variants->variant($chosen)];
      }
    }
    $union = [];
    $branches = [];
    $pointers = [];
    foreach ($listed as $id => $variant) {
      $id = (string) $id;
      $child = $variant->child;
      if ($listed !== $variants->variants) {
        // Chosen past the budget: built now, so what it mixes in is known
        // now, before its def is written.
        $subschemas->openAlso($this->mixedIn($child));
      }
      $fallback = $owner . '.' . $entry->name . '.' . $id;
      $is_chosen = !$full && !$declaring && !$blank && $id === $chosen;
      // Below a variant, a key is reached through the choice of it.
      $around = $this->enter($entry->name, NULL, $id);
      try {
        $key = $this->define($variant, $fallback, $emission);
        if ($declaring) {
          $reference = $this->propertySchema->reference($key, NULL, $subschemas);
        }
        else {
          $own = $is_chosen ? ($variants->held($id, $held) ?? []) : [];
          $variant_stale = new \ArrayObject();
          $inside = $full || $blank ? $this->known($child) : array_replace($child->getDefaultValues(), $own);
          [$instance, $variant_values] = $this->instance($child, $key, $inside, $emission->withPrefix($path . '.')->withOwner($variant->id ?? $fallback)->withStanding($is_chosen ? $emission->standing : [])->withFrozen($locked)->withStale($variant_stale));
          if ($is_chosen) {
            $shown = $variant_values;
            $emission->markStale(...$variant_stale);
          }
          $reference = $this->propertySchema->reference($key, $instance, $subschemas);
        }
      }
      finally {
        $this->site = $around;
      }
      // Which def each value chooses, said on the key: nothing below the
      // `then` carries `x-surface`.
      $pointers[$id] = self::DEFS . self::pointer($key);
      $union[] = $this->propertySchema->reference($key, NULL, $subschemas);
      $branches[] = Schema::keywords([
        'if' => [
          'properties' => [$variants->by => ['const' => $by === NULL ? $id : $this->propertySchema->cast($id, $by)]],
          'required' => [$variants->by],
        ],
        'then' => [
          'properties' => [$entry->name => Schemas::first($reference, $shell->all())],
        ],
      ]);
    }
    // A deferred key keeps its type: the union of the variants chosen
    // would refuse every other, which the pipeline accepts.
    if ($union !== [] && !$deferred) {
      if (in_array('null', (array) $property->get('type'), TRUE)) {
        $union[] = Schema::null();
      }
      // The union says the type: each variant's def is an object, and
      // null is its own member. A `type` beside it would only be
      // intersected with it by a type generator.
      $said = array_intersect_key($property->all(), [PortableUi::KEYWORD => TRUE, self::EXTENSION => TRUE]);
      $property = $property->without(PortableUi::KEYWORD)->without(self::EXTENSION)->without('type');
      $property = Schemas::defaults($property, ['anyOf' => $union] + $said);
    }
    $extension = (array) $property->get(self::EXTENSION);
    if ($pointers === []) {
      // No variant to name: absent says the same as empty.
      unset($extension['variants']);
    }
    else {
      // A map whose keys are numeric strings is still a map.
      $extension['variants'] = (object) $pointers;
    }
    return [$property->with(self::EXTENSION, $extension), $shown, $branches];
  }

  /**
   * Describes one key as a conditional on it states it, in plain schema.
   *
   * What an agreement check compares: the key as declared, with a
   * conditional's `then` laid over it, against the key refined afresh
   * for the conditional's values, each described by this.
   *
   * @param \Drupal\surface\Entry $entry
   *   The key, from the data surface's definition map.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $definition
   *   The definition to describe; NULL for the entry's own.
   *
   * @return array|false
   *   The schema, with no `x-surface` at any depth; empty for a key nothing
   *   describes; FALSE for a key that does not apply.
   */
  public function describeKey(Entry $entry, ?DataDefinitionInterface $definition = NULL): array|false {
    $definition ??= $entry->definition;
    if (DoesNotApply::marks($definition)) {
      return FALSE;
    }
    $described = Schemas::arrayed($this->propertySchema->withoutExtension($this->conditionals->stated($entry, $definition, Emission::detached())));
    // A key nothing describes is the empty schema, a \stdClass.
    if ($described instanceof \stdClass) {
      return [];
    }
    assert(is_array($described));
    return $described;
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
    return $this->conditionals->refineCounts();
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
   *   The entry, with the schema beside the message, which
   *   PortableKeywords::write() writes as the check `drupal:JsonSchema`.
   */
  public static function foreign(array $schema, TranslationInterface $translation): array {
    // Schemas held together as an `allOf` alone are named by what each
    // says: the message a frame's several schemas were written with.
    $together = array_keys($schema) === ['allOf'] && is_array($schema['allOf']) && array_is_list($schema['allOf']) && array_filter($schema['allOf'], 'is_array') === $schema['allOf'];
    $keywords = $together ? array_values(array_unique(array_merge(...array_map(JsonSchemaConstraint::keywords(...), $schema['allOf'])))) : JsonSchemaConstraint::keywords($schema);
    return [
      'constraint' => 'JsonSchema',
      'message' => PlainText::of(new TranslatableMarkup('Checked on the server against the schema it was read from: @keywords.', ['@keywords' => implode(', ', $keywords)], [], $translation)),
      'schema' => $schema === [] ? new \stdClass() : $schema,
    ];
  }

}
