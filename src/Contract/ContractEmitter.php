<?php

declare(strict_types=1);

namespace Drupal\data_surface\Contract;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMap;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Form\DataSurfaceFormBuilderInterface;
use Drupal\data_surface\Options\DataSurfaceOptions;
use Drupal\data_surface\Options\OptionSet;
use Drupal\data_surface\Pipeline\ValueState;
use Drupal\data_surface\SurfaceAttachment;
use Drupal\data_surface\SurfaceEntry;

/**
 * Turns a sealed surface and its values into the served contract.
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
 * - per key, what the surface knows about it beyond its shape
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
 * Everything is read from the surface: the declared shape for what it
 * advertises, the same surface refined against the values for what it
 * allows now, each subsurface in its own frame. Nothing names a surface
 * or a key, and nothing here reaches storage: the values are handed in.
 *
 * Each subsurface is a reusable subschema. The wall between a parent and
 * its child (the parent cannot refine into the child, the child cannot
 * watch the parent, the child keeps its own refiners and alters) makes a
 * child self-contained, so it is written once under the root's `$defs`,
 * keyed by its surface's id, as its own frame declares it for no values
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
 *   all: every key is the one the surface declares, every dependency
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
 * renderer such as data_surface_react's app draws what the situation
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
   * Emits the contract a surface serves for a set of values.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface as built in its context, not refined: what it
   *   advertises.
   * @param array $values
   *   The values to describe it for, keyed by surface key: what the
   *   target holds over the defaults, with any in-progress answers over
   *   that. A key not given shows its default. A refinement overlay
   *   (DataSurfaceFormBuilderInterface::refinementOverlay()) is read the
   *   way buildSurfaceForm() reads it: the surface is refined against the
   *   keys as they stand, each orphan held unanswered, and each orphan
   *   named under STANDING_KEY is shown stale, standing for its stored
   *   value, which is not handed over.
   * @param string $surface_id
   *   The surface's `#[Surface]` id.
   * @param string|null $situation_id
   *   The situation id, or NULL for a surface asked for in no situation.
   * @param string|\Stringable|null $label
   *   The situation's label, which titles the schema.
   * @param bool $widgets
   *   Whether to add the renderer's widget hints, `x-surface.widget` and,
   *   on a list of allowed values, `x-surface.multiple`. Off by default:
   *   the contract itself names no widget, and a renderer that draws
   *   from it, as data_surface_react does, asks for them.
   *
   * @return \Drupal\data_surface\Contract\ServedContract
   *   The contract, and what it depends on.
   */
  public function emit(DataSurfaceInterface $surface, array $values, string $surface_id, ?string $situation_id = NULL, string|\Stringable|null $label = NULL, bool $widgets = FALSE): ServedContract {
    $cacheability = new CacheableMetadata();
    $stale = [];
    $standing = $values[DataSurfaceFormBuilderInterface::STANDING_KEY] ?? [];
    unset($values[DataSurfaceFormBuilderInterface::STANDING_KEY]);
    $subschemas = new Subschemas(FALSE, $widgets ? [] : self::WIDGET_HINTS);
    [$schema, $shown] = $this->frame($surface, $values, '', $surface_id, $cacheability, $stale, $subschemas, is_array($standing) ? $standing : []);
    $schema += $this->definitions($subschemas);
    if (!$widgets) {
      $schema = $this->withoutWidgets($schema);
    }
    $title = $label === NULL ? [] : ['title' => (string) $label];
    $document = [
      'surface' => $surface_id,
      'situation' => $situation_id,
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
   * Emits the full schema a surface states, for any values.
   *
   * The second document. It is handed no values and reads none, so it is
   * the same whatever is stored and whatever is being typed:
   *
   * - every key is described as the surface declares it, with its
   *   declared default; nothing is refined, except against the locked
   *   keys the situation itself knows, which no value can change;
   * - a key whose refiners watch siblings that each offer a list of
   *   values is `enumerated`: one conditional per combination of those
   *   values on the parent, always, because no sibling holds anything
   *   here to be held at;
   * - a key whose answers cannot be listed keeps its declared schema and
   *   is `dynamic`: wider than what the pipeline will allow for some
   *   values, never narrower, and to be asked about with values;
   * - a slot is its conditionals, and every subsurface frame is described
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
   *   The surface as built in its context, not refined.
   * @param string $surface_id
   *   The surface's `#[Surface]` id.
   * @param string|null $situation_id
   *   The situation id, or NULL for a surface asked for in no situation.
   * @param string|\Stringable|null $label
   *   The situation's label, which titles the schema.
   * @param bool $widgets
   *   Whether to add the renderer's widget hints, as for emit().
   *
   * @return \Drupal\data_surface\Contract\ServedContract
   *   The document (`surface`, `situation`, `label`, `schema` and, when
   *   the surface declares outputs, `outputs`), and what it depends on:
   *   the surface and every option list read, never a stored value.
   */
  public function emitFull(DataSurfaceInterface $surface, string $surface_id, ?string $situation_id = NULL, string|\Stringable|null $label = NULL, bool $widgets = FALSE): ServedContract {
    $cacheability = new CacheableMetadata();
    $ignored = [];
    $subschemas = new Subschemas(TRUE, [...self::PRESENT, ...($widgets ? [] : self::WIDGET_HINTS)]);
    [$schema] = $this->frame($surface, $this->known($surface), '', $surface_id, $cacheability, $ignored, $subschemas);
    $schema += $this->definitions($subschemas);
    $schema = $this->withoutPresent($schema);
    if (!$widgets) {
      $schema = $this->withoutWidgets($schema);
    }
    $title = $label === NULL ? [] : ['title' => (string) $label];
    $document = [
      'surface' => $surface_id,
      'situation' => $situation_id,
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
   * Reads the values a situation knows and nothing can change.
   *
   * The only values the full schema is described for: a locked key holds
   * its identity whatever is stored or typed, so a key that watches only
   * locked siblings has one answer.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   A surface frame, as advertised.
   *
   * @return array
   *   The value of each locked key, by key.
   */
  protected function known(DataSurfaceInterface $surface): array {
    $known = [];
    foreach ($surface->getDefinitions()->entries() as $name => $entry) {
      if ($entry->locked) {
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
   * @param \Drupal\data_surface\SurfaceEntry $entry
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
  protected function watchesFree(DefinitionMap $advertised, SurfaceEntry $entry, bool $declaring = FALSE): bool {
    foreach ($entry->staticDependencies() as $key) {
      if ($declaring || !$advertised->isLocked($key)) {
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
   * Describes one surface frame: the top level, or one subsurface.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $declared
   *   The surface of this frame, as advertised.
   * @param array $values
   *   The values of this frame.
   * @param string $prefix
   *   The dotted path of this frame from the top, with its trailing dot.
   * @param string $owner
   *   The id of this frame's surface: the document's surface at the top,
   *   a child's own, or the key a child with no class is defined under.
   *   A child with no class of its own is defined as `<owner>.<key>`, a
   *   variant with none as `<owner>.<key>.<value>`.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the description depends on.
   * @param string[] $stale
   *   Collects the dotted paths of stale values.
   * @param \Drupal\data_surface\Contract\Subschemas $subschemas
   *   The document's defs, which every subsurface is written to, and
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
   *   Whether this frame is a def: the surface as declared, for no
   *   values, with nothing locked. A locked key is then described as the
   *   key it is before a context fixes it (no `readOnly`, no `const`, and
   *   no default, which is the context's), and every subsurface inside is
   *   a bare reference to its own def.
   *
   * @return array{0: array, 1: array|\stdClass}
   *   The object schema of the frame, and its values as shown.
   */
  protected function frame(DataSurfaceInterface $declared, array $values, string $prefix, string $owner, CacheableMetadata $cacheability, array &$stale, Subschemas $subschemas, array $standing = [], bool $declaring = FALSE): array {
    $full = $subschemas->full;
    // Decision: see docs/decisions.md#static-by-default-runtime-by-declaration.
    // The present moment is refined by every refiner; the full schema and
    // a def, which are for any values, by the static ones alone.
    $runtime = !$full && !$declaring;
    $refined = $declared->refine($values, $runtime);
    $cacheability->addCacheableDependency($refined);
    $advertised = $declared->getDefinitions();
    $now = $refined->getDefinitions();
    // What the static refiners alone answer for these values, which is
    // all a conditional may state: a runtime refiner is never run ahead.
    $static = $runtime && $this->refinedAtRuntime($advertised) ? $declared->refine($values, FALSE) : $refined;
    $ahead = $static->getDefinitions();
    $properties = [];
    $required = [];
    $conditions = [];
    $shown = [];
    foreach ($advertised->entries() as $name => $entry) {
      $name = (string) $name;
      $current = $now->entry($name) ?? $entry;
      $stated = $ahead->entry($name) ?? $entry;
      // A def is the declaration, which no context has locked.
      $locked = $entry->locked && !$declaring;
      if ($full && !$entry->isNested() && $this->watchesFree($advertised, $entry, $declaring)) {
        // A refiner that watches only a key an alter mounted is run even
        // while that key holds nothing. For any values, the key is the
        // one declared: what such a refiner answers is one moment's.
        $current = $entry;
        $stated = $entry;
      }
      $depends = $advertised->dependencies($name);
      if ($entry->slot !== NULL) {
        [$properties[$name], $shown[$name], $branches] = $this->slot($declared, $entry, $values, $prefix . $name, $owner, $cacheability, $stale, $subschemas, $standing, $declaring);
        array_push($conditions, ...$branches);
      }
      elseif ($entry->attachment !== NULL) {
        $attachment = $entry->attachment;
        $fallback = $owner . '.' . $name;
        $key = $this->define($attachment, $fallback, $cacheability, $subschemas);
        if ($declaring) {
          $instance = $subschemas->get($key) ?? [];
          $reference = $this->reference($key);
          $shown[$name] = new \stdClass();
        }
        else {
          $child = $attachment->child;
          $held = $values[$name] ?? NULL;
          $inside = $full ? $this->known($child) : array_replace($child->getDefaultValues(), is_array($held) ? $held : []);
          [$instance, $shown[$name]] = $this->instance($child, $key, $inside, $prefix . $name . '.', $attachment->id ?? $fallback, $cacheability, $stale, $subschemas, $standing);
          $reference = $this->reference($key, $instance, $subschemas);
        }
        $properties[$name] = $this->describe($current->definition) + $reference;
        $properties[$name][self::EXTENSION] = $this->extension('fieldset', $locked, $depends, $this->anyRefined($instance));
      }
      else {
        // The value each element would be built with: a secret's is never
        // handed over, a locked key holds what the situation knows (and a
        // def has no situation), an orphan the stored value it stands
        // for, and anything else what it was given or failing that its
        // default.
        $secret = DefinitionMetadata::isSecret($current->definition);
        $orphan = !$secret && !$entry->locked && array_key_exists($prefix . $name, $standing);
        $value = match (TRUE) {
          $secret, $declaring && $entry->locked => NULL,
          $entry->locked => $declared->getDefault($name),
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
        if ($entry->staticDependencies() !== [] && !$locked && $stated === $entry) {
          // Stated over the key as declared: a runtime refiner may have
          // narrowed it for the present moment already, and a conditional
          // says what the static ones add to the declaration.
          $ignored = [];
          $base = $current === $entry ? $properties[$name] : $this->property($entry->definition, NULL, NULL, $prefix . $name, FALSE, [], $cacheability, $ignored)[0];
          $branches = $this->enumerate($declared, $static, $entry, $values, $base, $prefix . $name, $cacheability);
          if ($branches === NULL) {
            $properties[$name][self::EXTENSION]['dynamic'] = TRUE;
          }
          else {
            $properties[$name][self::EXTENSION]['enumerated'] = TRUE;
            array_push($conditions, ...$branches);
          }
        }
        if ($entry->isRefinedAtRuntime()) {
          // Declared impossible to capture: never in a conditional, never
          // in the full schema, and only in this one for its values.
          $properties[$name][self::EXTENSION]['runtime'] = TRUE;
        }
      }
      if ($current->definition->isRequired()) {
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
    return [$schema, $shown === [] ? new \stdClass() : $shown];
  }

  /**
   * Writes a child's def, once, and answers the key it is filed under.
   *
   * The def is the child's frame as declared: described for no values,
   * with nothing locked, every subsurface inside it a reference of its
   * own. A child already written, here or at another key, is not written
   * again; nor is one reached inside its own def, which is referred to,
   * the def being written, so a surface that ever contained itself would
   * end there instead of looping. (The build step refuses a surface
   * attached inside itself, and a sealed surface cannot hold itself, so
   * that is a guard, not a case.)
   *
   * @param \Drupal\data_surface\SurfaceAttachment $attachment
   *   The child at its key.
   * @param string $fallback
   *   The id to define it under when it has no #[Surface] id of its own.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the description depends on.
   * @param \Drupal\data_surface\Contract\Subschemas $subschemas
   *   The document's defs.
   *
   * @return string
   *   The key under `$defs`.
   */
  protected function define(SurfaceAttachment $attachment, string $fallback, CacheableMetadata $cacheability, Subschemas $subschemas): string {
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
   *
   * @return array{0: array, 1: array|\stdClass}
   *   The child's object schema here, and its values as shown.
   */
  protected function instance(DataSurfaceInterface $child, string $key, array $values, string $prefix, string $owner, CacheableMetadata $cacheability, array &$stale, Subschemas $subschemas, array $standing): array {
    $def = $subschemas->get($key);
    if ($subschemas->full && $def !== NULL && !$this->locks($child)) {
      return [$def, new \stdClass()];
    }
    return $this->frame($child, $values, $prefix, $owner, $cacheability, $stale, $subschemas, $standing);
  }

  /**
   * Answers whether a context locked anything in a surface, at any depth.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface.
   *
   * @return bool
   *   TRUE when a key of it, or of a subsurface inside it, is locked.
   */
  protected function locks(DataSurfaceInterface $surface): bool {
    foreach ($surface->getDefinitions()->entries() as $entry) {
      $children = match (TRUE) {
        $entry->slot !== NULL => $entry->slot->variants,
        $entry->attachment !== NULL => [$entry->attachment],
        default => [],
      };
      if ($entry->locked || array_filter($children, fn (SurfaceAttachment $child): bool => $this->locks($child->child)) !== []) {
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
    $def = $subschemas?->get($key);
    if ($instance === NULL || $def === NULL) {
      return $reference;
    }
    $narrowing = $this->narrowing($instance, $def, $subschemas->transient);
    return $narrowing === [] ? $reference : ['allOf' => [$reference, $narrowing]];
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
        foreach ($value as $name => $property) {
          $changed = $this->narrowing($property, $def['properties'][$name] ?? [], $transient);
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
   *   The surface the slot belongs to.
   * @param \Drupal\data_surface\SurfaceEntry $entry
   *   The slot's entry.
   * @param array $values
   *   The values of the slot's frame.
   * @param string $path
   *   The slot's dotted path.
   * @param string $owner
   *   The id of the surface the slot belongs to.
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
   *
   * @return array{0: array, 1: mixed, 2: array}
   *   The slot's property schema, its value as shown, and the
   *   conditionals for the parent's `allOf`.
   */
  protected function slot(DataSurfaceInterface $declared, SurfaceEntry $entry, array $values, string $path, string $owner, CacheableMetadata $cacheability, array &$stale, Subschemas $subschemas, array $standing = [], bool $declaring = FALSE): array {
    $full = $subschemas->full;
    $slot = $entry->slot;
    assert($slot !== NULL);
    $by = $declared->getDefinitions()->get($slot->by);
    $deciding = $values[$slot->by] ?? ($declaring && $declared->isLocked($slot->by) ? NULL : $declared->getDefault($slot->by));
    $chosen = $slot->chosen($deciding);
    $held = $values[$entry->name] ?? NULL;
    $shell = $this->describe($slot->shell);
    $property = $shell + ['type' => $slot->shell->isRequired() ? 'object' : ['object', 'null']];
    $depends = array_values(array_unique([$slot->by, ...$entry->dependencies]));
    $property[self::EXTENSION] = $this->extension('slot', FALSE, $depends, $chosen !== NULL) + [
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
      $is_chosen = !$full && !$declaring && $id === $chosen;
      if ($declaring) {
        $instance = $subschemas->get($key) ?? [];
        $reference = $this->reference($key);
      }
      else {
        $own = $is_chosen && $slot->fits($id, $held) ? $held : [];
        $variant_stale = [];
        $inside = $full ? $this->known($child) : array_replace($child->getDefaultValues(), $own);
        [$instance, $variant_values] = $this->instance($child, $key, $inside, $path . '.', $variant->id ?? $fallback, $cacheability, $variant_stale, $subschemas, $is_chosen ? $standing : []);
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
   * Each combination is answered by refining the whole surface against
   * the values with the combination over them, the call /refine makes
   * with the runtime refiners left out, so a conditional states exactly
   * what /refine would answer less what only the runtime knows. A
   * runtime refiner is never called here: what it answers is not a
   * function of the combination, and the key carries `runtime` instead.
   * Decision: see
   * docs/decisions.md#static-by-default-runtime-by-declaration.
   * Siblings are taken in declaration order, and each one's list is read
   * from the surface refined for the combination so far, so a chain
   * composes: a bundle's list is read under each entity type.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $declared
   *   The surface of this frame, as advertised.
   * @param \Drupal\data_surface\DataSurfaceInterface $refined
   *   The same surface refined against the frame's values by its static
   *   refiners alone.
   * @param \Drupal\data_surface\SurfaceEntry $entry
   *   The key, unrefined.
   * @param array $values
   *   The values of the frame.
   * @param array $base
   *   The key's schema as emitted, unrefined.
   * @param string $path
   *   The key's dotted path.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what every list read and every refinement depends on.
   *
   * @return array|null
   *   The conditionals, none for a combination that changes nothing; NULL
   *   when the key cannot be enumerated: it watches a key an alter
   *   mounted, a list, a subsurface or a key with no list of values, or
   *   it would take more than ENUMERATION_CAP combinations.
   */
  protected function enumerate(DataSurfaceInterface $declared, DataSurfaceInterface $refined, SurfaceEntry $entry, array $values, array $base, string $path, CacheableMetadata $cacheability): ?array {
    $order = array_flip($declared->getDefinitions()->names());
    $watched = $entry->staticDependencies();
    foreach ($watched as $key) {
      if (!isset($order[$key])) {
        // A key an alter mounted, by its dotted path: a free value, or one
        // a conditional on the parent cannot name without restating the
        // mount around it.
        return NULL;
      }
    }
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
        $next[$i][1] = $declared->refine(array_replace($values, $combination), FALSE);
        $cacheability->addCacheableDependency($next[$i][1]);
      }
      $combinations = $next;
    }

    $branches = [];
    $ignored = [];
    foreach ($combinations as [$combination, $surface]) {
      $definition = $surface->getDefinition($entry->name) ?? $entry->definition;
      [$schema] = $this->property($definition, NULL, NULL, $path, FALSE, [], $cacheability, $ignored);
      $narrowed = $this->narrowed($schema, $base);
      if ($narrowed === []) {
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
        'then' => [
          'properties' => [$entry->name => $narrowed],
        ],
      ];
    }
    return $branches;
  }

  /**
   * Lists the values a watched sibling can take, to enumerate over.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface, refined for the combination so far.
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
   *   value, a list of values, a subsurface, or more values than
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
        foreach ($value as $name => $property) {
          $changed = $this->narrowed($property, $base['properties'][$name] ?? []);
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
   * Describes one key that is not a subsurface, recursing through maps.
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
    $listed = $set !== NULL && $set->options !== [];
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
        [$properties[$name], $nested[$name]] = $this->property($property, $before[$name] ?? NULL, is_array($value) ? ($value[$name] ?? NULL) : NULL, $path . '.' . $name, FALSE, [], $cacheability, $stale);
        if ($property->isRequired()) {
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
      $shown = $nested;
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
   * Describes the outputs a surface declares, if it declares any.
   *
   * Outputs are never refined, never rendered and never locked, so they
   * carry no `x-surface`.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the description depends on.
   *
   * @return array|null
   *   The output schema, or NULL for a surface with no outputs.
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
   * Allows null beside a type, for a key that need not hold a value.
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
    return $definition->isRequired() || is_array($type) ? $type : [$type, 'null'];
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
   *   The pattern without its delimiters, or NULL when the expression
   *   carries a modifier JSON Schema has no way to say.
   */
  protected function pattern(array $options): ?string {
    $regex = $options['pattern'] ?? $options['value'] ?? NULL;
    if (!is_string($regex) || strlen($regex) < 2) {
      return NULL;
    }
    $end = strrpos($regex, $regex[0]);
    if ($end === FALSE || $end === 0) {
      return NULL;
    }
    // The u modifier only says the subject is UTF-8, which an ECMA
    // pattern assumes; any other modifier changes what matches.
    return trim(substr($regex, $end + 1), 'u') === '' ? substr($regex, 1, $end - 1) : NULL;
  }

  /**
   * Spells an option set as `oneOf` entries, labels and help included.
   *
   * A key that need not hold a value allows null besides, as its own
   * entry with no title: the empty option is a presentation, said under
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
    if (!$definition->isRequired()) {
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
      if (!empty($property[self::EXTENSION]['refined'])) {
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
