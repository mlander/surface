<?php

declare(strict_types=1);

namespace Drupal\data_surface\Pipeline;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\data_surface\DataSurfaceAccess;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\DefinitionTypedData;
use Drupal\data_surface\Options\DataSurfaceOptions;
use Drupal\data_surface\Refinement\DoesNotApply;
use Drupal\data_surface\Entry;
use Drupal\data_surface\Collection;
use Symfony\Component\Validator\Constraints\Choice;

/**
 * The one entry point from raw values to stored values.
 *
 * @see \Drupal\data_surface\Pipeline\DataSurfacePipelineInterface
 *   For the documentation of every method.
 * @see docs/semantics.md
 *   For the rules accept() and validate() apply, as tables, including
 *   the one exception a secret key makes to them.
 */
final class DataSurfacePipeline implements DataSurfacePipelineInterface {

  use StringTranslationTrait;

  /**
   * The data types that hold one scalar.
   *
   * An array for one of them is a type mismatch, refused rather than
   * coerced. Every other
   * type ('any', a map that declares no properties, anything a
   * contributed typed data plugin adds) is left alone by both, because a
   * rule it never defined would be a guess.
   */
  protected const SCALAR_TYPES = [
    'integer',
    'float',
    'boolean',
    'string',
    'email',
    'uri',
    'timestamp',
    'datetime_iso8601',
    'decimal',
  ];

  /**
   * The data types an output is held to the PHP type of.
   */
  protected const OUTPUT_TYPES = ['integer', 'float', 'boolean', 'string', 'email', 'uri'];

  /**
   * The most allowed values a refusal lists, before saying how many more.
   */
  public const CHOICES_SHOWN = 10;

  /**
   * The locked keys the last accept() was sent a value it did not take.
   *
   * Collected while accepting, one per key by its full path, so a caller
   * learns why a value it sent did not land; submit() hands them on as
   * the result's notes. Every accept() starts it again.
   *
   * Decision: see docs/decisions.md#locked-at-the-top.
   *
   * @var array<string, \Drupal\data_surface\Pipeline\Violation>
   */
  protected array $notTaken = [];

  /**
   * The secrets a sent collection item would erase, matched by position.
   *
   * Collected while accepting, by full path: an item that leaves out a
   * secret storage holds at its position, in a list sent with items added
   * or removed, where the position may hold another item now. submit()
   * refuses the run with them. Every accept() starts it again.
   *
   * Decision: see
   * docs/decisions.md#every-door-projects-what-the-read-tool-projects.
   *
   * @var array<string, \Drupal\data_surface\Pipeline\Violation>
   */
  protected array $unmatched = [];

  /**
   * Constructs a DataSurfacePipeline.
   *
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The typed data manager, used to run the data surface's constraints.
   * @param \Drupal\data_surface\Options\DataSurfaceOptions $options
   *   The options service, asked one question and only when a value has
   *   already been refused: is this value among the ones its key offers?
   *   The list that validates and the list that is offered are the same
   *   list, so the stale rule has to read it from the same place a
   *   generated select does, or a form would stash a value the pipeline
   *   then refused.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service. The pipeline writes the sentences
   *   a person reads when a value is refused, so it is handed the
   *   service rather than letting each message reach the global
   *   container when it is finally rendered.
   */
  public function __construct(
    protected readonly TypedDataManagerInterface $typedDataManager,
    protected readonly DataSurfaceOptions $options,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public function accept(DataSurfaceInterface $surface, array $input, array $current = []): array {
    $this->notTaken = [];
    $this->unmatched = [];
    return $this->acceptLevel($surface, $input, $current, '');
  }

  /**
   * Accepts one data surface's values, at the top or inside a child.
   *
   * A child's value is accepted by the built child describing it,
   * through this same method, so its locked keys, secrets and defaults
   * answer for themselves exactly as they would on their own. Slots are
   * accepted after everything else, because the variant is read from the
   * accepted value of the deciding key, and so is a child that reads
   * ports, which read the accepted values of the keys they are wired to;
   * the result keeps declaration order.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface describing this level.
   * @param array $input
   *   The raw input for this level.
   * @param array $current
   *   What this level already holds.
   * @param string $prefix
   *   The dotted path of this level; '' at the top.
   *
   * @return array
   *   The accepted values, in declaration order.
   *
   * @throws \Drupal\data_surface\Pipeline\UnknownKeysException
   *   When an input key is not a definition at any depth.
   * @throws \Drupal\data_surface\Pipeline\TypeMismatchException
   *   When the input cannot be held by the definition at all.
   * @throws \Drupal\data_surface\Pipeline\VariantMismatchException
   *   When a slot's value is shaped for a variant other than the chosen.
   */
  protected function acceptLevel(DataSurfaceInterface $surface, array $input, array $current, string $prefix): array {
    $definitions = $surface->getDefinitions();
    $unknown = array_keys(array_diff_key($input, $definitions->toArray()));
    if ($unknown !== []) {
      throw new UnknownKeysException(array_map('strval', $unknown), $prefix);
    }
    $values = array_fill_keys($definitions->names(), NULL);
    $slots = [];
    foreach ($definitions->entries() as $name => $entry) {
      if ($entry->slot !== NULL || ($entry->attachment !== NULL && $entry->attachment->reads !== [])) {
        $slots[] = $entry;
        continue;
      }
      $values[$name] = $this->acceptKey($surface, $entry, $input, $current, $prefix, $values);
    }
    foreach ($slots as $entry) {
      $values[$entry->name] = $this->acceptKey($surface, $entry, $input, $current, $prefix, $values);
    }
    return $this->withoutWhatDoesNotApply($surface, $values, $input, $current);
  }

  /**
   * Leaves out of the accepted document what does not apply to it.
   *
   * A key a refiner ruled out for these values is read as left out: what
   * it holds stands, in storage, and the document says nothing about it.
   * A value sent for it other than what it holds stays, for validate()
   * to refuse; one that holds nothing (null, or a form's empty string)
   * or what it holds already says nothing, and goes. The same inside a
   * map, for a key an alter mounted.
   *
   * Decision: see docs/decisions.md#a-key-that-does-not-apply.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface describing this level.
   * @param array $values
   *   The accepted values of this level, every key present.
   * @param array $input
   *   The raw input for this level.
   * @param array $current
   *   What this level already holds.
   *
   * @return array
   *   The accepted values, less what does not apply and was not sent.
   */
  protected function withoutWhatDoesNotApply(DataSurfaceInterface $surface, array $values, array $input, array $current): array {
    $refined = $surface->refine($values);
    if ($refined === $surface) {
      return $values;
    }
    $defaults = NULL;
    foreach ($surface->getDefinitions()->entries() as $name => $entry) {
      $name = (string) $name;
      $definition = $refined->getDefinition($name);
      $paths = DoesNotApply::marks($definition) ? [''] : ($definition === NULL ? [] : DoesNotApply::inside($definition));
      foreach ($paths as $path) {
        $parents = $path === '' ? [$name] : [$name, ...explode('.', $path)];
        $defaults ??= $surface->getDefaultValues();
        $fallback = NestedArray::keyExists($current, $parents)
          ? NestedArray::getValue($current, $parents)
          : NestedArray::getValue($defaults, $parents);
        $sent = NestedArray::keyExists($input, $parents) ? NestedArray::getValue($input, $parents) : NULL;
        if (!ValueState::isConfigured($sent) || NestedArray::getValue($values, $parents) == $fallback) {
          NestedArray::unsetValue($values, $parents);
        }
      }
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function outbound(DataSurfaceInterface $surface, array $values): array {
    return Outbound::project($surface, $values);
  }

  /**
   * {@inheritdoc}
   */
  public function document(DataSurfaceInterface $surface, array $values): \stdClass {
    $document = new \stdClass();
    foreach ($values as $name => $value) {
      $entry = $surface->getDefinitions()->entry((string) $name);
      $child = $entry?->childFor($values);
      $document->{$name} = match (TRUE) {
        $entry === NULL || !is_array($value) => $value,
        $child !== NULL => $this->document($child, $value),
        $entry->list !== NULL => array_map(fn (mixed $item): mixed => is_array($item) ? $this->document($entry->list->item->child, $item) : $item, array_values($value)),
        default => static::documentValue($entry->definition, $value),
      };
    }
    return $document;
  }

  /**
   * Writes one value of an accepted document with every map an object.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The value's definition.
   * @param mixed $value
   *   The value.
   *
   * @return mixed
   *   The value, each map in it an object and each list a list.
   */
  protected static function documentValue(DataDefinitionInterface $definition, mixed $value): mixed {
    if (!is_array($value)) {
      return $value;
    }
    if ($definition instanceof ListDataDefinitionInterface) {
      return array_map(static fn (mixed $item): mixed => static::documentValue($definition->getItemDefinition(), $item), array_values($value));
    }
    if (!$definition instanceof ComplexDataDefinitionInterface) {
      return $value;
    }
    $map = new \stdClass();
    $properties = $definition->getPropertyDefinitions();
    foreach ($value as $key => $item) {
      $map->{$key} = isset($properties[$key]) ? static::documentValue($properties[$key], $item) : $item;
    }
    return $map;
  }

  /**
   * {@inheritdoc}
   */
  public function kept(DataSurfaceInterface $surface, array $values, array $current): array {
    $kept = [];
    foreach ($surface->getDefinitions()->entries() as $name => $entry) {
      $name = (string) $name;
      if (!array_key_exists($name, $values)) {
        if (array_key_exists($name, $current)) {
          $kept[$name] = $current[$name];
        }
        continue;
      }
      $kept[$name] = $values[$name];
      if (!is_array($values[$name]) || !is_array($current[$name] ?? NULL) || $entry->list !== NULL) {
        continue;
      }
      $child = $entry->childFor($values);
      $kept[$name] = $child !== NULL
        ? $this->kept($child, $values[$name], $current[$name])
        : static::keptInMap($entry->definition, $values[$name], $current[$name]);
    }
    return $kept + $values;
  }

  /**
   * Puts back what storage holds under a map's properties left out.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The map, as declared.
   * @param array $value
   *   The accepted map.
   * @param array $stored
   *   What storage holds for it.
   *
   * @return array
   *   The map to write.
   */
  protected static function keptInMap(DataDefinitionInterface $definition, array $value, array $stored): array {
    if (!$definition instanceof ComplexDataDefinitionInterface || $definition instanceof ListDataDefinitionInterface) {
      return $value;
    }
    $kept = [];
    foreach ($definition->getPropertyDefinitions() as $property => $property_definition) {
      if (!array_key_exists($property, $value)) {
        if (array_key_exists($property, $stored)) {
          $kept[$property] = $stored[$property];
        }
        continue;
      }
      $kept[$property] = is_array($value[$property]) && is_array($stored[$property] ?? NULL)
        ? static::keptInMap($property_definition, $value[$property], $stored[$property])
        : $value[$property];
    }
    return $kept + $value;
  }

  /**
   * Accepts the value of one key.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface the key belongs to.
   * @param \Drupal\data_surface\Entry $entry
   *   The key.
   * @param array $input
   *   The raw input for the key's level.
   * @param array $current
   *   What the key's level already holds.
   * @param string $prefix
   *   The dotted path of the key's level.
   * @param array $values
   *   The values accepted at this level so far, which is where a slot
   *   reads its deciding key.
   * @param bool $open
   *   TRUE to read the input as though the key were not locked: what a
   *   value sent for a locked key would have been, to tell whether it
   *   was taken.
   *
   * @return mixed
   *   The accepted value.
   */
  protected function acceptKey(DataSurfaceInterface $surface, Entry $entry, array $input, array $current, string $prefix, array $values, bool $open = FALSE): mixed {
    $name = $entry->name;
    $path = static::joinPath($prefix, $name);
    if ($entry->locked && !$open) {
      // A locked key's value space is narrowed to exactly one value, so
      // input cannot move it: it holds what it holds, read the way a key
      // the input leaves out is. That one value is whatever storage
      // holds; the declared default is only the starting point for a
      // key storage has never held. A locked child (its access answered
      // locked) is read the way an unanswered one is, so a stored value
      // missing a key the child added since still holds the whole shape.
      // A value sent for it that is not what it holds is not refused,
      // and the caller is told it was not taken.
      // Decision: see docs/decisions.md#locked-at-the-top.
      $kept = $this->acceptKey($surface, $entry, [], $current, $prefix, $values, TRUE);
      if (array_key_exists($name, $input) && !$this->takes($surface, $entry, $input, $current, $prefix, $values, $kept)) {
        [$key, $below] = array_pad(explode('.', $path, 2), 2, '');
        $this->notTaken[$path] = new Violation($key, $below, $this->t('@label is locked and kept as stored; the value sent was not taken.', [
          '@label' => $entry->definition->getLabel() ?? $name,
        ]));
      }
      return $kept;
    }
    // What the key already holds: the stored value when storage has
    // one, and the declared default only when it has none. A stored
    // NULL is a stored value, so array_key_exists rather than ??.
    $fallback = array_key_exists($name, $current) ? $current[$name] : $surface->getDefault($name);
    if ($fallback === NULL && DefinitionMetadata::isGrouping($entry->definition)) {
      // The map each module's mounted keys sit in is a map, never null,
      // even when nothing is stored and no mounted key has a default.
      // Decision: see docs/decisions.md#a-key-that-does-not-apply.
      $fallback = [];
    }
    $chosen = $entry->slot?->chosen($values[$entry->slot->by] ?? NULL);
    if ($chosen !== NULL && !$entry->slot->fits($chosen, $fallback)) {
      // What the slot held was written for another variant, and the
      // deciding key has moved on: it starts again from the chosen
      // variant's defaults rather than carrying keys that mean nothing
      // here.
      $fallback = $entry->slot->defaultsOf($chosen);
    }
    if ($entry->list !== NULL) {
      return $this->acceptCollection($entry, $entry->list, $input, $fallback, $path);
    }
    $child = $entry->childFor($values);
    if (!array_key_exists($name, $input)) {
      // A key the input leaves out keeps what it holds; null or '' sent
      // for it is something said, and clears an optional key.
      // Decision: see docs/decisions.md#absent-keeps-null-clears.
      // A child the input says nothing about still holds a whole value
      // of the child's shape, the child's defaults beneath what the
      // level already holds, so a stored value missing a key the child
      // added since reads the way a fresh one would.
      return $child !== NULL && is_array($fallback)
        ? $this->acceptLevel($child, [], $fallback, $path)
        : $fallback;
    }
    if ($child !== NULL) {
      return $this->acceptChild($entry, $child, $input[$name], $fallback, $path, $chosen);
    }
    return DefinitionMetadata::isSecret($entry->definition)
      ? $this->acceptSecret($entry->definition, $input[$name], $fallback, $path)
      : $this->acceptValue($entry->definition, $input[$name], $fallback, $path);
  }

  /**
   * Answers whether what was sent for a locked key is what it keeps.
   *
   * The value sent is read as though the key were open, and so is what
   * it keeps, so a form's "1" and a stored TRUE are one value, and a map
   * sent with fewer keys than it holds, all of them as held, is too. A
   * value that could not be held at all was not taken. A secret is never
   * compared: anything sent for one was not taken.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface the key belongs to.
   * @param \Drupal\data_surface\Entry $entry
   *   The locked key.
   * @param array $input
   *   The raw input for the key's level, which holds the key.
   * @param array $current
   *   What the key's level already holds.
   * @param string $prefix
   *   The dotted path of the key's level.
   * @param array $values
   *   The values accepted at this level so far.
   * @param mixed $kept
   *   What the key keeps.
   *
   * @return bool
   *   TRUE when the value sent is what the key keeps.
   */
  protected function takes(DataSurfaceInterface $surface, Entry $entry, array $input, array $current, string $prefix, array $values, mixed $kept): bool {
    if (DefinitionMetadata::isSecret($entry->definition)) {
      return !ValueState::isConfigured($input[$entry->name]);
    }
    // A locked key inside the one being compared is this key's matter,
    // not its own: what the comparison finds there is not kept.
    $noted = $this->notTaken;
    try {
      $sent = $this->acceptKey($surface, $entry, $input, $current, $prefix, $values, TRUE);
      $held = $this->acceptKey($surface, $entry, [$entry->name => $kept], $current, $prefix, $values, TRUE);
      // A secret inside is never compared either: whether a note is said
      // must not tell a caller what the secret holds. Anything sent for
      // one was not taken.
      // Decision: see
      // docs/decisions.md#every-door-projects-what-the-read-tool-projects.
      $secrets = Outbound::secretsAt($entry);
      if (is_array($secrets) && $secrets !== []) {
        $tree = [$entry->name => $secrets];
        $raw = is_array($input[$entry->name]) ? Outbound::onlySecrets([$entry->name => $input[$entry->name]], $tree) : [];
        $said = FALSE;
        array_walk_recursive($raw, static function (mixed $value) use (&$said): void {
          $said = $said || ValueState::isConfigured($value);
        });
        if ($said) {
          return FALSE;
        }
        $sent = Outbound::withoutSecrets([$entry->name => $sent], $tree)[$entry->name] ?? NULL;
        $held = Outbound::withoutSecrets([$entry->name => $held], $tree)[$entry->name] ?? NULL;
      }
      return static::sameValue($sent, $held);
    }
    catch (\InvalidArgumentException) {
      return FALSE;
    }
    finally {
      $this->notTaken = $noted;
    }
  }

  /**
   * Compares two accepted values, a map's keys in any order.
   *
   * @param mixed $a
   *   One value.
   * @param mixed $b
   *   The other.
   *
   * @return bool
   *   Whether they hold the same, type for type.
   */
  protected static function sameValue(mixed $a, mixed $b): bool {
    if (!is_array($a) || !is_array($b)) {
      return $a === $b;
    }
    if (count($a) !== count($b)) {
      return FALSE;
    }
    foreach ($a as $key => $value) {
      if (!array_key_exists($key, $b) || !static::sameValue($value, $b[$key])) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Accepts a collection's value: a list of items, each the child's.
   *
   * A list the input says nothing about holds what the level already
   * holds, each item read through the child the way an
   * unanswered child is, so a stored item missing a key the child added
   * since reads the way a fresh one would; a locked list is read so too,
   * as any locked key is (acceptKey()). Otherwise the list is the input, in
   * the input's order, its keys dropped: delta is position. An item that
   * holds nothing at all, a row a form added and left blank, is dropped,
   * the way core drops an empty delta, once its keys are found to be the
   * child's (an unknown key in it is refused); every other item is
   * accepted by the child in its own frame, over the child's defaults and
   * not over what is stored at the same position, since the list is sent
   * whole and an item may have moved.
   *
   * Except a secret, which no read returns and so no caller can send
   * back: an item that leaves one out keeps what is stored for it at the
   * item's position, as a key left out keeps what it holds, when the list
   * sent is as long as the one stored, an edit in place. In a list sent
   * with items added or removed a position may hold another item now, so
   * a secret stored there is neither kept, which could hand it to another
   * item, nor erased: the run is refused by the secret's path.
   *
   * Decision: see docs/decisions.md#empty-items-are-dropped, and
   * docs/decisions.md#every-door-projects-what-the-read-tool-projects.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The collection.
   * @param \Drupal\data_surface\Collection $list
   *   The child every item is.
   * @param array $input
   *   The raw input for the collection's level.
   * @param mixed $fallback
   *   What the collection already holds: the stored list, or the declared
   *   default.
   * @param string $path
   *   The dotted path of the collection.
   *
   * @return mixed
   *   The accepted list, NULL for a list that holds nothing, or what the
   *   level held when the input said nothing.
   *
   * @throws \Drupal\data_surface\Pipeline\UnknownKeysException
   *   When an item carries a key the child does not declare.
   * @throws \Drupal\data_surface\Pipeline\TypeMismatchException
   *   When the input is not a list, or an item is not a map.
   */
  protected function acceptCollection(Entry $entry, Collection $list, array $input, mixed $fallback, string $path): mixed {
    $child = $list->child();
    $raw = $input[$entry->name] ?? NULL;
    if (!array_key_exists($entry->name, $input) || !ValueState::isConfigured($raw)) {
      // As for a plain list: input that holds nothing says nothing, and
      // what the list already holds stands. Emptying it is said with an
      // empty list.
      if (!is_array($fallback)) {
        return $fallback;
      }
      $held = [];
      foreach (array_values($fallback) as $delta => $item) {
        $held[] = is_array($item) ? $this->acceptLevel($child, [], $item, $path . '.' . $delta) : $item;
      }
      return $held;
    }
    // The coercion table: a JSON list read as one, and a single item map
    // as a list of it.
    $raw = Coercion::decoded($raw, TRUE);
    if (is_array($raw) && static::isItem($raw, $child->getDefinitions()->names())) {
      $raw = [$raw];
    }
    if (!is_array($raw)) {
      throw new TypeMismatchException($path, 'list', get_debug_type($raw));
    }
    $sent = [];
    foreach (array_values($raw) as $item) {
      $item = Coercion::decoded($item, FALSE);
      $at = $path . '.' . count($sent);
      if (Collection::isEmptyItem($item)) {
        // A blank row is dropped, but its keys are the child's first: an
        // unknown key in it is refused by its path, as in any other item.
        if (is_array($item)) {
          $this->acceptLevel($child, $item, [], $at);
        }
        continue;
      }
      if (!is_array($item)) {
        throw new TypeMismatchException($at, 'map', get_debug_type($item));
      }
      $sent[] = $item;
    }
    $secrets = Outbound::secrets($child);
    $stored = is_array($fallback) ? array_values($fallback) : [];
    $in_place = count($sent) === count($stored);
    $items = [];
    foreach ($sent as $delta => $item) {
      $at = $path . '.' . $delta;
      $held = $secrets !== [] && is_array($stored[$delta] ?? NULL) ? $stored[$delta] : [];
      if ($held !== [] && !$in_place) {
        foreach (Outbound::leftOutSecrets($secrets, $item, $held) as $parents) {
          $this->unmatched[$at . '.' . implode('.', $parents)] = $this->unmatchedSecret($child, $at, $parents);
        }
      }
      $items[] = $this->acceptLevel($child, $item, $in_place ? Outbound::onlySecrets($held, $secrets) : [], $at);
    }
    return $items;
  }

  /**
   * Says that a collection item would erase a secret stored at its place.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $child
   *   The child every item is.
   * @param string $at
   *   The dotted path of the item.
   * @param list<string> $parents
   *   The secret's parents inside the item.
   *
   * @return \Drupal\data_surface\Pipeline\Violation
   *   The refusal, filed under the secret's full path.
   */
  protected function unmatchedSecret(DataSurfaceInterface $child, string $at, array $parents): Violation {
    [$key, $below] = array_pad(explode('.', $at . '.' . implode('.', $parents), 2), 2, '');
    return new Violation($key, $below, $this->t('@label is stored for the item at this position, and items are matched to what is stored by position, which items added or removed have moved: send it again, or the clear marker to remove it.', [
      '@label' => static::definitionAt($child, $parents)?->getLabel() ?? end($parents),
    ]));
  }

  /**
   * Accepts a child's value through the built child describing it.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The attached or slot key.
   * @param \Drupal\data_surface\DataSurfaceInterface $child
   *   The child answering for it: the attached one, or the chosen
   *   variant.
   * @param mixed $input
   *   The raw input for the key.
   * @param mixed $fallback
   *   What the key already holds.
   * @param string $path
   *   The dotted path of the key.
   * @param string|null $chosen
   *   The chosen variant of a slot; NULL for an attached child.
   *
   * @return array
   *   The accepted map.
   *
   * @throws \Drupal\data_surface\Pipeline\TypeMismatchException
   *   When the input is not a map.
   * @throws \Drupal\data_surface\Pipeline\VariantMismatchException
   *   When the input carries another variant's keys.
   */
  protected function acceptChild(Entry $entry, DataSurfaceInterface $child, mixed $input, mixed $fallback, string $path, ?string $chosen): array {
    if (!ValueState::isConfigured($input)) {
      // Nothing sent for a child is nothing sent for each of its keys: an
      // optional one is cleared, a required one is refused in its own
      // words, and the document stays a map the child's def describes.
      // Decision: see docs/decisions.md#absent-keeps-null-clears.
      $input = array_fill_keys($child->getDefinitions()->names(), NULL);
    }
    $input = Coercion::decoded($input, FALSE);
    if (!is_array($input)) {
      throw new TypeMismatchException($path, 'map', get_debug_type($input));
    }
    if ($entry->slot !== NULL && $chosen !== NULL) {
      $owners = $entry->slot->owners($entry->slot->strangers($chosen, $input), $chosen);
      if ($owners !== []) {
        throw new VariantMismatchException($path, $entry->slot->by, $chosen, $owners);
      }
    }
    return $this->acceptLevel($child, $input, is_array($fallback) ? $fallback : [], $path);
  }

  /**
   * Accepts one value for a key the data surface declared secret.
   *
   * The one documented exception to the rule that input holding nothing
   * means the key holds nothing: here it means the stored value stands,
   * whether or not the key is required. A generated form can never echo
   * a stored secret — that is what being secret means — so the box it
   * renders comes up empty every time, and somebody who changes the
   * setting beside it and saves has submitted an empty secret without
   * meaning to say anything about it at all.
   *
   * Which leaves no way to remove a secret, so there is one:
   * DataSurfacePipelineInterface::CLEAR_SECRET, sent as the value, means
   * the key holds nothing from now on. Clearing is an explicit act, said
   * out loud, by a checkbox a host renders beside the box or by an API
   * caller that names the marker.
   *
   * Everything else about a secret is ordinary: the value is coerced
   * through the same table as any other string, and validation holds it
   * to the same constraints.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition the value belongs to.
   * @param mixed $input
   *   The raw input value.
   * @param mixed $fallback
   *   What this key already holds: the stored value, or the declared
   *   default.
   * @param string $path
   *   The dotted path of this value, for refusal reporting.
   *
   * @return mixed
   *   The accepted value: the new secret, the fallback when the input
   *   said nothing, or NULL when the input asked for it to be cleared.
   *
   * @throws \Drupal\data_surface\Pipeline\TypeMismatchException
   *   When the input carries a value the definition cannot hold.
   */
  protected function acceptSecret(DataDefinitionInterface $definition, mixed $input, mixed $fallback, string $path): mixed {
    if ($input === self::CLEAR_SECRET) {
      return NULL;
    }
    if (!ValueState::isConfigured($input)) {
      return $fallback;
    }
    return $this->acceptValue($definition, $input, $fallback, $path);
  }

  /**
   * {@inheritdoc}
   */
  public function validate(DataSurfaceInterface $surface, array $values, array $current = []): ViolationSet {
    return new ViolationSet($this->validateLevel($surface, $values, $current));
  }

  /**
   * Validates one data surface's values, at the top or inside a child.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface describing this level.
   * @param array $values
   *   The accepted values of this level.
   * @param array $current
   *   What this level holds in storage, for the stale rule.
   *
   * @return \Drupal\data_surface\Pipeline\Violation[]
   *   The violations, filed under this level's keys.
   */
  protected function validateLevel(DataSurfaceInterface $surface, array $values, array $current): array {
    $refined = $surface->refine($values);
    $errors = [];
    foreach ($refined->getDefinitions()->entries() as $name => $entry) {
      $name = (string) $name;
      $definition = $entry->definition;
      $value = $values[$name] ?? NULL;
      if (DoesNotApply::marks($definition)) {
        // Nothing about a key that does not apply is required or held to
        // a rule; a value sent for it other than what it holds is refused.
        // Decision: see docs/decisions.md#a-key-that-does-not-apply.
        if (array_key_exists($name, $values) && ValueState::isConfigured($value) && !(array_key_exists($name, $current) && $current[$name] == $value)) {
          $errors[] = new Violation($name, '', $this->doesNotApply($surface, $refined, $name, '', $values));
        }
        continue;
      }
      $ruled_out = DoesNotApply::inside($definition);
      if ($ruled_out !== []) {
        foreach ($ruled_out as $path) {
          $parents = explode('.', $path);
          $held = is_array($value) ? NestedArray::getValue($value, $parents) : NULL;
          $stored = is_array($current[$name] ?? NULL) && NestedArray::keyExists($current[$name], $parents) ? NestedArray::getValue($current[$name], $parents) : NULL;
          if (ValueState::isConfigured($held) && $held != $stored) {
            $errors[] = new Violation($name, $path, $this->doesNotApply($surface, $refined, $name, $path, $values));
          }
        }
        $definition = static::withoutRuledOut($definition);
        $value = is_array($value) ? static::valueWithout($value, $ruled_out) : $value;
      }
      // Decision: see docs/decisions.md#required-inside-a-plain-map.
      $missing = $entry->attachment === NULL && $entry->slot === NULL
        ? $this->missingInMap($definition, is_array($value) ? $value : [])
        : [];
      if (!ValueState::isConfigured($value)) {
        // Not configured: nothing to hold to a constraint, and for a
        // required key the data surface's own message rather than whichever
        // type-specific one a constraint would have produced. A key that
        // was never set is this case whether it is required or not, and
        // it is never stale: there is no value to keep.
        if ($definition->isRequired()) {
          $errors[] = new Violation($name, '', $this->isRequired($surface, $refined, $name, '', $definition->getLabel() ?? $name, $values));
        }
        elseif (DefinitionMetadata::isAlwaysHeld($definition)) {
          // Null clears where clearing is possible; here the storage
          // holds a value whatever is sent, so null is no answer.
          // Decision: see docs/decisions.md#a-key-its-storage-always-holds.
          $errors[] = new Violation($name, '', $this->cannotBeCleared($definition, $name));
        }
        foreach ($missing as $path => $label) {
          $errors[] = new Violation($name, $path, $this->isRequired($surface, $refined, $name, $path, $label, $values));
        }
        continue;
      }
      if ($entry->list !== NULL) {
        $errors = array_merge($errors, $this->validateCollection($entry, $entry->list, $value, $current[$name] ?? NULL));
        continue;
      }
      $child = $entry->childFor($values);
      if ($child !== NULL) {
        $errors = array_merge($errors, $this->validateChild($entry, $child, $value, $current[$name] ?? [], $values));
        continue;
      }
      if ($entry->slot !== NULL) {
        // A slot holding a value with no variant to read it by. When the
        // deciding key holds a value that names none, that key's own
        // Choice says so; when it holds nothing, this does.
        if (!ValueState::isConfigured($values[$entry->slot->by] ?? NULL)) {
          $errors[] = new Violation($name, '', $this->t('@label depends on @by, which holds no value.', [
            '@label' => $definition->getLabel() ?? $name,
            '@by' => $refined->getDefinition($entry->slot->by)?->getLabel() ?? $entry->slot->by,
          ]));
        }
        continue;
      }
      if (($definition instanceof ListDataDefinition || $definition instanceof MapDataDefinition) && is_array($value) && $definition->isRequired()) {
        // Required means configured, and the empty list is: what all
        // checkboxes unchecked, or every row removed, says. So is a map
        // whose keys hold nothing, whose required keys each say so in
        // their own words ($missing), as a child's do. Core's NotNull
        // reads an empty list, or a map of nothing, as null, so it is not
        // asked here; the data surface asked whether the key holds a
        // value, above.
        // Decision: see docs/decisions.md#required-means-must-hold-a-value.
        $definition = (clone $definition)->setRequired(FALSE);
      }
      $typed_data = DefinitionTypedData::create($this->typedDataManager, $definition, $value, $name);
      $refusals = [];
      $choices = [];
      foreach ($typed_data->validate() as $violation) {
        $path = (string) $violation->getPropertyPath();
        if (isset($missing[$path])) {
          // Said below, in the data surface's own words.
          continue;
        }
        // The message stays the object the constraint built. Flattening
        // it here would render its placeholders once, as plain text, and
        // whatever reads the violation afterwards would escape that text
        // a second time; a form error, a tool result and a log line each
        // render it themselves, at their own boundary. A Symfony
        // placeholder core's translator leaves in it is filled in then.
        if ($violation->getConstraint() instanceof Choice) {
          // A value not on a list: the refusal names the list.
          $choices[count($refusals)] = TRUE;
        }
        $refusals[] = new Violation($name, $path, ViolationMessage::of($violation));
      }
      foreach ($missing as $path => $label) {
        // What a rule made required is said below, with the rest.
        $refusals[] = new Violation($name, $path, $this->t('@label is required.', ['@label' => $label]));
      }
      if ($refusals !== [] && $this->isStale($definition, $value, $name, $current, $refined->getDefinitions()->dependencies($name), $values)) {
        // The whole key is reported as stale and not re-judged. What
        // else its constraints would say is about a value this run is
        // not changing and could not have chosen — it is what storage
        // holds — and saying it would read as a list of things to fix
        // where there is exactly one: choose again.
        $errors[] = $this->staleViolation($definition, $value, $name);
        continue;
      }
      $errors = array_merge($errors, $this->sayingWhatIsAllowed($refusals, $surface, $refined, $name, $values, $choices));
    }
    return $errors;
  }

  /**
   * Says in each refusal of a key what is allowed for the values sent.
   *
   * One sentence per refusal, so a caller fixes the key in one step:
   *
   * - A refusal a refiner caused, such as too few stewards for the
   *   capacity given, is about a value the caller may not have touched:
   *   the key it names is the one to fix, but the reason is a sibling.
   *   When the refiners changed the refused key's constraints for these
   *   values, the refusal goes on to name each key they watch and what it
   *   holds, as a key that does not apply is said ("This value should be
   *   3 or more when Capacity is 120."). A refusal by a rule the key
   *   declared itself says no condition. A key an alter mounted is
   *   refined one property at a time, so its refusals are matched to the
   *   property each is about.
   * - A value not on its key's list goes on to list what may be chosen,
   *   as refined for these values, each label with its value, so the
   *   sentence says what to send ("...; choose Main hall (riverside_main)
   *   or East room (riverside_east)."), capped at CHOICES_SHOWN.
   *
   * Decision: see
   * docs/decisions.md#a-refusal-names-what-it-depends-on.
   *
   * @param \Drupal\data_surface\Pipeline\Violation[] $refusals
   *   The key's refusals.
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface as declared.
   * @param \Drupal\data_surface\DataSurfaceInterface $refined
   *   The same, refined against the values.
   * @param string $name
   *   The key.
   * @param array $values
   *   The values of the key's level.
   * @param array<int, true> $choices
   *   The refusals, by index, that a list of allowed values made.
   *
   * @return \Drupal\data_surface\Pipeline\Violation[]
   *   The refusals, each saying what is allowed where that applies.
   */
  protected function sayingWhatIsAllowed(array $refusals, DataSurfaceInterface $surface, DataSurfaceInterface $refined, string $name, array $values, array $choices): array {
    $entry = $refined->getDefinitions()->entry($name);
    if ($refusals === [] || $entry === NULL) {
      return $refusals;
    }
    $targets = $entry->dependencies === [] ? [] : ($entry->paths === [] ? ['' => $entry->dependencies] : $entry->paths);
    foreach ($refusals as $index => $refusal) {
      $conditions = [];
      foreach ($targets as $path => $dependencies) {
        $path = (string) $path;
        if ($path !== '' && $refusal->path !== $path && !str_starts_with($refusal->path, $path . '.')) {
          continue;
        }
        $parents = $path === '' ? [$name] : [$name, ...explode('.', $path)];
        $declared = static::definitionAt($surface, $parents);
        $narrowed = static::definitionAt($refined, $parents);
        if ($declared === NULL || $narrowed === NULL || json_encode($declared->getConstraints()) === json_encode($narrowed->getConstraints())) {
          continue;
        }
        $conditions = $this->conditions($refined, $dependencies, $values, $entry);
        break;
      }
      $allowed = isset($choices[$index]) ? $this->choices($refined, $name, $refusal->path) : NULL;
      if ($conditions === [] && $allowed === NULL) {
        continue;
      }
      $arguments = [
        '@message' => new Clause($refusal->message),
        '@conditions' => implode(' ' . $this->t('and') . ' ', array_map('strval', $conditions)),
        '@choices' => (string) $allowed,
      ];
      $message = match (TRUE) {
        $allowed === NULL => $this->t('@message when @conditions.', $arguments),
        $conditions === [] => $this->t('@message; @choices.', $arguments),
        default => $this->t('@message when @conditions; @choices.', $arguments),
      };
      $refusals[$index] = new Violation($refusal->key, $refusal->path, $message);
    }
    return $refusals;
  }

  /**
   * Says what each watched key holds, as the condition of a refusal.
   *
   * A key only a declared rule reads, and only by whether it holds a
   * value (`present()`, `absent()`), is said as set or empty, never by
   * what it holds: the rule asked nothing else, and "when Email is
   * digest@example.com" reads as though only that address counted.
   *
   * Decision: see
   * docs/decisions.md#a-refusal-names-what-it-depends-on.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $refined
   *   The data surface, refined against the values.
   * @param string[] $dependencies
   *   The watched keys, a mounted key by its dotted path, a port by its
   *   name.
   * @param array $values
   *   The values of the level.
   * @param \Drupal\data_surface\Entry|null $entry
   *   The refused key, whose declared rules say which watched keys are
   *   read for presence alone.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   "Venue is Riverside Hall", one per watched key, an option by its
   *   label; "Email is set" for a key read for presence alone.
   */
  protected function conditions(DataSurfaceInterface $refined, array $dependencies, array $values, ?Entry $entry = NULL): array {
    $conditions = [];
    $presence = $entry?->presenceDependencies() ?? [];
    foreach ($dependencies as $dependency) {
      $at = explode('.', $dependency);
      $watched = static::definitionAt($refined, $at);
      $held = NestedArray::getValue($values, $at) ?? $refined->getPortValues()[$dependency] ?? NULL;
      $set = $watched === NULL || !is_scalar($held) ? NULL : $this->options->resolve($watched);
      $shown = match (TRUE) {
        !ValueState::isConfigured($held) => $this->t('empty'),
        in_array($dependency, $presence, TRUE) => $this->t('set'),
        $set !== NULL && isset($set->options[$held]) => $set->options[$held],
        is_bool($held) => $held ? $this->t('on') : $this->t('off'),
        is_scalar($held) => (string) $held,
        default => $this->t('what it holds'),
      };
      $conditions[] = $this->t('@key is @value', ['@key' => $watched?->getLabel() ?? $dependency, '@value' => $shown]);
    }
    return $conditions;
  }

  /**
   * Lists what a refused key may hold, as refined for the values sent.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $refined
   *   The data surface, refined against the values.
   * @param string $name
   *   The key.
   * @param string $path
   *   The refused property below it, or ''.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|null
   *   "choose Main hall (riverside_main) or East room (riverside_east)",
   *   at most CHOICES_SHOWN of them and how many more; "nothing may be
   *   chosen" for an empty list; NULL when the key offers no list.
   */
  protected function choices(DataSurfaceInterface $refined, string $name, string $path): ?TranslatableMarkup {
    $definition = $path === '' ? NULL : static::definitionAt($refined, [$name, ...explode('.', $path)]);
    $definition ??= $refined->getDefinition($name);
    $set = $definition === NULL ? NULL : $this->options->resolve($definition);
    if ($set === NULL) {
      return NULL;
    }
    if ($set->options === []) {
      return $this->t('nothing may be chosen');
    }
    $choices = [];
    foreach ($set->options as $value => $label) {
      $choices[] = (string) $label === (string) $value
        ? (string) $value
        : (string) $this->t('@label (@value)', ['@label' => $label, '@value' => $value]);
    }
    $shown = array_slice($choices, 0, self::CHOICES_SHOWN);
    $more = count($choices) - count($shown);
    if ($more > 0) {
      return $this->t('choose @choices and @count more', ['@choices' => implode(', ', $shown), '@count' => $more]);
    }
    $last = array_pop($shown);
    return $shown === []
      ? $this->t('choose @choice', ['@choice' => $last])
      : $this->t('choose @choices or @last', ['@choices' => implode(', ', $shown), '@last' => $last]);
  }

  /**
   * Says that a key its storage always holds cannot be cleared.
   *
   * Names the default, an option by its label, so the caller knows what
   * the key holds when it starts, and that leaving it out keeps it.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key.
   * @param string $name
   *   Its name, when it has no label.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The refusal.
   */
  protected function cannotBeCleared(DataDefinitionInterface $definition, string $name): TranslatableMarkup {
    $label = $definition->getLabel() ?? $name;
    $default = DefinitionMetadata::hasDefaultValue($definition) ? DefinitionMetadata::getDefaultValue($definition) : NULL;
    if (!is_scalar($default)) {
      return $this->t('@label cannot be cleared: it always holds a value. Leave it out to keep what it holds.', ['@label' => $label]);
    }
    $set = $this->options->resolve($definition);
    $value = is_bool($default) ? (int) $default : $default;
    $shown = match (TRUE) {
      $set !== NULL && isset($set->options[$value]) => $this->t('@label (@value)', [
        '@label' => $set->options[$value],
        '@value' => $value,
      ]),
      is_bool($default) => $default ? $this->t('on') : $this->t('off'),
      default => (string) $default,
    };
    return $this->t('@label cannot be cleared: it always holds a value, @default by default. Leave it out to keep what it holds.', [
      '@label' => $label,
      '@default' => $shown,
    ]);
  }

  /**
   * Says that a key is required, and, when a rule made it so, why.
   *
   * A key declared required says so alone ("Phone is required."). One a
   * refiner or a declared rule made required for these values goes on
   * to name each key the rule watches and what it holds, as a refused
   * constraint does: "Phone is required when Maximum visitors is 80." A
   * port is named by the container's key it reads.
   *
   * Decision: see
   * docs/decisions.md#a-refusal-names-what-it-depends-on.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface as declared.
   * @param \Drupal\data_surface\DataSurfaceInterface $refined
   *   The same, refined against the values.
   * @param string $name
   *   The key.
   * @param string $path
   *   The property path below it, for a required property of a map; ''
   *   for the key itself.
   * @param string|\Stringable $label
   *   The label of what is required.
   * @param array $values
   *   The values of the key's level.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The refusal.
   */
  protected function isRequired(DataSurfaceInterface $surface, DataSurfaceInterface $refined, string $name, string $path, string|\Stringable $label, array $values): TranslatableMarkup {
    $parents = $path === '' ? [$name] : [$name, ...explode('.', $path)];
    $declared = static::definitionAt($surface, $parents);
    $entry = $refined->getDefinitions()->entry($name);
    $dependencies = $path === '' ? ($entry->dependencies ?? []) : ($entry->paths[$path] ?? $entry->dependencies ?? []);
    if ($entry?->slot !== NULL) {
      $dependencies = array_values(array_diff($dependencies, [$entry->slot->by]));
    }
    $conditions = $declared === NULL || $declared->isRequired() ? [] : $this->conditions($refined, $dependencies, $values, $entry);
    if ($conditions === []) {
      return $this->t('@label is required.', ['@label' => $label]);
    }
    return $this->t('@label is required when @conditions.', [
      '@label' => $label,
      '@conditions' => implode(' ' . $this->t('and') . ' ', array_map('strval', $conditions)),
    ]);
  }

  /**
   * Says that a key does not apply, and what it depends on.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface as declared.
   * @param \Drupal\data_surface\DataSurfaceInterface $refined
   *   The same, refined against the values.
   * @param string $name
   *   The key.
   * @param string $path
   *   The property path below it, for a key an alter mounted; '' for the
   *   key itself.
   * @param array $values
   *   The values of the key's level.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   "Send day does not apply when Frequency is Monthly."
   */
  protected function doesNotApply(DataSurfaceInterface $surface, DataSurfaceInterface $refined, string $name, string $path, array $values): TranslatableMarkup {
    $parents = $path === '' ? [$name] : [$name, ...explode('.', $path)];
    $entry = $refined->getDefinitions()->entry($name);
    $dependencies = $path === '' ? ($entry->dependencies ?? []) : ($entry->paths[$path] ?? $entry->dependencies ?? []);
    if ($entry?->slot !== NULL) {
      // What chooses a slot's variant is not what rules the slot out.
      $dependencies = array_values(array_diff($dependencies, [$entry->slot->by]));
    }
    $label = static::definitionAt($surface, $parents)?->getLabel() ?? end($parents);
    $conditions = $this->conditions($refined, $dependencies, $values, $entry);
    if ($conditions === []) {
      return $this->t('@label does not apply.', ['@label' => $label]);
    }
    return $this->t('@label does not apply when @conditions.', [
      '@label' => $label,
      '@conditions' => implode(' ' . $this->t('and') . ' ', array_map('strval', $conditions)),
    ]);
  }

  /**
   * Copies a map's definition without the properties ruled out.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The map, refined.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   A copy holding only what applies.
   */
  protected static function withoutRuledOut(DataDefinitionInterface $definition): DataDefinitionInterface {
    if (!$definition instanceof MapDataDefinition) {
      return $definition;
    }
    $copy = clone $definition;
    foreach ($copy->getPropertyDefinitions() as $property => $property_definition) {
      $copy->setPropertyDefinition((string) $property, DoesNotApply::marks($property_definition) ? NULL : static::withoutRuledOut($property_definition));
    }
    return $copy;
  }

  /**
   * Copies a map's value without the properties at some paths.
   *
   * @param array $value
   *   The map.
   * @param string[] $paths
   *   Dotted paths below it.
   *
   * @return array
   *   The map less those properties.
   */
  protected static function valueWithout(array $value, array $paths): array {
    foreach ($paths as $path) {
      NestedArray::unsetValue($value, explode('.', $path));
    }
    return $value;
  }

  /**
   * Finds the definition at a key, or at a property path inside it.
   *
   * A port is read as the container's key it is wired to.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface.
   * @param string[] $parents
   *   The key, then the property names below it; or a port.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The definition, or NULL when the path names none.
   */
  protected static function definitionAt(DataSurfaceInterface $surface, array $parents): ?DataDefinitionInterface {
    if (count($parents) === 1 && $surface->getDefinition((string) $parents[0]) === NULL) {
      // A port: the container's key it reads.
      return $surface->getPortDefinition((string) $parents[0]);
    }
    $definition = $surface->getDefinition((string) array_shift($parents));
    foreach ($parents as $property) {
      $definition = $definition instanceof ComplexDataDefinitionInterface ? $definition->getPropertyDefinition($property) : NULL;
    }
    return $definition;
  }

  /**
   * Finds the required properties of a plain map that hold no value.
   *
   * A plain map is not a child, so no child judges its keys; without
   * this, a required property inside one would be held only to typed
   * data's NotNull, which an empty string passes and an absent map never
   * reaches. The data surface's rule for a key, applied to the map's own
   * properties: required and not configured is refused, with the same
   * message. A mounted key an alter made required is the case it is for.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition.
   * @param array $value
   *   The key's value, or an empty array when it holds none.
   * @param string $prefix
   *   The property path so far.
   *
   * @return array<string, string|\Stringable>
   *   The labels of the missing properties, keyed by property path.
   */
  protected function missingInMap(DataDefinitionInterface $definition, array $value, string $prefix = ''): array {
    if (!$definition instanceof ComplexDataDefinitionInterface || $definition instanceof ListDataDefinitionInterface) {
      return [];
    }
    $missing = [];
    foreach ($definition->getPropertyDefinitions() as $property => $property_definition) {
      $path = $prefix . $property;
      $held = $value[$property] ?? NULL;
      if ($property_definition instanceof ComplexDataDefinitionInterface) {
        $missing += $this->missingInMap($property_definition, is_array($held) ? $held : [], $path . '.');
        continue;
      }
      if ($property_definition->isRequired() && !ValueState::isConfigured($held)) {
        $missing[$path] = $property_definition->getLabel() ?? $property;
      }
    }
    return $missing;
  }

  /**
   * Validates a child's value through the built child describing it.
   *
   * The child judges its own keys by its own rules — the required
   * message, the stale rule, its refiners, in its own frame — and every
   * violation it reports is filed back under the parent's key with the
   * child's key at the front of its path, so `columns` inside
   * `presentation_settings` reads as `presentation_settings.columns` to
   * whoever reads the set. Dotted paths into a child exist here and in
   * emitted schemas, and nowhere a refiner could name them.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The attached or slot key.
   * @param \Drupal\data_surface\DataSurfaceInterface $child
   *   The child answering for it, as advertised.
   * @param mixed $value
   *   The key's value, configured.
   * @param mixed $current
   *   What storage holds for the key.
   * @param array $values
   *   The values of the key's own level, for a slot's deciding key.
   *
   * @return \Drupal\data_surface\Pipeline\Violation[]
   *   The violations, filed under the key.
   */
  protected function validateChild(Entry $entry, DataSurfaceInterface $child, mixed $value, mixed $current, array $values): array {
    $name = $entry->name;
    if (!is_array($value)) {
      return [
        new Violation($name, '', $this->t('This value must be of type @expected, @actual given.', [
          '@expected' => 'map',
          '@actual' => get_debug_type($value),
        ])),
      ];
    }
    $errors = [];
    if ($entry->slot !== NULL) {
      $chosen = (string) $entry->slot->chosen($values[$entry->slot->by] ?? NULL);
      $strangers = $entry->slot->strangers($chosen, $value);
      $owners = $entry->slot->owners($strangers, $chosen);
      foreach ($strangers as $stranger) {
        $errors[] = new Violation($name, $stranger, isset($owners[$stranger])
          ? $this->variantMismatchMessage($name . '.' . $stranger, $entry->slot->by, $chosen, $owners[$stranger])
          : $this->t('Unknown key @key.', ['@key' => $name . '.' . $stranger]));
      }
      // Judged as the variant would judge it on its own: the keys it
      // declares, and nothing it does not.
      $value = array_diff_key($value, array_flip($strangers));
    }
    foreach ($this->validateLevel($child, $value, is_array($current) ? $current : []) as $violation) {
      $errors[] = new Violation($name, $violation->fullPath(), $violation->message, $violation->stale);
    }
    return $errors;
  }

  /**
   * Validates a collection: the list as the parent's, each item as the child's.
   *
   * The list's own constraints, its Count above all, are the parent's,
   * read off the key as the parent's refiners left it, and are said about
   * the list itself. Then every item is judged by the child in its own
   * frame, its own refiners run against that item alone, the stale rule
   * against what is stored at the same position, and every violation it
   * reports is filed under the list's key with the item's delta at the
   * front of its path: `tags.2.target_id`.
   *
   * Decision: see docs/decisions.md#count-is-the-parents.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The collection, refined.
   * @param \Drupal\data_surface\Collection $list
   *   The child every item is.
   * @param mixed $value
   *   The list, configured.
   * @param mixed $current
   *   What storage holds for it.
   *
   * @return \Drupal\data_surface\Pipeline\Violation[]
   *   The violations, filed under the key.
   */
  protected function validateCollection(Entry $entry, Collection $list, mixed $value, mixed $current): array {
    $name = $entry->name;
    if (!is_array($value)) {
      return [
        new Violation($name, '', $this->t('This value must be of type @expected, @actual given.', [
          '@expected' => 'list',
          '@actual' => get_debug_type($value),
        ])),
      ];
    }
    $items = array_values($value);
    // The list's own rules, apart from its items, which the child judges:
    // a list of anything, carrying the list's constraints.
    $own = ListDataDefinition::create('any');
    $own->setLabel($entry->definition->getLabel());
    foreach ($entry->definition->getConstraints() as $constraint => $options) {
      $own->addConstraint((string) $constraint, $options);
    }
    $errors = [];
    foreach (DefinitionTypedData::create($this->typedDataManager, $own, $items, $name)->validate() as $violation) {
      $errors[] = new Violation($name, (string) $violation->getPropertyPath(), ViolationMessage::of($violation));
    }
    $stored = is_array($current) ? array_values($current) : [];
    $child = $list->child();
    foreach ($items as $delta => $item) {
      if (!is_array($item)) {
        $errors[] = new Violation($name, (string) $delta, $this->t('This value must be of type @expected, @actual given.', [
          '@expected' => 'map',
          '@actual' => get_debug_type($item),
        ]));
        continue;
      }
      $held = is_array($stored[$delta] ?? NULL) ? $stored[$delta] : [];
      foreach ($this->validateLevel($child, $item, $held) as $violation) {
        $errors[] = new Violation($name, $delta . '.' . $violation->fullPath(), $violation->message, $violation->stale);
      }
    }
    return $errors;
  }

  /**
   * Says that a key belongs to a variant the deciding key did not choose.
   *
   * @param string $path
   *   The full dotted path of the misplaced key.
   * @param string $by
   *   The deciding key.
   * @param string $chosen
   *   The variant it chose.
   * @param string[] $owners
   *   The variants that do declare the key.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The message.
   */
  protected function variantMismatchMessage(string $path, string $by, string $chosen, array $owners): TranslatableMarkup {
    return $this->t('@path belongs to the @owners variant, but @by chose @chosen.', [
      '@path' => $path,
      '@owners' => implode(', ', $owners),
      '@by' => $by,
      '@chosen' => $chosen,
    ]);
  }

  /**
   * Answers whether a refused value is a stale reference.
   *
   * Four things have to be true at once, and each of them rules out a
   * case that is not stale:
   *
   * - The value is exactly what storage holds for that key. A value that
   *   differs was chosen by whoever sent it, so it is refused however
   *   far outside the list it falls. This is the whole line between
   *   "re-choose this" and "that is not a valid answer".
   * - Every key it refines against also holds exactly what storage
   *   holds. A run that moves a dependency is what narrowed the list
   *   away from the stored value — a venue changed, and the room stored
   *   under the old one is not among the new one's — so the refusal is
   *   about this run's own answers, not about the site, and it blocks.
   *   Stale is for what the site did; this is what the caller did.
   * - The key offers a list of values at all, read from the options
   *   service, which is the same list a generated select renders. A key
   *   with no list has no membership to fall outside of; whatever its
   *   constraints refused, they refused on their own terms.
   * - The value is not in that list. If it is, the refusal came from
   *   some other constraint — a length, a range — and that is an
   *   ordinary refusal of a value the key still offers.
   *
   * Lists are deliberately not covered. A multiple select's items are
   * each members of the same set, so a stale item would have to be
   * stashed and warned about per item, with a partial keep that neither
   * the widget nor this rule has a shape for. A list whose items went
   * stale is refused as it always was.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The refined definition, which is where the narrowed list lives.
   * @param mixed $value
   *   The value that was refused.
   * @param string $name
   *   The key.
   * @param array $current
   *   The stored values, in input layout.
   * @param string[] $dependencies
   *   The keys the key refines against.
   * @param array $values
   *   The values of this run, at the key's own level.
   *
   * @return bool
   *   TRUE when the refusal is a stale reference.
   */
  protected function isStale(DataDefinitionInterface $definition, mixed $value, string $name, array $current, array $dependencies, array $values): bool {
    if ($definition instanceof ListDataDefinitionInterface) {
      return FALSE;
    }
    if (!array_key_exists($name, $current) || $current[$name] !== $value) {
      return FALSE;
    }
    foreach ($dependencies as $dependency) {
      // A key an alter mounted is named by its dotted path.
      $parents = explode('.', $dependency);
      if (NestedArray::getValue($values, $parents) !== NestedArray::getValue($current, $parents)) {
        return FALSE;
      }
    }
    $set = $this->options->resolve($definition);
    return $set !== NULL && !$set->allows($value);
  }

  /**
   * Builds the entry reporting one stale reference.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The refined definition, for its label.
   * @param mixed $value
   *   The stale value, which the message names so that whoever reads it
   *   knows what is about to be kept.
   * @param string $name
   *   The key.
   *
   * @return \Drupal\data_surface\Pipeline\Violation
   *   The stale entry.
   */
  protected function staleViolation(DataDefinitionInterface $definition, mixed $value, string $name): Violation {
    return new Violation($name, '', $this->t('@label keeps the value @value, which is no longer available. Choose a new one when you can.', [
      '@label' => $definition->getLabel() ?? $name,
      '@value' => (string) $value,
    ]), TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function conformOutput(DataSurfaceInterface $surface, array $output): ViolationSet {
    $definitions = $surface->getOutputDefinitions();
    // Omitted is the producer's way of saying "this key is not emitted"
    // from inside an array literal, so it is resolved into absence
    // before anything else looks at the array.
    $output = Omitted::strip($output);
    $errors = [];
    foreach (array_keys(array_diff_key($output, $definitions->toArray())) as $unknown) {
      $errors[] = new Violation((string) $unknown, '', $this->t('Unknown output key @key.', [
        '@key' => $unknown,
      ]));
    }
    foreach ($definitions as $name => $definition) {
      $name = (string) $name;
      if (!array_key_exists($name, $output)) {
        // Absent, which is legal for an output that is not required.
        // NULL is not this case: NULL is a value and is held to the
        // definition below like any other.
        if ($definition->isRequired()) {
          $errors[] = new Violation($name, '', $this->t('@label was not emitted.', [
            '@label' => $definition->getLabel() ?? $name,
          ]));
        }
        continue;
      }
      $holdable = TRUE;
      $errors = array_merge(
        $errors,
        $this->conformStructure($definition, $output[$name], $name, '', $holdable),
      );
      if (!$holdable) {
        // The value is not in a shape the definition can hold at all, so
        // there is nothing left for typed data to say about it that the
        // shape refusal has not already said more precisely.
        continue;
      }
      $typed_data = DefinitionTypedData::create($this->typedDataManager, $definition, $output[$name], $name);
      foreach ($typed_data->validate() as $violation) {
        $errors[] = new Violation($name, (string) $violation->getPropertyPath(), ViolationMessage::of($violation));
      }
    }
    return new ViolationSet($errors);
  }

  /**
   * Checks an emitted value's shape and the keys it carries.
   *
   * The part of conformance typed data cannot do. Typed data runs the
   * constraints at every depth once a value is in the object, but it
   * will hold an array it was never told about — an extra property in a
   * map is simply ignored — and it turns a scalar handed to a list into
   * a one-item list, which is exactly the quiet coercion outputs are not
   * allowed. Both are found here, addressed by path, before the value is
   * handed over.
   *
   * NULL is left alone at every level: it is a value, and whether it is
   * acceptable is the definition's business, which typed data asks by
   * running the required flag as a constraint.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition the value is held to.
   * @param mixed $value
   *   The emitted value, already stripped of omitted keys.
   * @param string $key
   *   The top level output key every violation is filed under.
   * @param string $path
   *   The property path within that key, dotted; '' at the top.
   * @param bool $holdable
   *   Set to FALSE when the value is in a shape the definition cannot
   *   hold, so the caller knows not to hand it to typed data.
   *
   * @return \Drupal\data_surface\Pipeline\Violation[]
   *   The violations found.
   */
  protected function conformStructure(DataDefinitionInterface $definition, mixed $value, string $key, string $path, bool &$holdable): array {
    if ($value === NULL) {
      return [];
    }
    if ($definition instanceof ListDataDefinitionInterface) {
      if (!is_array($value)) {
        $holdable = FALSE;
        return [$this->outputShapeViolation($key, $path, 'list', $value)];
      }
      $errors = [];
      foreach ($value as $delta => $item) {
        $errors = array_merge($errors, $this->conformStructure(
          $definition->getItemDefinition(),
          $item,
          $key,
          static::joinPath($path, (string) $delta),
          $holdable,
        ));
      }
      return $errors;
    }
    $properties = $definition instanceof ComplexDataDefinitionInterface
      ? $definition->getPropertyDefinitions()
      : [];
    if ($properties === []) {
      $mismatch = $this->outputTypeMismatch($definition, $value, $key, $path);
      return $mismatch === NULL ? [] : [$mismatch];
    }
    if (!is_array($value)) {
      $holdable = FALSE;
      return [$this->outputShapeViolation($key, $path, 'map', $value)];
    }
    $errors = [];
    foreach (array_keys(array_diff_key($value, $properties)) as $unknown) {
      $unknown_path = static::joinPath($path, (string) $unknown);
      $errors[] = new Violation($key, $unknown_path, $this->t('Unknown output key @key.', [
        '@key' => $key . '.' . $unknown_path,
      ]));
    }
    foreach ($properties as $property => $property_definition) {
      if (array_key_exists($property, $value)) {
        $errors = array_merge($errors, $this->conformStructure(
          $property_definition,
          $value[$property],
          $key,
          static::joinPath($path, (string) $property),
          $holdable,
        ));
      }
    }
    return $errors;
  }

  /**
   * Refuses a leaf value that is not in its definition's native type.
   *
   * Core's own PrimitiveType constraint asks whether a value *could be*
   * the type — a string of digits passes as an integer, and any scalar
   * passes as a string — because on the input side that is the right
   * question: input arrives as text and the coercion table converts it.
   * An output is produced by code with the declaration in front of it,
   * nothing converts it, and a consumer reading `integer` in the emitted
   * schema will be handed whatever the producer put there. So the
   * question here is the strict one, for the types whose PHP type is
   * plain; anything else — 'any', a contributed type — is left alone,
   * because a rule it never defined would be a guess.
   *
   * An integer where a float was declared is the one widening allowed:
   * every integer is that float exactly, so nothing is lost and no
   * consumer is surprised.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition the value is held to.
   * @param mixed $value
   *   The emitted value; never NULL, which is judged by the required
   *   flag instead.
   * @param string $key
   *   The output key.
   * @param string $path
   *   The property path within it.
   *
   * @return \Drupal\data_surface\Pipeline\Violation|null
   *   The violation, or NULL when the type is right or unknown here.
   */
  protected function outputTypeMismatch(DataDefinitionInterface $definition, mixed $value, string $key, string $path): ?Violation {
    $type = $definition->getDataType();
    if (!in_array($type, self::OUTPUT_TYPES, TRUE)) {
      return NULL;
    }
    $holds = match ($type) {
      'integer' => is_int($value),
      'float' => is_float($value) || is_int($value),
      'boolean' => is_bool($value),
      default => is_string($value),
    };
    return $holds ? NULL : $this->outputShapeViolation($key, $path, $type, $value);
  }

  /**
   * Builds the violation for an emitted value of the wrong shape.
   *
   * The same sentence accept() refuses a misshapen input with, because
   * it is the same mistake read from the other side, and a caller
   * reading both reports should not have to learn two wordings.
   *
   * @param string $key
   *   The output key.
   * @param string $path
   *   The property path within it.
   * @param string $expected
   *   The shape the definition can hold.
   * @param mixed $value
   *   What arrived instead.
   *
   * @return \Drupal\data_surface\Pipeline\Violation
   *   The violation.
   */
  protected function outputShapeViolation(string $key, string $path, string $expected, mixed $value): Violation {
    return new Violation($key, $path, $this->t('This value must be of type @expected, @actual given.', [
      '@expected' => $expected,
      '@actual' => get_debug_type($value),
    ]));
  }

  /**
   * Appends one segment to a dotted property path.
   *
   * @param string $path
   *   The path so far, '' at the top of a value.
   * @param string $segment
   *   The segment to append.
   *
   * @return string
   *   The path.
   */
  protected static function joinPath(string $path, string $segment): string {
    return $path === '' ? $segment : $path . '.' . $segment;
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(DataSurfaceInterface $surface, array $values, DataSurfaceStorageInterface $storage): PreparedValues {
    $prepared = $storage->prepare($surface, $values);
    return $surface->changesNothing() ? $prepared->changingNothing() : $prepared;
  }

  /**
   * {@inheritdoc}
   */
  public function commit(PreparedValues $prepared, DataSurfaceStorageInterface $storage): void {
    // Decision: see docs/decisions.md#an-operation-states-how-it-behaves.
    if ($prepared->changesNothing) {
      throw new \LogicException('These values were prepared for an operation that changes nothing, and are not written: a read only operation stops after prepare.');
    }
    $storage->commit($prepared);
  }

  /**
   * {@inheritdoc}
   */
  public function submit(DataSurfaceInterface $surface, array $input, DataSurfaceStorageInterface $storage, bool $dry_run = FALSE, ?AccessResultInterface $access = NULL): DataSurfaceResult {
    if ($access !== NULL && $access->isForbidden()) {
      // Before the storage is touched, on purpose: a refused caller must
      // not be able to learn what storage holds by being refused, and a
      // load is a query, a cache entry and sometimes an entity build.
      // The values come back empty for the same reason.
      return new DataSurfaceResult([], $this->accessViolations($access), access: $access);
    }
    // Locked whole: shown and fixed, so nothing posted can move a key and
    // a write is refused by name rather than written back unchanged.
    // Decision: see docs/decisions.md#locked-at-the-top.
    $locked = $surface->lockedWhole();
    if ($locked !== NULL) {
      return new DataSurfaceResult([], new ViolationSet([
        new Violation(self::ACCESS_VIOLATION_KEY, '', $this->t('This may be seen and not changed, so nothing was written: @reason', ['@reason' => $locked])),
      ]), access: $access);
    }
    $current = $storage->load($surface);
    try {
      $values = $this->accept($surface, $input, $current);
    }
    catch (UnknownKeysException $e) {
      return new DataSurfaceResult($current, $this->unknownKeyViolations($e), access: $access);
    }
    catch (TypeMismatchException $e) {
      return new DataSurfaceResult($current, $this->typeMismatchViolations($e), access: $access);
    }
    catch (VariantMismatchException $e) {
      return new DataSurfaceResult($current, $this->variantMismatchViolations($e), access: $access);
    }
    // What storage holds is what the stale rule is judged against, and
    // it was loaded a moment ago for the merge, so the question costs
    // nothing extra here.
    // Blocking nothing: a value sent for a locked key was not taken, and
    // the caller is told so whatever else the run answers.
    $notes = array_values($this->notTaken);
    // Refused, not erased: a secret a collection item would lose because
    // its position may now hold another item.
    // Decision: see
    // docs/decisions.md#every-door-projects-what-the-read-tool-projects.
    if ($this->unmatched !== []) {
      return new DataSurfaceResult($values, new ViolationSet(array_values($this->unmatched)), access: $access, notes: $notes);
    }
    $violations = $this->validate($surface, $values, $current);
    if (!$violations->isEmpty()) {
      return new DataSurfaceResult($values, $violations, access: $access, notes: $notes);
    }
    try {
      $prepared = $this->prepare($surface, $this->kept($surface, $values, $current), $storage);
    }
    catch (StorageViolationsException $e) {
      return new DataSurfaceResult($values, $e->getViolations(), access: $access, notes: $notes);
    }
    // The set travels on rather than being replaced by an empty one: it
    // blocks nothing, so the run is valid and committed, and the stale
    // references it carries are how a caller — a form warning, an
    // agent's dry run — learns what to re-choose.
    // An operation that changes nothing stops where a dry run does.
    // Decision: see docs/decisions.md#an-operation-states-how-it-behaves.
    if ($dry_run || $surface->changesNothing()) {
      return new DataSurfaceResult($values, $violations, $prepared, access: $access, notes: $notes);
    }
    $this->commit($prepared, $storage);
    return new DataSurfaceResult($values, $violations, $prepared, TRUE, $access, $notes);
  }

  /**
   * Accepts one value against one definition.
   *
   * Input that holds nothing (NULL, or the empty string a form sends for
   * nothing) means the key holds nothing, NULL, for every type but a
   * list, where what the list holds stands. Configured input is then
   * placed by shape: a list normalizes to a clean list of coerced items,
   * a complex definition with declared properties merges the input over
   * what the level already holds and recurses, and everything else goes
   * through the coercion table. A complex definition that declares no
   * properties describes nothing to recurse into, so its value passes
   * through untouched.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition the value belongs to.
   * @param mixed $input
   *   The raw input value.
   * @param mixed $fallback
   *   What this key already holds: the stored value, or the declared
   *   default.
   * @param string $path
   *   The dotted path of this value, for refusal reporting.
   *
   * @return mixed
   *   The accepted value.
   *
   * @throws \Drupal\data_surface\Pipeline\UnknownKeysException
   *   When a nested input key is not a property definition.
   * @throws \Drupal\data_surface\Pipeline\TypeMismatchException
   *   When the input cannot be held by the definition at all.
   */
  protected function acceptValue(DataDefinitionInterface $definition, mixed $input, mixed $fallback, string $path): mixed {
    if ($definition instanceof ListDataDefinitionInterface) {
      return $this->acceptList($definition, $input, $fallback, $path);
    }
    if (!ValueState::isConfigured($input)) {
      return NULL;
    }
    $properties = $definition instanceof ComplexDataDefinitionInterface
      ? $definition->getPropertyDefinitions()
      : [];
    if ($properties === []) {
      return $this->coerce($definition, $input, $path);
    }
    $input = Coercion::decoded($input, FALSE);
    if (!is_array($input)) {
      throw new TypeMismatchException($path, 'map', get_debug_type($input));
    }
    $unknown = array_keys(array_diff_key($input, $properties));
    if ($unknown !== []) {
      throw new UnknownKeysException(array_map('strval', $unknown), $path);
    }
    // The result holds exactly the declared properties, in declaration
    // order: a key storage carries that no property declares is
    // storage's own business and does not round-trip through the data
    // surface. Each property merges the same way its parent did:
    // declared default, then what the level already holds, then input.
    $values = [];
    foreach ($properties as $property => $property_definition) {
      $child = is_array($fallback) && array_key_exists($property, $fallback)
        ? $fallback[$property]
        : DefinitionMetadata::defaultOf($property_definition);
      $values[$property] = array_key_exists($property, $input)
        ? $this->acceptValue($property_definition, $input[$property], $child, $path . '.' . $property)
        : $child;
    }
    return $values;
  }

  /**
   * Answers whether an array sent for a list of maps is one item.
   *
   * A list's keys are dropped, whatever a sender keyed it by, so an
   * array is one item, to be wrapped, only when it is not a list and
   * names a key the items declare.
   *
   * @param array $value
   *   The array.
   * @param array $names
   *   The keys an item declares.
   *
   * @return bool
   *   TRUE for a single item.
   */
  protected static function isItem(array $value, array $names): bool {
    return !array_is_list($value) && array_intersect_key($value, array_flip(array_map('strval', $names))) !== [];
  }

  /**
   * Accepts a list value against a list definition.
   *
   * Input reaches a list in whatever shape its sender had: a multiple
   * select submits the chosen values keyed by themselves, a payload
   * sends a plain list or a JSON string of one, a lenient caller sends
   * one bare item, and a form leaves empty entries behind. All of them
   * mean the same list of items, so a JSON list is read, a single item
   * (a scalar, or one map where the items are maps) is wrapped, the keys
   * are dropped, the not-configured entries with them, and what survives
   * is coerced through the item definition, recursing when the items
   * are complex.
   *
   * Input that holds nothing does not empty a list: a form that renders
   * no list widget, or a payload that names no list, has said nothing
   * about the list, so what the list already holds stands. Emptying it
   * is said with an empty list.
   *
   * @param \Drupal\Core\TypedData\ListDataDefinitionInterface $definition
   *   The list definition.
   * @param mixed $input
   *   The raw input value.
   * @param mixed $fallback
   *   What this key already holds: the stored list, or the declared
   *   default.
   * @param string $path
   *   The dotted path of this value, for refusal reporting.
   *
   * @return mixed
   *   The accepted list, or the fallback when no list was given.
   *
   * @throws \Drupal\data_surface\Pipeline\UnknownKeysException
   *   When an item's nested input key is not a property definition.
   * @throws \Drupal\data_surface\Pipeline\TypeMismatchException
   *   When an item cannot be held by the item definition.
   */
  protected function acceptList(ListDataDefinitionInterface $definition, mixed $input, mixed $fallback, string $path): mixed {
    if (!ValueState::isConfigured($input)) {
      return $fallback;
    }
    $item_definition = $definition->getItemDefinition();
    $input = Coercion::decoded($input, TRUE);
    $properties = $item_definition instanceof ComplexDataDefinitionInterface ? array_keys($item_definition->getPropertyDefinitions()) : [];
    if (!is_array($input) || ($properties !== [] && static::isItem($input, $properties))) {
      $input = [$input];
    }
    $values = [];
    foreach ($input as $delta => $item) {
      // A select submits its unchosen empty option like any other value;
      // an unchosen option is not an item.
      if (!ValueState::isConfigured($item)) {
        continue;
      }
      $values[] = $this->acceptValue($item_definition, $item, NULL, $path . '.' . $delta);
    }
    return $values;
  }

  /**
   * Coerces one configured value that is not a structure to its type.
   *
   * The coercion table is the Tool API's, mirrored in
   * \Drupal\data_surface\Pipeline\Coercion, and this is the only place
   * the pipeline applies it to a scalar. A near miss snaps onto the
   * values the key offers, read from the same options service a
   * generated select and the tool's advertised enum read. An array for a
   * type that holds one scalar cannot be held at all, so it is refused
   * here rather than coerced.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to coerce through.
   * @param mixed $value
   *   The raw value; always configured.
   * @param string $path
   *   The dotted path of this value, for refusal reporting.
   *
   * @return mixed
   *   The value in the definition's type, or untouched when the table has
   *   no unambiguous reading of it.
   *
   * @throws \Drupal\data_surface\Pipeline\TypeMismatchException
   *   When an array arrives for a type that holds a single value.
   */
  protected function coerce(DataDefinitionInterface $definition, mixed $value, string $path): mixed {
    $type = $definition->getDataType();
    if (is_array($value)) {
      if (in_array($type, self::SCALAR_TYPES, TRUE)) {
        throw new TypeMismatchException($path, $type, get_debug_type($value));
      }
      return $value;
    }
    $choices = [];
    if (is_string($value) && $definition->getConstraints() !== []) {
      $set = $this->options->resolve($definition);
      $choices = $set === NULL ? [] : array_keys($set->options);
    }
    return Coercion::scalar($definition, $value, $choices);
  }

  /**
   * Turns a refusal by access into the one violation it is.
   *
   * Filed under the reserved key rather than under a top level key,
   * because nothing about any particular value was wrong: the run itself
   * was refused. The reason travels as a message object, like every
   * other violation, so whoever prints it escapes it once.
   *
   * @param \Drupal\Core\Access\AccessResultInterface $access
   *   The forbidden answer.
   *
   * @return \Drupal\data_surface\Pipeline\ViolationSet
   *   One violation, under ACCESS_VIOLATION_KEY.
   */
  protected function accessViolations(AccessResultInterface $access): ViolationSet {
    return new ViolationSet([
      new Violation(self::ACCESS_VIOLATION_KEY, '', DataSurfaceAccess::message($access)),
    ]);
  }

  /**
   * Turns refused keys into violations in the shape validation returns.
   *
   * The key a violation is filed under is always a top level key, so a
   * nested unknown key is reported on its top-level key with the rest of
   * its path, exactly like a constraint violation inside a map.
   *
   * @param \Drupal\data_surface\Pipeline\UnknownKeysException $exception
   *   The refusal from accept().
   *
   * @return \Drupal\data_surface\Pipeline\ViolationSet
   *   The violations, filed under their top level keys.
   */
  protected function unknownKeyViolations(UnknownKeysException $exception): ViolationSet {
    $violations = [];
    foreach ($exception->getKeys() as $key) {
      $path = $exception->getPath() === '' ? $key : $exception->getPath() . '.' . $key;
      $segments = explode('.', $path);
      $name = array_shift($segments);
      $violations[] = new Violation(
        (string) $name,
        implode('.', $segments),
        $this->t('Unknown key @key.', ['@key' => $path]),
      );
    }
    return new ViolationSet($violations);
  }

  /**
   * Turns a slot value shaped for another variant into violations.
   *
   * One per misplaced key, each on its own path inside the slot, filed
   * under the top level key that carries the slot, so a form flags the
   * slot and a caller reads exactly which keys to move.
   *
   * @param \Drupal\data_surface\Pipeline\VariantMismatchException $exception
   *   The refusal from accept().
   *
   * @return \Drupal\data_surface\Pipeline\ViolationSet
   *   The violations.
   */
  protected function variantMismatchViolations(VariantMismatchException $exception): ViolationSet {
    $segments = explode('.', $exception->getPath());
    $name = (string) array_shift($segments);
    $violations = [];
    foreach ($exception->getOwners() as $key => $owners) {
      $violations[] = new Violation(
        $name,
        implode('.', [...$segments, $key]),
        $this->variantMismatchMessage($exception->getPath() . '.' . $key, $exception->getBy(), $exception->getChosen(), $owners),
      );
    }
    return new ViolationSet($violations);
  }

  /**
   * Turns a type mismatch into a violation, the way unknown keys are.
   *
   * @param \Drupal\data_surface\Pipeline\TypeMismatchException $exception
   *   The refusal from accept().
   *
   * @return \Drupal\data_surface\Pipeline\ViolationSet
   *   One violation, filed under the top level key that carries the
   *   value.
   */
  protected function typeMismatchViolations(TypeMismatchException $exception): ViolationSet {
    $segments = explode('.', $exception->getPath());
    $name = array_shift($segments);
    return new ViolationSet([
      new Violation(
        (string) $name,
        implode('.', $segments),
        $this->t('This value must be of type @expected, @actual given.', [
          '@expected' => $exception->getExpected(),
          '@actual' => $exception->getActual(),
        ]),
      ),
    ]);
  }

}
