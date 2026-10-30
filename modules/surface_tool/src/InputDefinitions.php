<?php

declare(strict_types=1);

namespace Drupal\surface_tool;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\surface\Attachment;
use Drupal\surface\Contract\Choices;
use Drupal\surface\Contract\PlainText;
use Drupal\surface\Contract\SiteListBudget;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Pipeline\Outbound;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Options\SurfaceOptions;
use Drupal\surface\Pipeline\SurfacePipeline;
use Drupal\surface\Pipeline\ViolationMessage;
use Drupal\surface\Reference\EntityReferenceStructure;
use Drupal\surface\Reference\References;
use Drupal\surface\Entry;
use Drupal\surface\Variants;
use Drupal\tool\TypedData\InputDefinition;
use Drupal\tool\TypedData\InputDefinitionInterface;
use Drupal\tool\TypedData\ListInputDefinition;
use Drupal\tool\TypedData\ListOutputDefinition;
use Drupal\tool\TypedData\MapInputDefinition;
use Drupal\tool\TypedData\MapOutputDefinition;
use Drupal\tool\TypedData\OutputDefinition;
use Drupal\tool\TypedData\OutputDefinitionInterface;

/**
 * Turns a data surface into the Tool API's definitions.
 *
 * The whole bridge. A data surface is an ordered set of
 * core data definitions; a tool input is a context definition. The two
 * describe the same thing in two vocabularies, so the conversion is a
 * rename plus the three places where the Tool API keeps its own class:
 * a map becomes a map input definition with property definitions, a list
 * becomes a list input definition with an item definition, and anything
 * else becomes a plain input definition carrying the data type.
 *
 * Nothing about any particular host, field type or setting appears here.
 * What a data surface says about a value (its type, its label, its help
 * text, whether it is required, what it starts from, and which values it
 * allows) is what the converted definition says, and the Tool API's own
 * normalizer turns that into the JSON Schema an invoker advertises.
 *
 * A key the data surface declared secret converts with no default value and
 * with a write-only note appended to its description; secretNote() says
 * why the note is prose rather than the JSON Schema keyword it will
 * become. A read leaves it out altogether: secrets() finds every one,
 * at any depth, and both the read's output definition and the values it
 * answers with are cut by that one answer.
 *
 * Two things a data surface knows survive the conversion only in part, and
 * both are Tool API gaps rather than choices made here:
 * - Example values. DefinitionMetadata carries them on a definition and
 *   JSON Schema has the keyword, but a context definition has nowhere to
 *   put them, so they are dropped.
 * - Type settings. Core's data definitions carry a settings array; a
 *   context definition does not, and rebuilds its data definition from
 *   the data type alone, so settings are dropped too.
 *
 * The data surface's outputs convert the same way, into the output
 * definitions the Tool API declares outputs with; outputsFromSurface()
 * says what that direction loses on top of these two.
 *
 * Children convert by what they are. An attached child, and variants
 * whose deciding key already chose (or is locked), are a map like any
 * other: a nested map input whose properties are the child's keys. A
 * collection is a list input of those maps, its Count the list's
 * constraint. Variants nothing has chosen yet are the one shape the
 * Tool API cannot say, and fromVariants() says why and what is emitted
 * instead; variants the site lists are left out (deferred()).
 *
 * One Tool API gap is worth naming here rather than in a method
 * docblock, because it is why a derived tool declares its outputs as
 * the deriver writes them: outputs are declared statically on the
 * #[Tool] attribute, and there is no output_definition_refiners beside
 * input_definition_refiners, so a tool cannot say "this output is
 * whatever the subject turns out to describe" the way it can for an
 * input. A tool that wants it has to override getOutputDefinitions()
 * itself, which no declaration makes visible.
 *
 * @see \Drupal\surface\SurfaceInterface
 * @see \Drupal\tool\Normalizer\ContextDefinitionNormalizer
 */
final class InputDefinitions {

  /**
   * The key of a secrets tree that holds what is secret inside each item.
   */
  public const EACH = Outbound::EACH;

  use StringTranslationTrait;

  /**
   * The constraint the Tool API's normalizer reads an enum from.
   */
  protected const CHOICE = 'Choice';

  /**
   * The tags of the option lists read since they were last taken.
   *
   * @var string[]
   */
  protected array $listTags = [];

  /**
   * The path the full schema is fetched at, for the surface being converted.
   *
   * NULL when the site serves no API, or outside fromSurface().
   */
  protected ?string $schemaPath = NULL;

  /**
   * Where the key being converted is.
   *
   * Its frame's JSON Pointer, the choices of the variants on the way to
   * it, each a pointer and a value, and what is stored in the frame, which
   * chooses the variant a key the site lists states; NULL when nothing
   * stored is known.
   *
   * @var array{pointer: string, steps: list<array{0: string, 1: string}>, values: array|null}
   */
  protected array $at = ['pointer' => '', 'steps' => [], 'values' => NULL];

  /**
   * Constructs a InputDefinitions converter.
   *
   * @param \Drupal\surface\Options\SurfaceOptions $options
   *   The options service, the one place a constraint is read as a list
   *   of allowed values.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service, which the notes and fallback
   *   labels this converter writes are built through.
   * @param \Drupal\surface\Reference\References|null $references
   *   What reference keys are: the limit up to which one lists its
   *   candidates.
   * @param \Drupal\surface\Contract\SiteListBudget|null $budget
   *   How many entries of a list the site decides a listing spells out;
   *   without it, every one.
   */
  public function __construct(
    protected readonly SurfaceOptions $options,
    TranslationInterface $string_translation,
    protected readonly ?References $references = NULL,
    protected readonly ?SiteListBudget $budget = NULL,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Takes the tags of the option lists read since they were last taken.
   *
   * A value list read from the site, as the venues example 2 chooses from,
   * is copied into a tool's definition as its enum, so the definition
   * holds until a tag the list was read under is invalidated.
   *
   * @return string[]
   *   The tags, which are then forgotten here.
   */
  public function takeListTags(): array {
    $tags = $this->listTags;
    $this->listTags = [];
    return $tags;
  }

  /**
   * Converts a whole data surface into one map input definition.
   *
   * The data surface's keys become the map's properties, in the order
   * it declares them, so a caller reading the advertised schema reads
   * the data surface.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface to convert.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $label
   *   The label of the resulting map input.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $description
   *   The description of the resulting map input.
   * @param bool $required
   *   Whether the resulting map input is required.
   * @param mixed $default_value
   *   The default for the map as a whole. Deliberately not assembled
   *   from the data surface's own defaults: a map default is handed back
   *   whenever the caller sends nothing, and on an update path a full
   *   set of defaults arriving as if the caller had typed it would
   *   overwrite every stored value. The data surface's defaults live on the
   *   converted properties, which is where they describe rather than
   *   act.
   * @param bool $runtimeAccessSaidOnce
   *   TRUE when the tool's description says that every key may be found
   *   locked or left out, so a key whose only mark is the one at the top
   *   does not say it again.
   * @param string|null $narrowing
   *   The id of the tool that answers the schema for some values, which a
   *   key whose list the site decides names past the budget; NULL when
   *   there is none.
   * @param string|null $schema
   *   The path the operation's full schema is fetched at, which such a key
   *   names too, with the choice that states one of its entries; NULL when
   *   the site serves no API.
   * @param array|null $stored
   *   What storage holds for the data surface, whose deciding keys choose
   *   the variant a key the site lists states (deferred()); NULL when
   *   nothing stored is read, and none is stated.
   *
   * @return \Drupal\tool\TypedData\MapInputDefinition
   *   The map input definition.
   */
  public function fromSurface(SurfaceInterface $surface, TranslatableMarkup|string $label, TranslatableMarkup|string $description, bool $required = FALSE, mixed $default_value = NULL, bool $runtimeAccessSaidOnce = FALSE, ?string $narrowing = NULL, ?string $schema = NULL, ?array $stored = NULL): MapInputDefinition {
    $was = [$this->schemaPath, $this->at];
    $this->schemaPath = $schema;
    $this->at = ['pointer' => '', 'steps' => [], 'values' => $stored];
    try {
      return $this->surfaceMap($surface, $label, $description, $required, $default_value, $runtimeAccessSaidOnce, $narrowing);
    }
    finally {
      [$this->schemaPath, $this->at] = $was;
    }
  }

  /**
   * Converts a data surface into one map input, for fromSurface().
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface to convert.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $label
   *   The label of the resulting map input.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $description
   *   The description of the resulting map input.
   * @param bool $required
   *   Whether the resulting map input is required.
   * @param mixed $default_value
   *   The default for the map as a whole.
   * @param bool $runtimeAccessSaidOnce
   *   As fromSurface() takes it.
   * @param string|null $narrowing
   *   The narrowing tool's id, or NULL.
   *
   * @return \Drupal\tool\TypedData\MapInputDefinition
   *   The map input definition.
   */
  protected function surfaceMap(SurfaceInterface $surface, TranslatableMarkup|string $label, TranslatableMarkup|string $description, bool $required, mixed $default_value, bool $runtimeAccessSaidOnce, ?string $narrowing): MapInputDefinition {
    // Said once by the tool's description, a mark every key carries from
    // the top is not said on each key again; a key's own mark still is.
    // Decision: see
    // docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability.
    $once = $runtimeAccessSaidOnce && $surface->isRuntimeAccessWhole();
    $properties = [];
    $definitions = $surface->getDefinitions();
    foreach ($definitions->entries() as $name => $entry) {
      $property = $this->fromEntry($entry, $narrowing);
      // A key a refiner may rule out is required only where it applies,
      // which a listing cannot say: optional here, and the pipeline asks
      // for it where it applies.
      // Decision: see docs/decisions.md#a-key-that-does-not-apply.
      static::optionalWhereRuledOut($property, $entry->mayNotApply());
      if ($definitions->isLocked($name)) {
        // A locked key has exactly one legal value, and the Tool
        // API has the same idea for a whole input. It has no effect on a
        // map's property today, because only top level inputs are
        // filtered by it; carrying it anyway keeps the statement in the
        // definition for the day the normalizer reads it. A child its
        // access locked is a locked map, and every key inside it is
        // locked with it.
        static::lock($property);
      }
      if ($entry->runtimeAccess && !($once && !$surface->isRuntimeAccessOwn((string) $name))) {
        $this->runtimeAccessNote($property);
      }
      $properties[$name] = $property;
    }
    return new MapInputDefinition(
      label: $label,
      description: $description,
      required: $required,
      default_value: $default_value,
      property_definitions: $properties,
    );
  }

  /**
   * Says on each reference key with too many to list where to find an id.
   *
   * A key that refers to one entity type and whose candidates are not
   * listed as its enum, there being more than the site's limit, and the id
   * of a bare `entity` key, which the listing cannot list per entity type,
   * each say in their description that a caller sends an id, and where it
   * looks one up: the resource's lookup, when the site serves its API.
   *
   * Decision: see
   * docs/decisions.md#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up.
   *
   * @param \Drupal\tool\TypedData\MapInputDefinition $map
   *   The converted data surface, or a converted child; changed in place.
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface it was converted from.
   * @param string|null $lookup
   *   The resource's lookup, `/surface-api/{resource}:lookup`, or NULL
   *   when the site serves no API.
   * @param string $pointer
   *   The JSON Pointer of the map in the values.
   *
   * @return \Drupal\tool\TypedData\MapInputDefinition
   *   The same map.
   */
  public function withLookups(MapInputDefinition $map, SurfaceInterface $surface, ?string $lookup, string $pointer = ''): MapInputDefinition {
    if ($this->references === NULL) {
      return $map;
    }
    $properties = $map->getPropertyDefinitions();
    foreach ($surface->getDefinitions()->entries() as $name => $entry) {
      $property = $properties[$name] ?? NULL;
      $at = $pointer . '/' . str_replace(['~', '/'], ['~0', '~1'], (string) $name);
      $child = $entry->attachment?->child;
      if ($property instanceof MapInputDefinition && $child !== NULL) {
        $this->withLookups($property, $child, $lookup, $at);
        continue;
      }
      if (!$property instanceof DataDefinition) {
        continue;
      }
      $target = References::target($entry->definition);
      $bare = $entry->definition->getSetting(EntityReferenceStructure::TARGETS) !== NULL;
      if (($target === NULL || $this->options->resolve($entry->definition) !== NULL) && !$bare) {
        continue;
      }
      // Whether a key lists its candidates depends on the limit, so the
      // listing is derived again when it changes.
      $this->listTags = Cache::mergeTags($this->listTags, $this->references->limitCacheability()->getCacheTags());
      $arguments = [
        '@limit' => $this->references->limit(),
        '@lookup' => (string) $lookup,
        '@key' => $at,
      ];
      $note = match (TRUE) {
        $bare && $lookup !== NULL => $this->t('Send the id of an entity of the entity type chosen; GET @lookup?key=@key&type={entity type}&q={text} looks one up by its label.', $arguments),
        $bare => $this->t('Send the id of an entity of the entity type chosen.', $arguments),
        $lookup !== NULL => $this->t('Too many to list (more than @limit): send the id of one; GET @lookup?key=@key&q={text} looks one up by its label.', $arguments),
        default => $this->t('Too many to list (more than @limit): send the id of one.', $arguments),
      };
      $this->noted($property, $note);
    }
    return $map;
  }

  /**
   * Names in each dependent key's description what it depends on.
   *
   * A listing states every key as declared, so a key another narrows
   * says so in words, once, in its own description: "Depends on venue."
   * Each key it depends on is named by its path in the values map, the
   * name a caller sends it under; a port is named by the key of its
   * container it reads, at the site; a stored value is never named. What
   * chooses a variant is said by the variants' own description, and a
   * key that holds a child names nothing its ports read, which the keys
   * inside it name. Inside a collection's item a key names its siblings
   * by their keys in the item.
   *
   * Decision: see docs/decisions.md#a-refusal-names-what-it-depends-on.
   *
   * @param \Drupal\tool\TypedData\MapInputDefinition $map
   *   The converted data surface; changed in place.
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface it was converted from.
   *
   * @return \Drupal\tool\TypedData\MapInputDefinition
   *   The same map.
   */
  public function withDependencies(MapInputDefinition $map, SurfaceInterface $surface): MapInputDefinition {
    foreach (static::dependencies($surface, shown: $map) as $path => $keys) {
      $target = $map;
      foreach (explode('.', $path) as $property) {
        $target = match (TRUE) {
          $target instanceof ListInputDefinition && $property === self::EACH => $target->getItemDefinition(),
          $target instanceof MapInputDefinition => $target->getPropertyDefinition($property),
          default => NULL,
        };
      }
      if (!$target instanceof DataDefinition) {
        continue;
      }
      $note = $this->t('Depends on @keys.', ['@keys' => implode(', ', $keys)]);
      $target->setDescription($this->appended($this->description($target), $note));
    }
    return $map;
  }

  /**
   * Lists what each key of a data surface depends on, at any depth.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface, or a child of it.
   * @param string $prefix
   *   The path of the frame in the values map, with its trailing dot.
   * @param array<string, string> $ports
   *   The frame's ports, each mapped to the path in the values map of the
   *   key it reads.
   * @param \Drupal\tool\TypedData\MapInputDefinition|null $shown
   *   The frame as the listing shows it: a child it shows no map for, as
   *   variants inside variants, which it holds as a value of any type, is
   *   not walked, since nothing inside it is listed. NULL to walk all.
   *
   * @return array<string, string[]>
   *   The paths each key depends on, keyed by its own path; a key that
   *   depends on nothing is not there.
   */
  protected static function dependencies(SurfaceInterface $surface, string $prefix = '', array $ports = [], ?MapInputDefinition $shown = NULL): array {
    $found = [];
    foreach ($surface->getDefinitions()->entries() as $name => $entry) {
      $at = $prefix . $name;
      $shown_at = $shown?->getPropertyDefinition((string) $name);
      // A map the listing states with no key (one it leaves out) has
      // nothing below it to name, so its children are not built to be
      // asked.
      $walked = $shown === NULL || ($shown_at instanceof MapInputDefinition && $shown_at->getPropertyDefinitions() !== []);
      $resolve = static fn (string $key): string => $ports[$key] ?? $prefix . $key;
      $children = [];
      if ($entry->attachment !== NULL) {
        $children[] = [$entry->attachment, $at . '.'];
      }
      foreach ($entry->mounted as $path => $attachment) {
        $children[] = [$attachment, $at . '.' . $path . '.'];
      }
      foreach ($entry->variants->variants ?? [] as $variant) {
        $children[] = [$variant, $at . '.'];
      }
      // What chooses a variant, and what a child's ports read, are said
      // elsewhere: by the variants' own description, and by the keys that
      // read them.
      $read = array_merge($entry->variants === NULL ? [] : [$entry->variants->by], ...array_map(static fn (array $child): array => array_values($child[0]->reads), $children));
      if ($entry->paths !== []) {
        foreach ($entry->paths as $path => $keys) {
          $found[$at . '.' . $path] = array_map($resolve, $keys);
        }
      }
      else {
        $own = array_values(array_diff($entry->dependencies, $read));
        if ($own !== []) {
          $found[$at] = array_map($resolve, $own);
        }
      }
      foreach ($walked ? $children : [] as [$attachment, $below]) {
        // Of variants the site lists, the listing states the one it read,
        // and no other is built to be asked.
        if (static::listedVariants($entry) && !$attachment->isSettled()) {
          continue;
        }
        $found += static::dependencies($attachment->child, $below, array_map($resolve, $attachment->reads), $shown_at instanceof MapInputDefinition ? $shown_at : NULL);
      }
      // Inside a collection's item, a key names its siblings by their
      // keys in the item, the frame a caller sends them in together.
      $item = $shown_at instanceof ListInputDefinition ? $shown_at->getItemDefinition() : NULL;
      if ($entry->list !== NULL && ($shown === NULL || $item instanceof MapInputDefinition)) {
        foreach (static::dependencies($entry->list->item->child, shown: $item instanceof MapInputDefinition ? $item : NULL) as $path => $keys) {
          $found[$at . '.' . self::EACH . '.' . $path] = $keys;
        }
      }
    }
    return $found;
  }

  /**
   * Copies an input with its shape alone: no constraint at any depth.
   *
   * What the Tool API holds a tool's `values` to before the tool runs:
   * its data types, which keys are required, its nesting. Every rule is
   * the pipeline's to judge, against the values narrowed by the values
   * sent, so a refusal says what is allowed for them; the listing keeps
   * the constraints it advertises.
   *
   * On an operation that changes what exists, the shape requires nothing
   * either, as its listing and its refined input say: a key left out
   * keeps what is stored, which the pipeline judges.
   *
   * Decision: see docs/decisions.md#a-refusal-names-what-it-depends-on.
   *
   * @param \Drupal\tool\TypedData\InputDefinitionInterface $input
   *   The input, as listed or refined.
   * @param bool $requiresNothing
   *   Whether no key is required, at any depth but a list's items.
   *
   * @return \Drupal\tool\TypedData\InputDefinitionInterface
   *   A copy of it, holding no constraint.
   */
  public static function shapeOf(InputDefinitionInterface $input, bool $requiresNothing = FALSE): InputDefinitionInterface {
    $shape = clone $input;
    static::withoutConstraints($shape, $requiresNothing);
    return $shape;
  }

  /**
   * Takes out each value sent for a scalar key that holds no scalar.
   *
   * Core's PrimitiveTypeConstraintValidator assumes a scalar for some
   * types: a `uri` is read with parse_url() and a `decimal` with
   * preg_match(), and an array there throws a TypeError, which the Tool
   * API answers as a runtime failure rather than a refusal. So the shape
   * is held here first: a map, a list or an object sent for a key of one
   * of SurfacePipeline::SCALAR_TYPES, at any depth, is refused by its
   * path, as the pipeline refuses it, and taken out of what the Tool
   * API's validators are handed, the key made optional in the shape so
   * its absence says nothing more.
   *
   * Decision: see docs/decisions.md#a-refusal-names-what-it-depends-on.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $shape
   *   The shape, a copy (shapeOf()); a refused key is made optional in it.
   * @param mixed $value
   *   What was sent for it.
   * @param string $path
   *   The dotted path of the value, from the input's name.
   *
   * @return array{0: mixed, 1: array<string, array{0: string, 1: string}>}
   *   The value less what was refused; and what was refused, by its dotted
   *   path, the type the key holds and the type sent.
   */
  public static function withoutNonScalars(DataDefinitionInterface $shape, mixed $value, string $path): array {
    if (!is_array($value)) {
      return [$value, []];
    }
    $refused = [];
    $members = match (TRUE) {
      $shape instanceof ListDataDefinitionInterface => array_fill_keys(array_keys($value), $shape->getItemDefinition()),
      $shape instanceof ComplexDataDefinitionInterface => array_intersect_key($shape->getPropertyDefinitions(), $value),
      default => [],
    };
    foreach ($members as $name => $member) {
      $at = $path . '.' . $name;
      $sent = $value[$name];
      $scalar = !$member instanceof ComplexDataDefinitionInterface
        && !$member instanceof ListDataDefinitionInterface
        && in_array($member->getDataType(), SurfacePipeline::SCALAR_TYPES, TRUE);
      if ($scalar && (is_array($sent) || is_object($sent))) {
        $refused[$at] = [$member->getDataType(), get_debug_type($sent)];
        unset($value[$name]);
        if ($member instanceof DataDefinition) {
          $member->setRequired(FALSE);
        }
        continue;
      }
      [$value[$name], $below] = static::withoutNonScalars($member, $sent, $at);
      $refused += $below;
    }
    return [$value, $refused];
  }

  /**
   * Drops every constraint of a copied input, at any depth.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $input
   *   The copy; changed in place.
   * @param bool $requiresNothing
   *   Whether to make it optional too, and every property below it but a
   *   list's items.
   */
  protected static function withoutConstraints(DataDefinitionInterface $input, bool $requiresNothing = FALSE): void {
    if ($input instanceof DataDefinition) {
      $input->setConstraints([]);
      if ($requiresNothing) {
        $input->setRequired(FALSE);
      }
    }
    if ($input instanceof MapInputDefinition) {
      foreach ($input->getPropertyDefinitions() as $property) {
        static::withoutConstraints($property, $requiresNothing);
      }
    }
    if ($input instanceof ListInputDefinition) {
      // An item is sent whole, so it keeps the keys it requires.
      static::withoutConstraints($input->getItemDefinition());
    }
  }

  /**
   * Makes an input optional wherever a refiner may rule it out.
   *
   * @param \Drupal\tool\TypedData\InputDefinitionInterface $input
   *   The converted key; changed in place.
   * @param string[] $paths
   *   Where: the key itself as '', or a property's dotted path below it.
   */
  protected static function optionalWhereRuledOut(InputDefinitionInterface $input, array $paths): void {
    foreach ($paths as $path) {
      $target = $input;
      foreach ($path === '' ? [] : explode('.', $path) as $property) {
        $target = $target instanceof MapInputDefinition ? $target->getPropertyDefinition($property) : NULL;
      }
      if ($target instanceof InputDefinitionInterface) {
        $target->setRequired(FALSE);
      }
    }
  }

  /**
   * Marks an input locked, and every property inside it.
   *
   * @param \Drupal\tool\TypedData\InputDefinitionInterface $input
   *   The input.
   */
  protected static function lock(InputDefinitionInterface $input): void {
    $input->setLocked();
    if ($input instanceof MapInputDefinition) {
      foreach ($input->getPropertyDefinitions() as $property) {
        if ($property instanceof InputDefinitionInterface) {
          static::lock($property);
        }
      }
    }
    if ($input instanceof ListInputDefinition) {
      // A locked collection locks every item with it.
      static::lock($input->getItemDefinition());
    }
  }

  /**
   * Converts one key of a data surface, children included.
   *
   * @param \Drupal\surface\Entry $entry
   *   The key.
   * @param string|null $narrowing
   *   The narrowing tool's id, or NULL (fromSurface()).
   *
   * @return \Drupal\tool\TypedData\InputDefinitionInterface
   *   The input definition.
   */
  protected function fromEntry(Entry $entry, ?string $narrowing = NULL): InputDefinitionInterface {
    if ($this->deferred($entry)) {
      return $this->leftOut($entry, $entry->definition, $narrowing);
    }
    $was = $this->at;
    try {
      if ($entry->variants !== NULL && DefinitionMetadata::variantsBy($entry->definition) !== NULL) {
        $this->enter($entry->name);
        return $this->fromVariants($entry->variants, $entry->definition, $narrowing);
      }
      $item = $entry->definition instanceof ListDataDefinitionInterface ? $entry->definition->getItemDefinition() : NULL;
      if ($entry->list !== NULL && $item instanceof ComplexDataDefinitionInterface) {
        // A collection is a list of the child's maps, its Count carried as
        // the list's constraint, each item converted the way the child's
        // own entries say.
        $list = $this->fromDefinition($entry->definition);
        if ($list instanceof ListInputDefinition) {
          $this->enter($entry->name, '0');
          $list->setItemDefinition($this->fromChild($entry->list->child(), $item, $narrowing));
        }
        return $list;
      }
      $child = $entry->attachment?->child;
      if ($child === NULL || !$entry->definition instanceof ComplexDataDefinitionInterface) {
        return $this->fromDefinition($entry->definition);
      }
      $this->enter($entry->name);
      return $this->fromChild($child, $entry->definition, $narrowing);
    }
    finally {
      $this->at = $was;
    }
  }

  /**
   * Moves where the key being converted is, one key down.
   *
   * @param string $name
   *   The key the next frame is at.
   * @param string|null $below
   *   A segment past it: a collection's item.
   * @param string|null $chosen
   *   The variant the next frame is, chosen at the key.
   */
  protected function enter(string $name, ?string $below = NULL, ?string $chosen = NULL): void {
    $pointer = $this->at['pointer'] . '/' . Choices::escape($name);
    if ($chosen !== NULL) {
      $this->at['steps'][] = [$pointer, $chosen];
    }
    $this->at['pointer'] = $below === NULL ? $pointer : $pointer . '/' . $below;
    // What is stored in the next frame: the value at the key, and nothing
    // for a collection's item.
    $stored = $this->at['values'][$name] ?? NULL;
    $this->at['values'] = $below === NULL && is_array($stored) ? $stored : NULL;
  }

  /**
   * Answers whether the tool door leaves a key's entries out of a listing.
   *
   * Variants the site lists, always, whatever the site list limit says: a
   * tool's definition is sent with every request that offers it, and the
   * Tool API cannot say which keys a sibling's value chooses, so every
   * variant's keys would be one map, none required, large and loose at
   * once. The listing states the variant the stored values choose, and
   * how to learn any other. Not variants the build chose, their deciding
   * key locked: that is one shape. And a map of keys from the site past
   * the budget, which states each key exactly up to it.
   *
   * Decision: see docs/decisions.md#a-budget-for-what-the-site-decides.
   *
   * @param \Drupal\surface\Entry $entry
   *   The key.
   *
   * @return bool
   *   TRUE when the listing leaves its entries out.
   */
  public function deferred(Entry $entry): bool {
    if (static::listedVariants($entry)) {
      return DefinitionMetadata::variantsBy($entry->definition) !== NULL;
    }
    return $this->over($entry);
  }

  /**
   * Answers whether any key, at any depth, is left out of a listing.
   *
   * Reads no variant the site lists: a key of them is left out, or was
   * chosen by the build, which built the one it chose.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The built resource or structure.
   *
   * @return bool
   *   TRUE when a key is left out (deferred()), so the operation has a
   *   narrowing tool.
   */
  public function anyDeferred(SurfaceInterface $surface): bool {
    return SiteListBudget::anyKey(
      $surface,
      fn (Entry $entry): bool => $this->deferred($entry),
      static fn (Entry $entry, Attachment $variant): bool => !static::listedVariants($entry) || $variant->isSettled(),
    );
  }

  /**
   * Answers whether a key holds variants the site lists.
   *
   * @param \Drupal\surface\Entry $entry
   *   The key.
   *
   * @return bool
   *   TRUE for variants a provider lists (attachVariants(from:)), and the
   *   variants inside a structure read from a schema.
   */
  protected static function listedVariants(Entry $entry): bool {
    return $entry->variants !== NULL && SiteListBudget::listedBySite($entry);
  }

  /**
   * Answers whether a map of keys from the site is over the budget.
   *
   * What it read is the listing's: the setting's tag joins the list tags,
   * so the listing is derived again when the limit changes.
   *
   * @param \Drupal\surface\Entry $entry
   *   The key.
   *
   * @return bool
   *   TRUE when the listing states it open.
   */
  protected function over(Entry $entry): bool {
    if ($this->budget === NULL) {
      return FALSE;
    }
    $read = new CacheableMetadata();
    $over = $this->budget->over($entry, $read);
    $this->listTags = Cache::mergeTags($this->listTags, $read->getCacheTags());
    return $over;
  }

  /**
   * Converts a key whose entries the listing leaves out (deferred()).
   *
   * A map holding no key of the entries, where the listing would otherwise
   * carry one map per variant or per entry. Its description says how to
   * learn the keys: choose the deciding key, then fetch the full schema
   * with that choice, once per value, where the site serves its API, or
   * ask the narrowing tool with the values. Variants the site lists state
   * the one the stored values choose, as variants nothing chose state
   * each (fromVariants()), and no other: it alone is read.
   *
   * Decision: see
   * docs/decisions.md#a-list-left-out-for-size-is-deferred-not-refined,
   * and docs/decisions.md#a-budget-for-what-the-site-decides.
   *
   * @param \Drupal\surface\Entry $entry
   *   The key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   Its definition, as the map or variants advertise it.
   * @param string|null $narrowing
   *   The narrowing tool's id, or NULL.
   *
   * @return \Drupal\tool\TypedData\MapInputDefinition
   *   The map.
   */
  protected function leftOut(Entry $entry, DataDefinitionInterface $definition, ?string $narrowing): MapInputDefinition {
    $arguments = ['@by' => (string) $entry->variants?->by, '@tool' => (string) $narrowing];
    $fetch = NULL;
    if ($this->schemaPath !== NULL) {
      $choices = [];
      foreach ($this->at['steps'] as [$pointer, $value]) {
        $choices[$pointer][] = $value;
      }
      $query = Choices::query($choices);
      $arguments['@path'] = $this->schemaPath;
      // The path carries a query of its own when the operation takes a
      // parameter that is not identity.
      $arguments['@fetch'] = 'GET ' . $this->schemaPath . (str_contains($this->schemaPath, '?') ? '&' : '?') . $query . ($query === '' ? '' : '&') . Choices::PARAMETER . '=' . $this->at['pointer'] . '/' . Choices::escape($entry->name) . '=';
      $fetch = $entry->variants !== NULL;
    }
    if ($entry->variants !== NULL) {
      return $this->variantsLeftOut($entry, $definition, $narrowing, $arguments, $fetch === TRUE);
    }
    $note = match (TRUE) {
      $fetch === FALSE && $narrowing !== NULL => $this->t("Too many to list: fetch @fetch{id} once per entry (GET @path lists the ids under x-surface.deferred.entries), or call @tool with your values, to get this key's schema.", $arguments),
      $narrowing !== NULL => $this->t("Too many to list: call @tool with your values to get this key's schema.", $arguments),
      default => $this->t('Too many to list: one key per thing the site has.'),
    };
    return new MapInputDefinition(
      label: $this->label($definition),
      description: $this->appended($this->description($definition), $note),
      required: $definition->isRequired(),
      property_definitions: [],
    );
  }

  /**
   * Converts variants the site lists: the stored choice's keys, if any.
   *
   * @param \Drupal\surface\Entry $entry
   *   The key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The variants' placeholder.
   * @param string|null $narrowing
   *   The narrowing tool's id, or NULL.
   * @param array<string, string> $arguments
   *   The deciding key, the narrowing tool and the fetch, for the note.
   * @param bool $fetch
   *   Whether the note names the fetch.
   *
   * @return \Drupal\tool\TypedData\MapInputDefinition
   *   The map: no key, or those of the variant the stored values choose.
   */
  protected function variantsLeftOut(Entry $entry, DataDefinitionInterface $definition, ?string $narrowing, array $arguments, bool $fetch): MapInputDefinition {
    $variants = $entry->variants;
    assert($variants instanceof Variants);
    $chosen = $variants->chosen($this->at['values'][$variants->by] ?? NULL);
    if ($chosen === NULL) {
      $note = match (TRUE) {
        $fetch && $narrowing !== NULL => $this->t("Its keys depend on @by and are not listed here: choose @by, then fetch @fetch{@by} once per @by, or call @tool with your values, to get this key's schema.", $arguments),
        $narrowing !== NULL => $this->t("Its keys depend on @by and are not listed here: choose @by, then call @tool with your values to get this key's schema.", $arguments),
        default => $this->t('Its keys depend on @by and are not listed here.', $arguments),
      };
      return new MapInputDefinition(
        label: $this->label($definition),
        description: $this->appended($this->description($definition), $note),
        required: $definition->isRequired(),
        property_definitions: [],
      );
    }
    $arguments['@value'] = $chosen;
    $note = match (TRUE) {
      $fetch && $narrowing !== NULL => $this->t("Its keys depend on @by: listed here are those of @value, the @by stored. For another @by, fetch @fetch{@by} once per @by, or call @tool with your values, to get this key's schema.", $arguments),
      $narrowing !== NULL => $this->t("Its keys depend on @by: listed here are those of @value, the @by stored. For another @by, call @tool with your values to get this key's schema.", $arguments),
      default => $this->t('Its keys depend on @by: listed here are those of @value, the @by stored.', $arguments),
    };
    // The stored choice's keys as variants nothing chose state them, read
    // from that one variant alone.
    $was = $this->at;
    $this->enter($entry->name);
    try {
      $map = $this->fromVariants(new Variants($variants->by, $variants->shell, [$chosen => $variants->variant($chosen)]), $definition, $narrowing);
    }
    finally {
      $this->at = $was;
    }
    $map->setDescription($this->appended($this->description($definition), $note));
    return $map;
  }

  /**
   * Converts a child at a key, or each item of a collection.
   *
   * A map whose properties are the child's own keys, converted the way
   * the child's own entries say: variants inside the child are still
   * variants, and a collection still a list.
   *
   * @param \Drupal\surface\SurfaceInterface $child
   *   The child.
   * @param \Drupal\Core\TypedData\ComplexDataDefinitionInterface $definition
   *   The map it is advertised as at the key.
   * @param string|null $narrowing
   *   The narrowing tool's id, or NULL (fromSurface()).
   *
   * @return \Drupal\tool\TypedData\InputDefinitionInterface
   *   The map input.
   */
  protected function fromChild(SurfaceInterface $child, ComplexDataDefinitionInterface $definition, ?string $narrowing = NULL): InputDefinitionInterface {
    $map = $this->fromDefinition($definition);
    if ($map instanceof MapInputDefinition) {
      $properties = $definition->getPropertyDefinitions();
      foreach ($child->getDefinitions()->entries() as $name => $child_entry) {
        $property = $properties[$name] ?? $child_entry->definition;
        $converted = match (TRUE) {
          $this->deferred($child_entry) => $this->leftOut($child_entry, $property, $narrowing),
          $child_entry->variants !== NULL && isset($properties[$name]) && DefinitionMetadata::variantsBy($properties[$name]) !== NULL => $this->variantsAt((string) $name, $child_entry->variants, $properties[$name], $narrowing),
          $child_entry->list !== NULL => $this->fromEntry($child_entry->withDefinition($property), $narrowing),
          default => $this->fromDefinition($property),
        };
        // As at the top: a key the child's refiners may rule out, by the
        // child's own values or by a port, is optional in a listing.
        // Decision: see docs/decisions.md#a-key-that-does-not-apply.
        static::optionalWhereRuledOut($converted, $child_entry->mayNotApply());
        if ($child->getDefinitions()->isLocked((string) $name)) {
          // As at the top: an entry of a map of keys from the site its
          // provider locked, say.
          static::lock($converted);
        }
        if ($child_entry->runtimeAccess) {
          $this->runtimeAccessNote($converted);
        }
        $map->setPropertyDefinition((string) $name, $converted);
      }
    }
    return $map;
  }

  /**
   * Converts variants at a key of the frame being converted.
   *
   * @param string $name
   *   The key.
   * @param \Drupal\surface\Variants $variants
   *   The variant table.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $placeholder
   *   The variants' placeholder.
   * @param string|null $narrowing
   *   The narrowing tool's id, or NULL.
   *
   * @return \Drupal\tool\TypedData\MapInputDefinition
   *   The union map.
   */
  protected function variantsAt(string $name, Variants $variants, DataDefinitionInterface $placeholder, ?string $narrowing): MapInputDefinition {
    $was = $this->at;
    $this->enter($name);
    try {
      return $this->fromVariants($variants, $placeholder, $narrowing);
    }
    finally {
      $this->at = $was;
    }
  }

  /**
   * Converts variants whose deciding key has chosen nothing.
   *
   * The widest honest schema, and a known Tool API gap. What variants say
   * — "this map is exactly variant A's when the sibling is a, exactly
   * variant B's when it is b" — is a union keyed by a sibling. JSON
   * Schema can say it, but only on the parent: an `if` naming the
   * sibling's `const` and a `then` naming this property's schema, once
   * per variant, or a `oneOf` over whole parent objects. The Tool API's
   * definitions have no place for either. A context definition carries
   * one data type, constraints and, for a map, its property definitions;
   * MapInputDefinition has no union, no conditional and no `oneOf`, and
   * its normalizer emits one schema per property with no reference to a
   * sibling. And `oneOf` on the key's own schema would not do: the
   * deciding key is not inside the key's value, so nothing in that
   * value could select a branch.
   *
   * So the variants are advertised as a map holding every key any variant
   * declares, in variant order, none of them required and none with a
   * default (both depend on the variant), each annotated with the values
   * of the deciding key it belongs to, and the whole table said in the
   * variants' own description. Nothing is promised that the pipeline does
   * not keep: it refuses a key from another variant by name, and judges
   * the chosen variant's keys by that variant's own rules. A key two
   * variants share is advertised with the first variant's definition.
   *
   * @param \Drupal\surface\Variants $variants
   *   The variant table.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $placeholder
   *   The variants' placeholder.
   * @param string|null $narrowing
   *   The narrowing tool's id, which a key inside a variant whose entries
   *   the listing leaves out names; NULL when there is none.
   *
   * @return \Drupal\tool\TypedData\MapInputDefinition
   *   The union map.
   */
  public function fromVariants(Variants $variants, DataDefinitionInterface $placeholder, ?string $narrowing = NULL): MapInputDefinition {
    $at = $this->at;
    $owners = [];
    $first = [];
    $entries = [];
    $table = [];
    foreach ($variants->variants as $id => $variant) {
      $keys = [];
      foreach ($variant->child->getDefinitions()->entries() as $name => $entry) {
        $owners[$name][] = (string) $id;
        $first[$name] ??= $entry->definition;
        $entries[$name] ??= $entry;
        $keys[] = $name;
      }
      $table[] = $this->t('@value: @keys', [
        '@value' => $id,
        '@keys' => $keys === [] ? $this->t('nothing') : implode(', ', $keys),
      ]);
    }
    $properties = [];
    foreach ($first as $name => $definition) {
      if ($this->deferred($entries[$name])) {
        // A key inside the variant whose entries the listing leaves out, at
        // its own level, reached through the first variant that holds it.
        $this->at = ['steps' => [...$at['steps'], [$at['pointer'], $owners[$name][0]]]] + $at;
        try {
          $properties[$name] = $this->leftOut($entries[$name], $definition, $narrowing);
        }
        finally {
          $this->at = $at;
        }
        $properties[$name]->setRequired(FALSE);
        continue;
      }
      // A copy, said the way the union means it: optional, with no
      // default, and the variants it belongs to named. Only its own
      // flags change, so a shallow clone leaves the variant untouched.
      $union = clone $definition;
      $note = $this->t('Only when @by is @values.', [
        '@by' => $variants->by,
        '@values' => implode(', ', $owners[$name]),
      ]);
      if ($union instanceof DataDefinition) {
        $union->setRequired(FALSE);
        $union->setDescription($this->appended($this->description($definition), $note));
      }
      DefinitionMetadata::setDefaultValue($union, NULL);
      $properties[$name] = $this->fromDefinition($union);
    }
    $note = $this->t('Its keys depend on @by. @table.', [
      '@by' => $variants->by,
      '@table' => $table === [] ? $this->t('No variant fills it') : implode('; ', array_map('strval', $table)),
    ]);
    return new MapInputDefinition(
      label: $this->label($placeholder),
      description: $this->appended($this->description($placeholder), $note),
      required: $placeholder->isRequired(),
      property_definitions: $properties,
    );
  }

  /**
   * Converts a data surface's outputs into Tool API output definitions.
   *
   * The same bridge in the other direction. A tool's outputs are the
   * Tool API's output definitions rather than its input definitions,
   * and rightly so: an output is never rendered as a form
   * element, never refined by a caller's other answers, and never
   * locked, so the three things an input definition adds are all
   * meaningless here.
   *
   * What travels: the data type, the label, the description, whether
   * the output is required, the constraints, and — through the same
   * enum treatment the inputs get — a plain Choice beside any
   * constraint that names a value list, so the advertised schema shows
   * the vocabulary.
   *
   * What is lost, beyond the two the inputs already lose (example
   * values and type settings, both Tool API gaps):
   * - The presence rule. A data surface says an output is absent when
   *   the producer omitted it; JSON Schema says the same thing with
   *   `required`, which is what `required` converts to, so the
   *   statement survives, but the NotEmitted sentinel itself does not
   *   travel, because it is a PHP marker and not a value.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface whose outputs to convert.
   *
   * @return array<string, \Drupal\tool\TypedData\OutputDefinitionInterface>
   *   The output definitions, keyed by output name, in the order the
   *   data surface declares them. Empty for one that declares no
   *   outputs, which is what a tool with nothing to add should pass
   *   straight over.
   */
  public function outputsFromSurface(SurfaceInterface $surface): array {
    $outputs = [];
    foreach ($surface->getOutputDefinitions() as $name => $definition) {
      $outputs[$name] = $this->outputFromDefinition($definition);
    }
    return $outputs;
  }

  /**
   * Converts one core data definition into one output definition.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to convert.
   *
   * @return \Drupal\tool\TypedData\OutputDefinitionInterface
   *   The output definition.
   */
  public function outputFromDefinition(DataDefinitionInterface $definition): OutputDefinitionInterface {
    $label = $this->label($definition);
    $description = $this->description($definition);
    $constraints = $this->constraints($definition);
    $required = $definition->isRequired();

    if ($definition instanceof ListDataDefinitionInterface) {
      return new ListOutputDefinition(
        label: $label,
        required: $required,
        description: $description,
        constraints: $constraints,
        item_definition: $this->outputFromDefinition($definition->getItemDefinition()),
      );
    }
    $properties = $definition instanceof ComplexDataDefinitionInterface
      ? $definition->getPropertyDefinitions()
      : [];
    if ($properties !== []) {
      $property_definitions = [];
      foreach ($properties as $name => $property_definition) {
        $property_definitions[$name] = $this->outputFromDefinition($property_definition);
      }
      return new MapOutputDefinition(
        label: $label,
        required: $required,
        description: $description,
        constraints: $constraints,
        property_definitions: $property_definitions,
      );
    }
    // No default value argument anywhere above, and none here: an
    // output carries none, which is the one place the two directions of
    // this bridge differ in substance rather than in class.
    return new OutputDefinition(
      data_type: $definition->getDataType(),
      label: $label,
      required: $required,
      description: $description,
      constraints: $constraints,
    );
  }

  /**
   * Converts one core data definition into one input definition.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to convert.
   *
   * @return \Drupal\tool\TypedData\InputDefinitionInterface
   *   The input definition.
   */
  public function fromDefinition(DataDefinitionInterface $definition): InputDefinitionInterface {
    $label = $this->label($definition);
    $description = $this->description($definition);
    $constraints = $this->constraints($definition);
    $secret = DefinitionMetadata::isSecret($definition);
    // A secret is optional whatever it requires: left out or null, the
    // stored secret stands, and the pipeline refuses one never set.
    // Decision: see docs/decisions.md#a-secret-is-optional-never-nullable.
    $required = $definition->isRequired() && !$secret;
    // A secret carries no default across. A default is a value the
    // advertised schema shows to every caller, and a secret's whole
    // claim is that its value is never read back; a data surface that
    // declares one anyway is saying something it cannot mean.
    $default = !$secret && DefinitionMetadata::hasDefaultValue($definition)
      ? DefinitionMetadata::getDefaultValue($definition)
      : NULL;
    if ($secret) {
      $description = $this->secretNote($description);
    }

    if ($definition instanceof ListDataDefinitionInterface) {
      return new ListInputDefinition(
        label: $label,
        description: $description,
        required: $required,
        default_value: $default,
        constraints: $constraints,
        item_definition: $this->fromDefinition($definition->getItemDefinition()),
      );
    }
    $properties = $definition instanceof ComplexDataDefinitionInterface
      ? $definition->getPropertyDefinitions()
      : [];
    if ($properties !== []) {
      $property_definitions = [];
      foreach ($properties as $name => $property_definition) {
        $property_definitions[$name] = $this->fromDefinition($property_definition);
      }
      return new MapInputDefinition(
        label: $label,
        description: $description,
        required: $required,
        default_value: $default,
        constraints: $constraints,
        property_definitions: $property_definitions,
      );
    }
    return new InputDefinition(
      data_type: $definition->getDataType(),
      label: $label,
      description: $description,
      required: $required,
      default_value: $default,
      constraints: $constraints,
    );
  }

  /**
   * Defaults each property to the value storage holds for it now.
   *
   * For a `values` input built in a context that changes a thing that
   * exists, so a caller reads what is there, not what a new one would
   * start from. A key storage holds nothing for keeps its declared
   * default. A secret keeps none: its stored value is never read back. A
   * child is walked key by key through the data surface that describes the
   * stored value (for variants, the variant its stored deciding key chooses,
   * and only when the stored value fits it), so a secret inside a child
   * is kept off the schema too, and the map itself carries no default:
   * fromSurface() says why a map's default would act rather than describe.
   *
   * @param \Drupal\tool\TypedData\MapInputDefinition $map
   *   The converted data surface, or a converted child; changed in place.
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface it was converted from.
   * @param array $stored
   *   What the resource's storage loads, keyed by its keys.
   *
   * @return \Drupal\tool\TypedData\MapInputDefinition
   *   The same map.
   */
  public function withStored(MapInputDefinition $map, SurfaceInterface $surface, array $stored): MapInputDefinition {
    $properties = $map->getPropertyDefinitions();
    foreach ($surface->getDefinitions()->entries() as $name => $entry) {
      $property = $properties[$name] ?? NULL;
      if (!$property instanceof InputDefinitionInterface || !array_key_exists($name, $stored) || DefinitionMetadata::isSecret($entry->definition)) {
        continue;
      }
      if (!$entry->isNested()) {
        $property->setDefaultValue($stored[$name]);
        continue;
      }
      $child = $entry->childFor($stored);
      $chosen = $entry->variants?->chosen($stored[$entry->variants->by] ?? NULL);
      $held = $chosen === NULL || !is_array($stored[$name]) ? $stored[$name] : $entry->variants->held($chosen, $stored[$name]);
      if ($child !== NULL && $property instanceof MapInputDefinition && is_array($held)) {
        $this->withStored($property, $child, $held);
      }
    }
    return $map;
  }

  /**
   * Takes every default off a map's properties, at any depth.
   *
   * For the static listing of an operation that changes a thing that
   * exists: a key left out keeps what is stored, so a default there would
   * say what does not happen. A list's items keep theirs, since a new
   * item has nothing stored.
   *
   * @param \Drupal\tool\TypedData\MapInputDefinition $map
   *   The map, changed in place.
   *
   * @return \Drupal\tool\TypedData\MapInputDefinition
   *   The same map.
   */
  public function withoutDefaults(MapInputDefinition $map): MapInputDefinition {
    foreach ($map->getPropertyDefinitions() as $property) {
      if (!$property instanceof InputDefinitionInterface) {
        continue;
      }
      $property->setDefaultValue(NULL);
      if ($property instanceof MapInputDefinition) {
        $this->withoutDefaults($property);
      }
    }
    return $map;
  }

  /**
   * Requires no property of a map, at any depth, saying it in words.
   *
   * For the static listing of an operation that changes a thing that
   * exists: a key left out keeps what is stored, and the Tool API refuses
   * a required property a payload leaves out before the tool runs, so a
   * required one there would refuse a call the pipeline accepts. Each key
   * the resource requires says so in its description instead, and the
   * pipeline still refuses the merged document when nothing is stored for
   * it, in the key's own words. A list's items keep theirs, since an item
   * is sent whole, with nothing stored under it to stand.
   *
   * Decision: see
   * docs/decisions.md#required-is-the-resources-and-an-update-tool-requires-nothing-at-its-door.
   *
   * @param \Drupal\tool\TypedData\MapInputDefinition $map
   *   The map, changed in place.
   *
   * @return \Drupal\tool\TypedData\MapInputDefinition
   *   The same map.
   */
  public function withoutRequired(MapInputDefinition $map): MapInputDefinition {
    foreach ($map->getPropertyDefinitions() as $property) {
      if (!$property instanceof InputDefinitionInterface) {
        continue;
      }
      if ($property->isRequired()) {
        $property->setRequired(FALSE);
        if ($property instanceof DataDefinition) {
          $property->setDescription($this->appended($this->description($property), $this->t('Required; a value already stored stands when left out.')));
        }
      }
      if ($property instanceof MapInputDefinition) {
        $this->withoutRequired($property);
      }
    }
    $map->setRequired(FALSE);
    return $map;
  }

  /**
   * Finds every key a data surface marks secret, at any depth.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   *
   * @return array<string, true|array>
   *   As Outbound::secrets() answers: TRUE where a key is secret, an
   *   array where one below it is, a list's items' under EACH.
   *
   * @see \Drupal\surface\Pipeline\Outbound::secrets()
   */
  public function secrets(SurfaceInterface $surface): array {
    // The listing states no key of a variant it leaves out, so a secret
    // inside one is no key of it: those are not built to ask.
    // Decision: see
    // docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
    return Outbound::secrets($surface, fn (Entry $entry): bool => !$this->deferred($entry));
  }

  /**
   * Leaves every secret out of a set of values.
   *
   * @param array $values
   *   The values, keyed like the data surface.
   * @param array<string, true|array> $secrets
   *   What secrets() found.
   *
   * @return array
   *   The values, with no secret in them.
   */
  public function withoutSecrets(array $values, array $secrets): array {
    // Decision: see docs/decisions.md#a-read-leaves-a-secret-out.
    return Outbound::withoutSecrets($values, $secrets);
  }

  /**
   * Says a converted input as an output: the same shape, no promises.
   *
   * For an answer that reports what is stored, in the shape a caller
   * sends it: each map, list and scalar keeps its data type, label and
   * description, and nests as it did. Nothing else crosses. A default is
   * an input's idea. A constraint would be a promise a stored value
   * cannot keep once it goes stale, and the Tool API holds a tool's
   * outputs to their definitions, so a stale value would fail the read
   * that reports it. And no property is required, because a key storage
   * holds nothing for is absent.
   *
   * @param \Drupal\tool\TypedData\InputDefinitionInterface $input
   *   The converted input.
   * @param array<string, true|array> $leave_out
   *   The properties to leave out, as secrets() finds them.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string|null $label
   *   The output's label, or NULL for the input's.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string|null $description
   *   The output's description, or NULL for the input's. Given with a
   *   label, the output is required: it is the answer itself.
   *
   * @return \Drupal\tool\TypedData\OutputDefinitionInterface
   *   The output definition.
   */
  public function outputFromInput(InputDefinitionInterface $input, array $leave_out = [], TranslatableMarkup|string|null $label = NULL, TranslatableMarkup|string|null $description = NULL): OutputDefinitionInterface {
    $required = $label !== NULL;
    $label ??= $input->getLabel() ?? '';
    $description ??= $input->getDescription() ?? '';
    if ($input instanceof MapInputDefinition) {
      $properties = [];
      foreach ($input->getPropertyDefinitions() as $name => $property) {
        $secret = $leave_out[$name] ?? [];
        if ($secret !== TRUE && $property instanceof InputDefinitionInterface) {
          $properties[$name] = $this->outputFromInput($property, $secret);
        }
      }
      return new MapOutputDefinition(
        label: $label,
        description: $description,
        required: $required,
        property_definitions: $properties,
      );
    }
    if ($input instanceof ListInputDefinition) {
      $each = $leave_out[self::EACH] ?? [];
      return new ListOutputDefinition(
        label: $label,
        description: $description,
        required: $required,
        item_definition: $this->outputFromInput($input->getItemDefinition(), is_array($each) ? $each : []),
      );
    }
    return new OutputDefinition(
      data_type: $input->getDataType(),
      label: $label,
      description: $description,
      required: $required,
      multiple: $input->isMultiple(),
    );
  }

  /**
   * Says in words what phase B will say in the schema.
   *
   * JSON Schema has the keyword for this — a secret is `writeOnly`, and
   * the emitter the roadmap's phase B lands will say so — but the Tool
   * API's context definitions have nowhere to carry it today, so an
   * agent reading the advertised input would have no way to know that
   * sending this key replaces a value it can never read. A sentence in
   * the description is small, honest, and costs nothing to delete on the
   * day the keyword travels.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $description
   *   The description so far, empty when the definition declares none.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The description with the note appended.
   */
  protected function secretNote(TranslatableMarkup|string $description): TranslatableMarkup {
    return $this->appended($description, $this->t('Write only: the stored value is never returned, and sending nothing leaves it unchanged.'));
  }

  /**
   * Says on a key that only the subject decides whether it may be changed.
   *
   * A key a runtime access answer may lock or leave out, which a listing
   * built for the account alone keeps open. The Tool API's normalizer
   * writes JSON Schema from these typed definitions and carries a
   * description, and no extension keyword, so the description says it:
   * the served contract's `x-surface.live`, in words. Not said
   * on a key whose only mark is a runtime answer at the top that the
   * tool's description already says once for every key
   * (OperationInputs::description()): a note on each key would bury what
   * each says of itself.
   *
   * Decision: see
   * docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability.
   *
   * @param \Drupal\tool\TypedData\InputDefinitionInterface $input
   *   The converted key; changed in place.
   */
  protected function runtimeAccessNote(InputDefinitionInterface $input): void {
    $note = $this->t('Whether this may be changed is decided once the thing it belongs to is known: a call may find it locked or left out.');
    if ($input instanceof DataDefinition) {
      $input->setDescription($this->appended($this->description($input), $note));
    }
  }

  /**
   * Copies a definition's constraints, teaching the enum one to travel.
   *
   * The constraints are carried across by name, with the same options, so
   * a Drupal constraint plugin goes on validating exactly as it did on
   * the data surface. The one
   * addition is the enum: the Tool API's schema normalizer reads Choice
   * and AllowedValues and nothing else, so a value list declared as
   * anything else (the labeled choice constraint, an existence
   * constraint such as Country or LanguageExists) would simply not
   * appear in the advertised schema. The list is asked for once, from
   * the options service, which is the only code that knows how to read a
   * constraint as a list; a plain Choice carrying those values is added
   * beside the constraint that named them. Both then validate the same
   * value space, so the duplication cannot make them disagree.
   *
   * LabeledChoice is a Symfony Choice subclass, so one might expect the
   * normalizer to see it without this. It does not: the normalizer
   * matches constraints by their Drupal plugin ID — isset($c['Choice'])
   * — and never with instanceof, so a subclass registered under its own
   * ID is invisible to it and the duplicate has to stay until that is a
   * Tool API change. FieldToolsComparisonTest is where removing it stops
   * emitting the enum.
   *
   * A definition that already carries a Choice keeps it: it is its own
   * enum, and the options service would only hand back what it says.
   *
   * Every option value is then made a scalar (scalarOptions() says how
   * and why), so a LabeledChoice's labels travel as the strings they
   * read as rather than as TranslatableMarkup. A message option written
   * with Symfony's `{{ max }}` is respelled `%max`, which is what core's
   * translator fills in, so the Tool API's own refusal shows the number
   * as the pipeline's does (ViolationMessage). The respelled message is a
   * second source for a translator; it has to stay, because the Tool API
   * validates through core's translator, which fills in Drupal's
   * placeholders alone. A message spelled Drupal's way, `%max`, is
   * copied as it is, so every door meets the one source the author wrote.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to read.
   *
   * @return array<string, mixed>
   *   The constraints, keyed by constraint name.
   *
   * @throws \LogicException
   *   When an option holds an object that has no scalar spelling.
   */
  protected function constraints(DataDefinitionInterface $definition): array {
    $constraints = $definition->getConstraints();
    if (!isset($constraints[self::CHOICE])) {
      $set = $this->options->resolve($definition);
      if ($set !== NULL && $set->options !== []) {
        $constraints[self::CHOICE] = ['choices' => array_keys($set->options)];
        // A list read from the site is in the definition until a tag it
        // was read under is invalidated.
        $this->listTags = Cache::mergeTags($this->listTags, $set->getCacheTags());
      }
    }
    foreach ($constraints as $name => $options) {
      $constraints[$name] = $this->scalarOptions($options, (string) $name);
      // An options-less constraint (NotNull, NotBlank) is declared as NULL.
      if (!is_array($constraints[$name])) {
        continue;
      }
      foreach ($constraints[$name] as $option => $value) {
        if (is_string($value) && preg_match('/message$/i', (string) $option)) {
          $constraints[$name][$option] = ViolationMessage::drupalPlaceholders($value);
        }
      }
    }
    return $constraints;
  }

  /**
   * Spells a constraint's options with scalars and arrays alone.
   *
   * A tool definition's constraint options are config-schema shaped:
   * the Tool API copies them into the config schema it suggests for a
   * tool, and Tool Explorer prints that schema with Yaml::encode(), which
   * refuses an object. A data surface's own constraints may hold objects
   * (a LabeledChoice's labels and descriptions are TranslatableMarkup),
   * because the data surface is the PHP side and they validate the same
   * either way. So markup converts to its plain text, any other
   * stringable object to the string it reads as, a backed enum to its
   * value, and anything else is refused here,
   * by name, rather than as an encoding error on a page that has nothing
   * to do with it.
   *
   * What the string costs: a tool definition is cached once for every
   * language, so a label is in the language discovery ran in. It costs
   * nothing an invoker reads today, because the Tool API's normalizer
   * advertises a value list from Choice alone and never these labels;
   * the tool's own label and description stay TranslatableMarkup, which
   * the Tool API takes as such, and so does each key's, sentences
   * appended included: every one of them is rendered in the language
   * that reads it.
   *
   * @param mixed $options
   *   A constraint's options, or one value inside them.
   * @param string $path
   *   Where the value is, for the message.
   *
   * @return mixed
   *   The same options, with no object anywhere in them.
   *
   * @throws \LogicException
   *   When an object is neither stringable nor a backed enum.
   */
  protected function scalarOptions(mixed $options, string $path): mixed {
    if (is_array($options)) {
      foreach ($options as $key => $value) {
        $options[$key] = $this->scalarOptions($value, $path . '.' . $key);
      }
      return $options;
    }
    if (!is_object($options)) {
      return $options;
    }
    if ($options instanceof \BackedEnum) {
      return $options->value;
    }
    if ($options instanceof MarkupInterface) {
      // Markup, as a select draws a label: the plain text the documents
      // carry, so an escaped "&lt;no caching&gt;" reads "<no caching>".
      // Decision: see docs/decisions.md#a-machine-document-carries-plain-text.
      return PlainText::of($options);
    }
    if ($options instanceof \Stringable) {
      return (string) $options;
    }
    throw new \LogicException(sprintf('The constraint option %s holds a %s, which a tool definition cannot carry: its constraint options are config schema, scalars and arrays only.', $path, get_class($options)));
  }

  /**
   * Adds a sentence to the end of a definition's description.
   *
   * @param \Drupal\Core\TypedData\DataDefinition $definition
   *   The definition; changed in place.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $note
   *   The sentence.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The same definition.
   */
  public function noted(DataDefinition $definition, TranslatableMarkup $note): DataDefinition {
    return $definition->setDescription($this->appended($this->description($definition), $note));
  }

  /**
   * Appends a sentence to a description, as plain text.
   *
   * Both stay objects, so a definition cached for every language reads
   * in the language that reads it, the description and the sentence
   * alike. A description read as plain text is joined as the markup it
   * decorates, and the two are read as plain text together, so neither
   * is escaped: "you'd" stays "you'd". A plain string is text, which the
   * placeholder escapes and the reading decodes.
   *
   * Decision: see docs/decisions.md#a-machine-document-carries-plain-text.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $description
   *   The description, or ''.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $note
   *   The sentence.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The two, or the sentence alone.
   */
  protected function appended(TranslatableMarkup|string $description, TranslatableMarkup $note): TranslatableMarkup {
    if ($description instanceof PlainTextMarkup) {
      $description = $description->markup();
    }
    $empty = $description instanceof TranslatableMarkup ? $description->getUntranslatedString() === '' : $description === '';
    return PlainTextMarkup::translatable($empty ? $note : $this->t('@description @note', [
      '@description' => $description,
      '@note' => $note,
    ]));
  }

  /**
   * Gets a definition's label, which an input definition must have.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to read.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   *   The label, as plain text: a tool listing renders no markup.
   */
  protected function label(DataDefinitionInterface $definition): TranslatableMarkup|string {
    $label = $definition->getLabel();
    if ($label === NULL || (string) $label === '') {
      return $this->t('@type value', ['@type' => $definition->getDataType()]);
    }
    return PlainTextMarkup::of($label);
  }

  /**
   * Gets a definition's description, or nothing when it declares none.
   *
   * An input definition demands a description where a data definition
   * treats it as optional, and the empty string is the honest answer: a
   * manufactured sentence repeating the label reads as help text a human
   * wrote, and tells a reader nothing the label did not.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to read.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   *   The description as plain text, empty when the definition declares
   *   none.
   */
  protected function description(DataDefinitionInterface $definition): TranslatableMarkup|string {
    $description = $definition->getDescription();
    if ($description === NULL) {
      return '';
    }
    return PlainTextMarkup::of($description);
  }

}
