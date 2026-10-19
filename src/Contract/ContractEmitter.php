<?php

declare(strict_types=1);

namespace Drupal\data_surface\Contract;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DataSurface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMap;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Form\DataSurfaceFormBuilderInterface;
use Drupal\data_surface\Options\DataSurfaceOptions;
use Drupal\data_surface\Options\OptionSet;
use Drupal\data_surface\Pipeline\ValueState;
use Drupal\data_surface\Refinement\DeclaredRefiner;
use Drupal\data_surface\Refinement\DoesNotApply;
use Drupal\data_surface\Refinement\Rule;
use Drupal\data_surface\Attachment;
use Drupal\data_surface\Port;
use Drupal\data_surface\ResourceBuild\RefinesInputRefiner;
use Drupal\data_surface\Entry;
use Drupal\data_surface\Collection;

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
 * - a slot as a real conditional on its parent, one `if` naming the
 *   deciding key's `const` and one `then` naming the slot's shape per
 *   variant, so the union is stated where JSON Schema can state it;
 * - per key, what the data surface knows about it beyond its shape
 *   (`x-surface`): whether it is locked, which siblings its refiners
 *   watch, whether it is narrowed right now, whether a select shows its
 *   empty option and under what label, and whether the value it holds
 *   has gone stale;
 * - where a key's static refiners have nothing to refine against yet,
 *   what they would answer for every value their watched siblings could
 *   take, as conditionals on the parent beside the slots' (enumerate()),
 *   so a static contract is exact wherever the answers are finite; where
 *   they are not, that the key is `dynamic`, to be asked again; and where
 *   a runtime refiner narrows it too, that the key is `runtime`, which no
 *   schema states ahead of time.
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
 * (define()). Each place it is used, an attached key or a slot's
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
 *   one that cannot is marked `dynamic`. It is what a validator, or
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
 * an email input for the email type, a text input otherwise; a number is
 * a number input; a boolean a checkbox. Two hints have no Form API
 * counterpart here: `slot` for a key a sibling chooses the shape of, and
 * `list` for a list with no option list, which no stock widget claims.
 * `radios` is in the vocabulary for a consumer to honour, and is never
 * chosen by this emitter, because the Form API mapping never renders
 * radios.
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
   * The most conditionals one key is enumerated into.
   *
   * Counted per key, over the combinations of its watched siblings'
   * values: above it the key is `dynamic` instead.
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
  protected const WIDGET_HINTS = ['widget', 'multiple'];

  /**
   * Where every subschema a document defines is, as a JSON pointer.
   */
  public const DEFS = '#/$defs/';

  /**
   * Constraints the schema states in a keyword of its own, or not at all.
   *
   * NotNull is `required`; PrimitiveType, ComplexData and ValidReference
   * are the data type's own, which `type` states; the three list
   * constraints are `oneOf`. Anything not here and not stated by a
   * keyword is named under `x-surface.checkedOnServer`, so a client knows
   * the schema is not the whole story for that key.
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
   * Constructs a ContractEmitter.
   *
   * @param \Drupal\data_surface\Options\DataSurfaceOptions $options
   *   The options service, the one place a constraint is read as a list.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service, for the empty option's labels.
   */
  public function __construct(
    protected readonly DataSurfaceOptions $options,
    TranslationInterface $string_translation,
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
   *
   * @return \Drupal\data_surface\Contract\ServedContract
   *   The contract, and what it depends on.
   */
  public function emit(DataSurfaceInterface $surface, array $values, string $resource_id, ?string $operation_id = NULL, string|\Stringable|null $label = NULL, bool $widgets = FALSE): ServedContract {
    $cacheability = new CacheableMetadata();
    $stale = [];
    $standing = $values[DataSurfaceFormBuilderInterface::STANDING_KEY] ?? [];
    unset($values[DataSurfaceFormBuilderInterface::STANDING_KEY]);
    $subschemas = new Subschemas(FALSE, $widgets ? [] : self::WIDGET_HINTS, $this->mixedIn($surface));
    [$schema, $shown] = $this->frame($surface, $values, '', $resource_id, $cacheability, $stale, $subschemas, is_array($standing) ? $standing : []);
    $schema += $this->definitions($subschemas);
    if (!$widgets) {
      $schema = $this->withoutWidgets($schema);
    }
    $title = $label === NULL ? [] : ['title' => (string) $label];
    $document = [
      'surface' => $resource_id,
      'situation' => $operation_id,
      'label' => $label === NULL ? NULL : (string) $label,
      'schema' => ['$schema' => self::DIALECT] + $title + $schema,
      'values' => $shown,
      'stale' => $stale,
    ];
    $outputs = $this->outputs($surface, $cacheability);
    if ($outputs !== NULL) {
      $document['outputs'] = $outputs;
    }
    return new ServedContract($document, $cacheability);
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
   *   is `dynamic`: wider than what the pipeline will allow for some
   *   values, never narrower, and to be asked about with values;
   * - a slot is its conditionals, and every child's frame is described
   *   by the same rule, from nothing, not from the child's defaults;
   * - a runtime refiner contributes nothing but `runtime: true` on the key
   *   it narrows: it is never run here, so the key keeps the width its
   *   static refiners give it, wider than the pipeline for some values;
   * - `x-surface` carries only what holds for any values: `locked`,
   *   `dependsOn`, `enumerated`, `dynamic`, `runtime`, a slot's `by` and
   *   `variants`, a variant's id, `group`, `after`, `patternMessage`,
   *   `checkedOnServer`, and the widget hints when asked for. What is
   *   only true of a moment (`refined`, `stale`, `emptyOption`, a slot's
   *   `chosen`) is not written, and there is no `values` and no `stale`
   *   beside the schema.
   *
   * Decision: see
   * docs/decisions.md#two-documents-never-stricter-than-the-runtime.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface as built in its context, not refined.
   * @param string $resource_id
   *   The `#[Resource]` or `#[Structure]` id it was built from.
   * @param string|null $operation_id
   *   The operation id, or NULL for a data surface asked for in no
   *   operation.
   * @param string|\Stringable|null $label
   *   The operation's label, which titles the schema.
   * @param bool $widgets
   *   Whether to add the renderer's widget hints, as for emit().
   *
   * @return \Drupal\data_surface\Contract\ServedContract
   *   The document (`surface`, `situation`, `label`, `schema` and, when
   *   the data surface declares outputs, `outputs`), and what it depends
   *   on: the data surface and every option list read, never a stored
   *   value.
   */
  public function emitFull(DataSurfaceInterface $surface, string $resource_id, ?string $operation_id = NULL, string|\Stringable|null $label = NULL, bool $widgets = FALSE): ServedContract {
    $cacheability = new CacheableMetadata();
    $ignored = [];
    $subschemas = new Subschemas(TRUE, [...self::PRESENT, ...($widgets ? [] : self::WIDGET_HINTS)], $this->mixedIn($surface));
    [$schema] = $this->frame($surface, $this->known($surface), '', $resource_id, $cacheability, $ignored, $subschemas);
    $schema += $this->definitions($subschemas);
    $schema = $this->withoutPresent($schema);
    if (!$widgets) {
      $schema = $this->withoutWidgets($schema);
    }
    $title = $label === NULL ? [] : ['title' => (string) $label];
    $document = [
      'surface' => $resource_id,
      'situation' => $operation_id,
      'label' => $label === NULL ? NULL : (string) $label,
      'schema' => ['$schema' => self::DIALECT] + $title + $schema,
    ];
    $outputs = $this->outputs($surface, $cacheability);
    if ($outputs !== NULL) {
      $document['outputs'] = $outputs;
    }
    return new ServedContract($document, $cacheability);
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
      // out, and a slot's own deciding key is no refiner's.
      $ahead_of_time = $entry->codedDependencies() !== [] && !$locked && ($entry->isNested() ? $entry->refiners !== [] && !$this->holds($entry, $values) : !$answered);
      // What a key reading a port that holds nothing answers is said where
      // the port is wired, at the site, never here: a def is reused, and
      // its container's keys are no properties of it.
      $at_site = $ahead_of_time && $this->readsEmptyPort($entry, $ports, $values);
      $ahead_of_time = $ahead_of_time && !$at_site;
      $enumerated = NULL;
      $stating = [];
      if ($entry->slot !== NULL) {
        [$properties[$name], $shown[$name], $branches] = $this->slot($declared, $entry, $values, $prefix . $name, $owner, $cacheability, $stale, $subschemas, $standing, $declaring, $locked, $blank);
        array_push($conditions, ...$branches);
        if ($ahead_of_time) {
          // A parent's refiner of a slot says only whether it applies.
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
        if (!$declaring && $attachment->reads !== []) {
          // Which of this frame's keys each port reads, and what the child
          // answers for them where they hold nothing yet: said here, at the
          // site, on this frame's allOf, never in the child's def.
          // Decision: see docs/decisions.md#a-port-is-a-live-sibling-value.
          $properties[$name][self::EXTENSION]['reads'] = $attachment->reads;
          if (!$locked) {
            [$branches, $markers] = $this->site($declared, $static, $entry, $current->attachment ?? $attachment, '', $values, $prefix . $name, $cacheability, $kinds, $runtime);
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
      if ($at_site) {
        // Not stated here, so wherever its coded refiners may rule it
        // out, it is not required here either.
        [$properties[$name], $requires] = $this->mayNotApply($entry, DataSurfaceInterface::CODED, $properties[$name], $requires);
      }
      if ($ahead_of_time) {
        if ($enumerated === NULL) {
          $properties[$name][self::EXTENSION]['dynamic'] = TRUE;
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
        $properties[$name][self::EXTENSION]['runtime'] = TRUE;
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
    if ($ports !== []) {
      // What the frame reads from its container, by the names its keys'
      // `dependsOn` use; the site says which key of the container each is.
      $schema[self::EXTENSION]['ports'] = array_map(static fn (Port $port): array => [
        'type' => $port->type,
        'required' => $port->required,
      ], $ports);
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
    $ids = [];
    foreach ($surface->getDefinitions()->mixins() as $mixin) {
      $ids[] = (string) ($mixin->structure->id ?? $mixin->structure->source);
      array_push($ids, ...$this->mixedIn($mixin->structure->child));
    }
    foreach ($surface->getDefinitions()->entries() as $entry) {
      $children = match (TRUE) {
        $entry->slot !== NULL => $entry->slot->variants,
        $entry->attachment !== NULL => [$entry->attachment],
        $entry->list !== NULL => [$entry->list->item],
        default => [],
      };
      foreach ([...$children, ...$entry->mounted] as $child) {
        array_push($ids, ...$this->mixedIn($child->child));
      }
    }
    return array_values(array_unique($ids));
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
   *   or `dynamic`, and `declared`.
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
        $markers[$enumerated === NULL ? 'dynamic' : 'enumerated'] = TRUE;
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
                $markers['dynamic'] = TRUE;
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
   * Each carries `x-surface.declared`, beside its `if` and `then`.
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
    $unless = array_map(static fn (array $ifs): array => ['not' => count($ifs) === 1 ? $ifs[0] : ['anyOf' => $ifs]], $ruling);
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
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface.
   *
   * @return bool
   *   TRUE when a key of it, or of a child inside it, is locked.
   */
  protected function locks(DataSurfaceInterface $surface): bool {
    foreach ($surface->getDefinitions()->entries() as $entry) {
      $children = match (TRUE) {
        $entry->slot !== NULL => $entry->slot->variants,
        $entry->attachment !== NULL => [$entry->attachment],
        $entry->list !== NULL => [$entry->list->item],
        default => [],
      };
      if ($entry->locked || array_filter($children, fn (Attachment $child): bool => $this->locks($child->child)) !== []) {
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
      if ($keyword === 'properties' && is_array($value) && is_array($def['properties'] ?? NULL)) {
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
   * Describes a slot: a placeholder, and one conditional per variant.
   *
   * The slot's own property says only what holds whatever is chosen: its
   * label, that it is an object, and, under `x-surface`, which key
   * decides and which variant is chosen now. Each variant's shape is a
   * `then` on the parent, under an `if` naming the deciding key's value
   * as a `const`, so a validator, and the React app, read the shape the
   * chosen value selects. The `then` refers to the variant's def, beside
   * the slot's label; the chosen variant is described refined against
   * what the slot holds, which narrows its reference where that differs,
   * and the others as they are advertised.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $declared
   *   The data surface the slot belongs to.
   * @param \Drupal\data_surface\Entry $entry
   *   The slot's entry.
   * @param array $values
   *   The values of the slot's frame.
   * @param string $path
   *   The slot's dotted path.
   * @param string $owner
   *   The id of the resource or structure the slot belongs to.
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
   *   Whether the slot is part of a def: every variant is then a bare
   *   reference, and a locked deciding key chooses nothing.
   * @param bool $frozen
   *   Whether the slot is inside a child locked whole: every variant is
   *   then described locked.
   * @param bool $blank
   *   Whether the slot is inside a collection's items: no variant is
   *   then chosen, and every one is described from its locked keys.
   *
   * @return array{0: array, 1: mixed, 2: array}
   *   The slot's property schema, its value as shown, and the
   *   conditionals for the parent's `allOf`.
   */
  protected function slot(DataSurfaceInterface $declared, Entry $entry, array $values, string $path, string $owner, CacheableMetadata $cacheability, array &$stale, Subschemas $subschemas, array $standing = [], bool $declaring = FALSE, bool $frozen = FALSE, bool $blank = FALSE): array {
    $full = $subschemas->full;
    $slot = $entry->slot;
    assert($slot !== NULL);
    $by = $declared->getDefinitions()->get($slot->by);
    $deciding = $values[$slot->by] ?? ($declaring && $declared->isLocked($slot->by) ? NULL : $declared->getDefault($slot->by));
    $chosen = $slot->chosen($deciding);
    $held = $values[$entry->name] ?? NULL;
    $shell = $this->describe($slot->shell);
    $property = $shell + ['type' => $this->nullable('object', $slot->shell)];
    $depends = array_values(array_unique([$slot->by, ...$entry->dependencies]));
    if ($frozen) {
      $property['readOnly'] = TRUE;
    }
    $property[self::EXTENSION] = $this->extension('slot', $frozen, $depends, $chosen !== NULL) + [
      'by' => $slot->by,
      'variants' => $slot->variantIds(),
      'chosen' => $chosen,
    ];
    // A slot nothing has chosen keeps what it holds: it has no element,
    // so it says nothing about it.
    $shown = $held;
    $branches = [];
    foreach ($slot->variants as $id => $variant) {
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
        $own = $is_chosen && $slot->fits($id, $held) ? $held : [];
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
      $schema[self::EXTENSION] = $this->extension('fieldset', FALSE, [], $is_chosen && $this->anyRefined($instance)) + ['variant' => $id];
      $branches[] = [
        'if' => [
          'properties' => [$slot->by => ['const' => $by === NULL ? $id : $this->cast($id, $by)]],
          'required' => [$slot->by],
        ],
        'then' => [
          'properties' => [$entry->name => $schema],
        ],
      ];
    }
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
   * function of the combination, and the key carries `runtime` instead.
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
        $next[$i][1] = $declared->refine(array_replace($values, $combination), $kinds);
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
      $schema += ['type' => $this->nullable('array', $definition), 'items' => $items] + $this->keywords($definition, 'array');
      $extension = $this->extension($listed ? 'select' : 'list', $locked, $depends, $refined) + ($listed ? ['multiple' => TRUE] : []);
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
        'properties' => $properties,
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
      $extension = $this->extension($listed ? 'select' : $this->widget($definition), $locked, $depends, $refined);
      if ($listed) {
        $schema['oneOf'] = $this->choices($set, $definition);
        // Decision: see docs/decisions.md#the-empty-option-rule.
        $configured = ValueState::isConfigured($value);
        $chosen = $configured && $set->allows($value);
        $required = $definition->isRequired();
        $extension['emptyOption'] = [
          'show' => !($chosen && $required),
          'label' => (string) ($required ? $this->t('- Select -') : $this->t('- None -')),
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
    if (isset($schema[self::EXTENSION . '-checked'])) {
      $extension['checkedOnServer'] = $schema[self::EXTENSION . '-checked'];
      unset($schema[self::EXTENSION . '-checked']);
    }
    if (isset($schema[self::EXTENSION . '-pattern-message'])) {
      $extension['patternMessage'] = $schema[self::EXTENSION . '-pattern-message'];
      unset($schema[self::EXTENSION . '-pattern-message']);
    }
    $schema[self::EXTENSION] = $extension;
    return [$schema, $shown];
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
   * Reads a definition's title and description.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return array
   *   `title` and `description`, each only when the definition has one.
   */
  protected function describe(DataDefinitionInterface $definition): array {
    $described = [];
    $label = $definition->getLabel();
    if ($label !== NULL && (string) $label !== '') {
      $described['title'] = (string) $label;
    }
    $description = $definition->getDescription();
    if ($description !== NULL && (string) $description !== '') {
      $described['description'] = (string) $description;
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
      'email' => ['type' => 'string', 'format' => 'email'],
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
    return $definition->isRequired() && !DefinitionMetadata::isSecret($definition);
  }

  /**
   * Allows null beside a type, for an optional key a caller may clear.
   *
   * Null on an optional key clears it, so it is a value the key takes. A
   * required key never takes it, and neither does a secret, which null
   * leaves as it is and only its clear marker empties.
   *
   * Decision: see docs/decisions.md#absent-keeps-null-clears.
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
    if ($definition->isRequired() || DefinitionMetadata::isSecret($definition) || is_array($type)) {
      return $type;
    }
    return [$type, 'null'];
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
   *   and under `x-surface-checked` the names of the
   *   constraints no keyword states, which property() moves into
   *   `x-surface.checkedOnServer`.
   */
  protected function keywords(DataDefinitionInterface $definition, string|array|null $type): array {
    $keywords = [];
    $unstated = [];
    $types = (array) $type;
    $numeric = array_intersect($types, ['integer', 'number']) !== [];
    foreach ($definition->getConstraints() as $name => $options) {
      switch ($name) {
        case 'Range':
          if ($numeric && isset($options['min'])) {
            $keywords['minimum'] = $options['min'];
          }
          if ($numeric && isset($options['max'])) {
            $keywords['maximum'] = $options['max'];
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
          $keywords['format'] = 'email';
          break;

        case 'NotBlank':
          $keywords['minLength'] = max(1, $keywords['minLength'] ?? 1);
          break;

        case 'Regex':
          $pattern = $this->pattern($options);
          if ($pattern === NULL) {
            $unstated[] = (string) $name;
          }
          elseif (($options['match'] ?? TRUE) === FALSE) {
            $keywords['not'] = ['pattern' => $pattern];
          }
          else {
            $keywords['pattern'] = $pattern;
          }
          // A pattern is for machines; a person is told the message.
          if (is_string($options['message'] ?? NULL) && $options['message'] !== '') {
            $keywords[self::EXTENSION . '-pattern-message'] = $options['message'];
          }
          break;

        default:
          if (!in_array($name, self::STATED, TRUE)) {
            $unstated[] = (string) $name;
          }
      }
    }
    if ($unstated !== []) {
      $keywords[self::EXTENSION . '-checked'] = $unstated;
    }
    return $keywords;
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
      $choice = ['const' => $this->cast($value, $definition), 'title' => (string) $label];
      if (isset($set->descriptions[$value])) {
        $choice['description'] = (string) $set->descriptions[$value];
      }
      $choices[] = $choice;
    }
    if ($choices === [] || (!$definition->isRequired() && !DefinitionMetadata::isSecret($definition))) {
      // Null clears an optional key. A list of no values offers it alone,
      // which a required key's type refuses too: nothing is allowed.
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
   *   `emptyOption` and a slot's `chosen`.
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
   *   The schema, its `x-surface` keywords without `widget` and
   *   `multiple`.
   */
  protected function withoutWidgets(array $schema): array {
    foreach ($schema as $key => $value) {
      if (!is_array($value)) {
        continue;
      }
      if ($key === self::EXTENSION) {
        unset($value['widget'], $value['multiple']);
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
    unset($schema[self::EXTENSION], $schema[self::EXTENSION . '-checked'], $schema[self::EXTENSION . '-pattern-message']);
    foreach ($schema as $key => $value) {
      if (is_array($value)) {
        $schema[$key] = $this->withoutExtension($value);
      }
    }
    return $schema;
  }

}
