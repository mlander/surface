<?php

declare(strict_types=1);

namespace Drupal\data_surface\Pipeline;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\data_surface\Computed;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Options\DataSurfaceOptions;
use Drupal\data_surface\Refinement\DoesNotApply;
use Drupal\data_surface\Entry;
use Drupal\data_surface\Collection;

/**
 * The accept verb of the pipeline: raw values to typed values.
 *
 * What DataSurfacePipelineInterface::accept() does: each level merged from
 * the declared defaults, what storage holds and the input; each value
 * coerced through the Tool API's coercion table (Coercion); the three-way
 * rule, where a key left out keeps what it holds, one sent nothing is
 * cleared and one sent a value takes it; the exceptions a locked key, a
 * secret and a computed key make to it; and each child, variant and
 * collection item accepted through the child that describes it. Then
 * what turns accept()'s refusals into violations, the nearest declared
 * key suggested for an unknown one.
 *
 * It holds what one accept() collects beside the values: the locked and
 * computed keys that did not take the value sent, and the secrets a
 * collection item would erase. submit() reads both after it.
 *
 * Decision: see docs/decisions.md#the-pipeline-is-read-by-verb.
 *
 * @internal
 *
 * @see \Drupal\data_surface\Pipeline\DataSurfacePipeline
 * @see docs/semantics.md
 */
final class Accepting {

  use StringTranslationTrait;

  /**
   * The most edits an unknown key may be from the declared key suggested.
   */
  protected const NEAREST_EDITS = 2;

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
   * Constructs the accept verb.
   *
   * @param \Drupal\data_surface\Options\DataSurfaceOptions $options
   *   The options service, whose values a near miss snaps onto (coerce()).
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service, for the sentences a refusal and a
   *   note say.
   */
  public function __construct(
    protected readonly DataSurfaceOptions $options,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Accepts a data surface's values at the top.
   *
   * Starts again what one accept() collects beside the values.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface describing what the values may be.
   * @param array $input
   *   Raw input keyed by data surface key; may be partial.
   * @param array $current
   *   The stored values, in input layout.
   *
   * @return array
   *   The accepted values: every data surface key present, each in its
   *   definition's native type.
   *
   * @throws \Drupal\data_surface\Pipeline\UnknownKeysException
   *   When the input carries a key no definition declares.
   * @throws \Drupal\data_surface\Pipeline\TypeMismatchException
   *   When the input carries a value in a shape its definition cannot
   *   hold.
   * @throws \Drupal\data_surface\Pipeline\VariantMismatchException
   *   When the input at a key with variants carries keys of a variant
   *   its deciding key did not choose.
   *
   * @see \Drupal\data_surface\Pipeline\DataSurfacePipelineInterface::accept()
   */
  public function accept(DataSurfaceInterface $surface, array $input, array $current = []): array {
    $this->notTaken = [];
    $this->unmatched = [];
    return $this->acceptLevel($surface, $input, $current, '');
  }

  /**
   * Lists the keys the last accept() was sent a value it did not take.
   *
   * @return array<string, \Drupal\data_surface\Pipeline\Violation>
   *   The notes, by full path.
   */
  public function notTaken(): array {
    return $this->notTaken;
  }

  /**
   * Lists the secrets the last accept() found a sent item would erase.
   *
   * @return array<string, \Drupal\data_surface\Pipeline\Violation>
   *   The refusals, by the secret's full path.
   */
  public function unmatched(): array {
    return $this->unmatched;
  }

  /**
   * Accepts one data surface's values, at the top or inside a child.
   *
   * A child's value is accepted by the built child describing it,
   * through this same method, so its locked keys, secrets and defaults
   * answer for themselves exactly as they would on their own. Variants
   * are accepted after everything else, because the variant is read from
   * the accepted value of the deciding key, and so is a child that reads
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
   *   When the value at a key with variants is shaped for a variant
   *   other than the chosen.
   */
  protected function acceptLevel(DataSurfaceInterface $surface, array $input, array $current, string $prefix): array {
    $definitions = $surface->getDefinitions();
    $unknown = array_keys(array_diff_key($input, $definitions->toArray()));
    if ($unknown !== []) {
      throw new UnknownKeysException(array_map('strval', $unknown), $prefix, $definitions->names());
    }
    $values = array_fill_keys($definitions->names(), NULL);
    $deferred = [];
    $computed = [];
    foreach ($definitions->entries() as $name => $entry) {
      if (!$entry->locked && Computed::of($entry->definition) !== NULL) {
        // Computed from the accepted values of the keys it names, so last.
        // Decision: see
        // docs/decisions.md#a-computed-key-is-a-pure-function-of-its-sources.
        $computed[] = $entry;
        continue;
      }
      if ($entry->variants !== NULL || ($entry->attachment !== NULL && $entry->attachment->reads !== [])) {
        $deferred[] = $entry;
        continue;
      }
      $values[$name] = $this->acceptKey($surface, $entry, $input, $current, $prefix, $values);
    }
    foreach ($deferred as $entry) {
      $values[$entry->name] = $this->acceptKey($surface, $entry, $input, $current, $prefix, $values);
    }
    foreach ($computed as $entry) {
      $values[$entry->name] = $this->acceptComputed($surface, $entry, $input, $current, $prefix, $values);
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
   *   The values accepted at this level so far, which is where a key's
   *   variants read their deciding key.
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
        ]), Violation::LOCKED);
      }
      return $kept;
    }
    // What the key already holds: the stored value when storage has
    // one, and the declared default only when it has none. A stored
    // NULL is a stored value, so array_key_exists rather than ??.
    $was_stored = array_key_exists($name, $current);
    $fallback = $was_stored ? $current[$name] : $surface->getDefault($name);
    if ($fallback === NULL && DefinitionMetadata::isGrouping($entry->definition)) {
      // The map each module's mounted keys sit in is a map, never null,
      // even when nothing is stored and no mounted key has a default.
      // Decision: see docs/decisions.md#a-key-that-does-not-apply.
      $fallback = [];
    }
    $chosen = $entry->variants?->chosen($values[$entry->variants->by] ?? NULL);
    if ($chosen !== NULL) {
      // What the key held was written for another variant, and the
      // deciding key has moved on: it starts again from the chosen
      // variant's defaults rather than carrying keys that mean nothing
      // here, and from the secrets of its own kept while it was not
      // chosen. Which variant it was written for is what the deciding
      // key holds beside it, never the names of its keys.
      // Decision: see
      // docs/decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter.
      $by = $entry->variants->by;
      $decided = $was_stored ? ($current[$by] ?? NULL) : $surface->getDefault($by);
      $fallback = $entry->variants->heldFor($chosen, $fallback, $decided)
        ?? (is_array($fallback) ? $entry->variants->ownSecrets($chosen, $fallback) : []) + $entry->variants->defaultsOf($chosen);
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
   * Accepts a computed key: the value it holds, whatever was sent.
   *
   * Computed from the accepted values of the keys it names while storage
   * holds nothing for it, and on every write that changes one of them
   * when it says so; else what is stored. A value sent for it is read as
   * though it were open: the value it holds is noted and not taken, as a
   * locked key's is; any other stays, for validate() to refuse with the
   * code `computed`, naming the keys it is computed from, as a value sent
   * for a key that does not apply stays. A key computed unless sent
   * (Compute::UNLESS_SENT) takes a value sent for it as any key does; one
   * sent null or empty is read as left out, and computed.
   *
   * Decision: see
   * docs/decisions.md#a-computed-key-is-a-pure-function-of-its-sources.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface the key belongs to.
   * @param \Drupal\data_surface\Entry $entry
   *   The computed key.
   * @param array $input
   *   The raw input for the key's level.
   * @param array $current
   *   What the key's level already holds.
   * @param string $prefix
   *   The dotted path of the key's level.
   * @param array $values
   *   The values accepted at this level, every other key's.
   *
   * @return mixed
   *   The value it holds, or another value sent for it.
   */
  protected function acceptComputed(DataSurfaceInterface $surface, Entry $entry, array $input, array $current, string $prefix, array $values): mixed {
    $name = $entry->name;
    $path = static::joinPath($prefix, $name);
    $computed = Computed::of($entry->definition);
    assert($computed !== NULL);
    if ($computed->takesSent() && array_key_exists($name, $input) && ValueState::isConfigured($input[$name])) {
      // Typed over: kept as sent, and judged as any key.
      $fallback = array_key_exists($name, $current) ? $current[$name] : NULL;
      return $this->acceptValue($entry->definition, $input[$name], $fallback, $path);
    }
    $value = $computed->value($name, $values, $current, $entry->definition);
    $held = $this->acceptValue($entry->definition, $value, $value, $path);
    if (!array_key_exists($name, $input) || $computed->takesSent()) {
      return $held;
    }
    $sent = $this->acceptValue($entry->definition, $input[$name], $held, $path);
    if (!static::sameValue($sent, $held)) {
      return $sent;
    }
    [$key, $below] = array_pad(explode('.', $path, 2), 2, '');
    $this->notTaken[$path] = new Violation($key, $below, $this->computedFrom($entry->definition->getLabel() ?? $name, $this->sources($surface, $computed, $prefix), 'note'), Violation::COMPUTED);
    return $held;
  }

  /**
   * Names the keys a computed key is computed from, for a sentence.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface the key belongs to.
   * @param \Drupal\data_surface\Computed $computed
   *   What it is computed from.
   * @param string $prefix
   *   The dotted path of its level, with a trailing dot or empty.
   *
   * @return \Drupal\data_surface\Pipeline\Named[]
   *   Each key, by its label for a person and its path for a machine.
   */
  public function sources(DataSurfaceInterface $surface, Computed $computed, string $prefix): array {
    $prefix = $prefix === '' || str_ends_with($prefix, '.') ? $prefix : $prefix . '.';
    return array_map(static fn (string $source): Named => Named::key($surface->getDefinition($source)?->getLabel() ?? $source, $prefix . $source), $computed->from);
  }

  /**
   * Says that a key is computed, in one whole sentence per count of keys.
   *
   * Four sentences, each one source string per count of the keys it is
   * computed from: the note ("Slug is computed from Name; the value sent
   * was not taken."), the refusal of another value ("Slug is computed
   * from Name and takes no value of its own; leave it out."), the
   * sentence set after a refusal of the value it holds ("This is
   * computed from Name."), and that sentence for a key computed unless
   * sent ("This is computed from Name unless a value is sent.").
   *
   * Decision: see docs/decisions.md#a-refusal-names-what-it-depends-on.
   *
   * @param string|\Stringable $label
   *   The computed key's label.
   * @param \Drupal\data_surface\Pipeline\Named[] $sources
   *   The keys it is computed from, at least one.
   * @param string $kind
   *   Which sentence: `note`, `refusal`, `after` or `unless`.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The sentence.
   */
  public function computedFrom(string|\Stringable $label, array $sources, string $kind): TranslatableMarkup {
    $context = ['context' => DataSurfacePipeline::REFUSAL_CONTEXT];
    $count = count($sources);
    $arguments = ['@label' => $label] + match ($count) {
      1 => ['@source' => $sources[0]],
      2 => ['@first' => $sources[0], '@second' => $sources[1]],
      default => ['@sources' => implode(', ', array_map('strval', $sources))],
    };
    return match ([$kind, min($count, 3)]) {
      ['note', 1] => $this->t('@label is computed from @source; the value sent was not taken.', $arguments, $context),
      ['note', 2] => $this->t('@label is computed from @first and @second; the value sent was not taken.', $arguments, $context),
      ['note', 3] => $this->formatPlural($count, '@label is computed from @sources; the value sent was not taken.', '@label is computed from these @count keys, @sources; the value sent was not taken.', $arguments, $context),
      ['refusal', 1] => $this->t('@label is computed from @source and takes no value of its own; leave it out.', $arguments, $context),
      ['refusal', 2] => $this->t('@label is computed from @first and @second and takes no value of its own; leave it out.', $arguments, $context),
      ['refusal', 3] => $this->formatPlural($count, '@label is computed from @sources and takes no value of its own; leave it out.', '@label is computed from these @count keys, @sources, and takes no value of its own; leave it out.', $arguments, $context),
      ['after', 1] => $this->t('This is computed from @source.', $arguments, $context),
      ['after', 2] => $this->t('This is computed from @first and @second.', $arguments, $context),
      ['after', 3] => $this->formatPlural($count, 'This is computed from @sources.', 'This is computed from these @count keys: @sources.', $arguments, $context),
      ['unless', 1] => $this->t('This is computed from @source unless a value is sent.', $arguments, $context),
      ['unless', 2] => $this->t('This is computed from @first and @second unless a value is sent.', $arguments, $context),
      default => $this->formatPlural($count, 'This is computed from @sources unless a value is sent.', 'This is computed from these @count keys, @sources, unless a value is sent.', $arguments, $context),
    };
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
  public static function sameValue(mixed $a, mixed $b): bool {
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
      '@label' => Validating::definitionAt($child, $parents)?->getLabel() ?? end($parents),
    ]), Violation::SECRET_IN_ITEM);
  }

  /**
   * Accepts a child's value through the built child describing it.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The attached key or key with variants.
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
   *   The chosen variant of the key's variants; NULL for an attached
   *   child.
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
    if ($entry->variants !== NULL && $chosen !== NULL) {
      $owners = $entry->variants->owners($entry->variants->strangers($chosen, $input), $chosen);
      if ($owners !== []) {
        throw new VariantMismatchException($path, $entry->variants->by, $chosen, $owners);
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
    if ($input === DataSurfacePipelineInterface::CLEAR_SECRET) {
      return NULL;
    }
    if (!ValueState::isConfigured($input)) {
      return $fallback;
    }
    return $this->acceptValue($definition, $input, $fallback, $path);
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
  public function acceptValue(DataDefinitionInterface $definition, mixed $input, mixed $fallback, string $path): mixed {
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
      throw new UnknownKeysException(array_map('strval', $unknown), $path, array_map('strval', array_keys($properties)));
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
      if (in_array($type, DataSurfacePipeline::SCALAR_TYPES, TRUE)) {
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
  public static function joinPath(string $path, string $segment): string {
    return $path === '' ? $segment : $path . '.' . $segment;
  }

  /**
   * Turns refused keys into violations in the shape validation returns.
   *
   * The key a violation is filed under is always a top level key, so a
   * nested unknown key is reported on its top-level key with the rest of
   * its path, exactly like a constraint violation inside a map. A key
   * within a small edit distance of one declared at its level is taken
   * for a misspelling, and the nearest declared key is suggested in a
   * sentence of its own after the refusal: "Did you mean venue_name?"
   *
   * Decision: see docs/decisions.md#a-refusal-names-what-it-depends-on.
   *
   * @param \Drupal\data_surface\Pipeline\UnknownKeysException $exception
   *   The refusal from accept().
   *
   * @return \Drupal\data_surface\Pipeline\ViolationSet
   *   The violations, filed under their top level keys.
   */
  public function unknownKeyViolations(UnknownKeysException $exception): ViolationSet {
    $violations = [];
    foreach ($exception->getKeys() as $key) {
      $path = $exception->getPath() === '' ? $key : $exception->getPath() . '.' . $key;
      $segments = explode('.', $path);
      $name = array_shift($segments);
      $message = $this->t('Unknown key @key.', ['@key' => $path]);
      $near = static::nearest((string) $key, $exception->getDeclared());
      if ($near !== NULL) {
        $message = new Sentences($message, $this->t('Did you mean @key?', [
          '@key' => $exception->getPath() === '' ? $near : $exception->getPath() . '.' . $near,
        ], ['context' => DataSurfacePipeline::REFUSAL_CONTEXT]));
      }
      $violations[] = new Violation(
        (string) $name,
        implode('.', $segments),
        $message,
        Violation::UNKNOWN_KEY,
      );
    }
    return new ViolationSet($violations);
  }

  /**
   * Finds the declared key an unknown one is most likely a misspelling of.
   *
   * @param string $key
   *   The unknown key.
   * @param string[] $declared
   *   The keys declared at its level, in declaration order.
   *
   * @return string|null
   *   The declared key nearest by edit distance, the first declared among
   *   equals, when it is at most NEAREST_EDITS edits away and the edits
   *   are fewer than half the unknown key's length, so a short key is
   *   never guessed at; NULL when none is that near.
   */
  protected static function nearest(string $key, array $declared): ?string {
    $nearest = NULL;
    foreach ($declared as $candidate) {
      $distance = levenshtein(strtolower($key), strtolower((string) $candidate));
      if ($distance <= self::NEAREST_EDITS && 2 * $distance < strlen($key) && ($nearest === NULL || $distance < $nearest[1])) {
        $nearest = [(string) $candidate, $distance];
      }
    }
    return $nearest[0] ?? NULL;
  }

  /**
   * Turns a value shaped for a variant nobody chose into violations.
   *
   * One per misplaced key, each on its own path inside the key with
   * variants, filed under the top level key that carries them, so a form
   * flags that key and a caller reads exactly which keys to move.
   *
   * @param \Drupal\data_surface\Pipeline\VariantMismatchException $exception
   *   The refusal from accept().
   *
   * @return \Drupal\data_surface\Pipeline\ViolationSet
   *   The violations.
   */
  public function variantMismatchViolations(VariantMismatchException $exception): ViolationSet {
    $segments = explode('.', $exception->getPath());
    $name = (string) array_shift($segments);
    $violations = [];
    foreach ($exception->getOwners() as $key => $owners) {
      $violations[] = new Violation(
        $name,
        implode('.', [...$segments, $key]),
        $this->variantMismatchMessage($exception->getPath() . '.' . $key, $exception->getBy(), $exception->getChosen(), $owners),
        Violation::WRONG_VARIANT,
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
  public function typeMismatchViolations(TypeMismatchException $exception): ViolationSet {
    return new ViolationSet([static::typeMismatchViolation($exception, $this->getStringTranslation())]);
  }

  /**
   * Words a type mismatch as the violation every door files it as.
   *
   * The form's door accepts what was posted through the same accept(),
   * and refuses the value it could not hold with this violation, as the
   * other doors do.
   *
   * @param \Drupal\data_surface\Pipeline\TypeMismatchException $exception
   *   The refusal from accept().
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The string translation service.
   *
   * @return \Drupal\data_surface\Pipeline\Violation
   *   The violation, filed under the top level key that carries the value.
   */
  public static function typeMismatchViolation(TypeMismatchException $exception, TranslationInterface $translation): Violation {
    $segments = explode('.', $exception->getPath());
    $name = array_shift($segments);
    return new Violation(
      (string) $name,
      implode('.', $segments),
      $translation->translate('This value must be of type @expected, @actual given.', [
        '@expected' => $exception->getExpected(),
        '@actual' => $exception->getActual(),
      ]),
      Violation::TYPE_MISMATCH,
    );
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
  public function variantMismatchMessage(string $path, string $by, string $chosen, array $owners): TranslatableMarkup {
    return $this->t('@path belongs to the @owners variant, but @by chose @chosen.', [
      '@path' => $path,
      '@owners' => implode(', ', $owners),
      '@by' => $by,
      '@chosen' => $chosen,
    ]);
  }

}
