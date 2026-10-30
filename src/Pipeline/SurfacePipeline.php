<?php

declare(strict_types=1);

namespace Drupal\surface\Pipeline;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\surface\SurfaceAccess;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Options\SurfaceOptions;

/**
 * The one entry point from raw values to stored values.
 *
 * It keeps the write path and the document: what is written back and
 * what a write changes (kept(), changes()), prepare(), commit(), submit()
 * and its dry run, a delete, outbound() and document(). The three verbs
 * before a write are classes of their own, which this one constructs
 * from its services; accept(), validate() and conformOutput() call them:
 *
 * - Accepting: raw values to typed values, by the coercion table, the
 *   three-way rule, secrets and computed keys.
 * - Validating: the accepted values held to the refined definition, each
 *   refusal by its path, naming what it depends on and what is allowed.
 * - Conforming: outputs held to their declared shape.
 *
 * Decision: see docs/decisions.md#the-pipeline-is-read-by-verb.
 *
 * @see \Drupal\surface\Pipeline\SurfacePipelineInterface
 *   For the documentation of every method.
 * @see docs/semantics.md
 *   For the rules accept() and validate() apply, as tables, including
 *   the one exception a secret key makes to them.
 */
final class SurfacePipeline implements SurfacePipelineInterface {

  use StringTranslationTrait;

  /**
   * The data types that hold one scalar.
   *
   * An array for one of them is a type mismatch, refused rather than
   * coerced, here and by the tool's shape check before the Tool API's
   * validators run (InputDefinitions::withoutNonScalars()). Every other
   * type ('any', a map that declares no properties, anything a
   * contributed typed data plugin adds) is left alone by both, because a
   * rule it never defined would be a guess.
   */
  public const SCALAR_TYPES = [
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
   * The most allowed values a refusal lists, before saying how many more.
   */
  public const CHOICES_SHOWN = 10;

  /**
   * The context of a refusal's own sentences, for a translator.
   *
   * Decision: see docs/decisions.md#a-refusal-names-what-it-depends-on.
   */
  public const REFUSAL_CONTEXT = 'surface refusal';

  /**
   * The accept verb: raw values to typed values.
   */
  protected Accepting $accepting;

  /**
   * The validate verb: accepted values held to the refined definition.
   */
  protected Validating $validating;

  /**
   * The conform verb: outputs held to their declared shape.
   */
  protected Conforming $conforming;

  /**
   * Constructs a SurfacePipeline, and the three verbs over its services.
   *
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The typed data manager, used to run the data surface's constraints.
   * @param \Drupal\surface\Options\SurfaceOptions $options
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
    protected readonly SurfaceOptions $options,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
    $this->accepting = new Accepting($options, $string_translation);
    $this->validating = new Validating($typedDataManager, $options, $string_translation, $this->accepting, $this);
    $this->conforming = new Conforming($typedDataManager, $string_translation);
  }

  /**
   * {@inheritdoc}
   */
  public function accept(SurfaceInterface $surface, array $input, array $current = []): array {
    return $this->accepting->accept($surface, $input, $current);
  }

  /**
   * {@inheritdoc}
   */
  public function outbound(SurfaceInterface $surface, array $values): array {
    return Outbound::project($surface, $values);
  }

  /**
   * {@inheritdoc}
   */
  public function document(SurfaceInterface $surface, array $values): \stdClass {
    $document = new \stdClass();
    foreach ($values as $name => $value) {
      $entry = $surface->getDefinitions()->entry((string) $name);
      if ($value === NULL && $entry !== NULL && DefinitionMetadata::isSecret($entry->definition)) {
        // A secret that holds nothing is written left out: null is no value
        // of it, and both schemas say so.
        // Decision: see docs/decisions.md#a-secret-is-optional-never-nullable.
        continue;
      }
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
  public function kept(SurfaceInterface $surface, array $values, array $current, ?array $input = NULL): array {
    // A thing storage holds nothing of is being created, and is written
    // whole, its defaults included.
    $exists = $input !== NULL && $current !== [];
    $defaults = NULL;
    $kept = [];
    $unwritten = [];
    foreach ($surface->getDefinitions()->entries() as $name => $entry) {
      $name = (string) $name;
      if (!array_key_exists($name, $values)) {
        if (array_key_exists($name, $current)) {
          $kept[$name] = $current[$name];
        }
        continue;
      }
      $sent = $input !== NULL && array_key_exists($name, $input);
      if ($exists && !array_key_exists($name, $current)) {
        $defaults ??= $surface->getDefaultValues();
        if (static::isDefault($values[$name], $defaults[$name] ?? NULL)) {
          // A key storage never held reads as its default. Left out, or
          // sent as that default (a form posts every element it drew),
          // it is what it was: nothing to write.
          // Decision: see docs/decisions.md#absent-keeps-null-clears.
          $unwritten[$name] = TRUE;
          continue;
        }
      }
      $kept[$name] = $values[$name];
      $child = $entry->list === NULL ? $entry->childFor($values) : NULL;
      if ($sent && $child === NULL && $entry->list === NULL && !is_array($values[$name]) && array_key_exists($name, $current) && $this->heldAlike($entry->definition, $values[$name], $current[$name])) {
        $kept[$name] = $current[$name];
        continue;
      }
      if (!is_array($values[$name]) || !is_array($current[$name] ?? NULL) || $entry->list !== NULL) {
        continue;
      }
      $below = $input === NULL ? NULL : (is_array($input[$name] ?? NULL) ? $input[$name] : []);
      $chosen = $entry->variants?->chosen($values[$entry->variants->by] ?? NULL);
      // The secrets of a variant not chosen are kept as stored, beside the
      // chosen variant's keys.
      // Decision: see
      // docs/decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter.
      $aside = $chosen === NULL ? [] : $entry->variants->keptSecrets($chosen, $current[$name]);
      $own = array_diff_key($current[$name], $aside);
      if ($chosen !== NULL && $entry->variants->heldFor($chosen, $current[$name], $current[$entry->variants->by] ?? NULL) === NULL) {
        // What it held was another variant's: the chosen one starts here,
        // and is written whole, its defaults included.
        $below = NULL;
      }
      $kept[$name] = ($child !== NULL
        ? $this->kept($child, $values[$name], $own, $below)
        : $this->keptInMap($entry->definition, $values[$name], $own, $below)) + $aside;
    }
    return array_diff_key($kept + $values, $unwritten);
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
   * @param array|null $input
   *   What was sent for it, or NULL when that is not known.
   *
   * @return array
   *   The map to write.
   */
  protected function keptInMap(DataDefinitionInterface $definition, array $value, array $stored, ?array $input = NULL): array {
    if (!$definition instanceof ComplexDataDefinitionInterface || $definition instanceof ListDataDefinitionInterface) {
      return $value;
    }
    $kept = [];
    $unwritten = [];
    foreach ($definition->getPropertyDefinitions() as $property => $property_definition) {
      if (!array_key_exists($property, $value)) {
        if (array_key_exists($property, $stored)) {
          $kept[$property] = $stored[$property];
        }
        continue;
      }
      $sent = $input !== NULL && array_key_exists($property, $input);
      if ($input !== NULL && !array_key_exists($property, $stored) && static::isDefault($value[$property], DefinitionMetadata::defaultOf($property_definition))) {
        $unwritten[$property] = TRUE;
        continue;
      }
      if ($sent && !is_array($value[$property]) && array_key_exists($property, $stored) && $this->heldAlike($property_definition, $value[$property], $stored[$property])) {
        $kept[$property] = $stored[$property];
        continue;
      }
      $kept[$property] = is_array($value[$property]) && is_array($stored[$property] ?? NULL)
        ? $this->keptInMap($property_definition, $value[$property], $stored[$property], $input === NULL ? NULL : (is_array($input[$property] ?? NULL) ? $input[$property] : []))
        : $value[$property];
    }
    return array_diff_key($kept + $value, $unwritten);
  }

  /**
   * Answers whether an accepted value is a key's default, read alike.
   *
   * A property a default leaves out holds nothing, so it is NULL in the
   * accepted value: the two are one value.
   *
   * @param mixed $value
   *   The accepted value.
   * @param mixed $default
   *   The declared default.
   *
   * @return bool
   *   TRUE when the value is the default.
   */
  protected static function isDefault(mixed $value, mixed $default): bool {
    if (!is_array($value) || !is_array($default) || array_is_list($value) || array_is_list($default)) {
      return Accepting::sameValue($value, $default);
    }
    foreach (array_keys($value + $default) as $key) {
      if (!static::isDefault($value[$key] ?? NULL, $default[$key] ?? NULL)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Answers whether an accepted value is what storage holds, read alike.
   *
   * What storage holds is accepted as though it were sent, so the empty
   * string a read returned and the NULL the empty string sent is accepted
   * as are one value, as are a stored "5" and an integer 5: the stored
   * representation is then the one written back.
   *
   * Decision: see docs/decisions.md#absent-keeps-null-clears.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key or the property.
   * @param mixed $accepted
   *   The accepted value, not an array.
   * @param mixed $stored
   *   What storage holds for it.
   *
   * @return bool
   *   TRUE when the two are one value.
   */
  protected function heldAlike(DataDefinitionInterface $definition, mixed $accepted, mixed $stored): bool {
    if (DefinitionMetadata::isSecret($definition) || $definition instanceof ListDataDefinitionInterface || is_array($stored)) {
      return FALSE;
    }
    try {
      return Accepting::sameValue($accepted, $this->accepting->acceptValue($definition, $stored, $stored, ''));
    }
    catch (\InvalidArgumentException) {
      return FALSE;
    }
  }

  /**
   * Lists the paths a write changes, against what storage holds.
   *
   * @param array $write
   *   What would be written, as kept() puts it.
   * @param array $current
   *   What storage holds.
   * @param string $prefix
   *   The dotted path of this level; '' at the top.
   *
   * @return string[]
   *   The dotted paths whose value would change, a map's by its own keys,
   *   a list whole; empty when the write changes nothing.
   */
  protected static function changes(array $write, array $current, string $prefix = ''): array {
    $changes = [];
    foreach ($write as $key => $value) {
      $path = Accepting::joinPath($prefix, (string) $key);
      if (!array_key_exists($key, $current)) {
        $changes[] = $path;
        continue;
      }
      $held = $current[$key];
      if (is_array($value) && is_array($held) && $value !== [] && $held !== [] && !array_is_list($value) && !array_is_list($held)) {
        array_push($changes, ...static::changes($value, $held, $path));
        // Below the top a map is written whole, so what it held and the
        // write leaves out is gone. At the top, what no key describes is
        // storage's own, and stays.
        foreach (array_keys(array_diff_key($held, $value)) as $gone) {
          $changes[] = Accepting::joinPath($path, (string) $gone);
        }
        continue;
      }
      if (!Accepting::sameValue($value, $held)) {
        $changes[] = $path;
      }
    }
    return $changes;
  }

  /**
   * {@inheritdoc}
   */
  public function validate(SurfaceInterface $surface, array $values, array $current = []): ViolationSet {
    return $this->validating->validate($surface, $values, $current);
  }

  /**
   * {@inheritdoc}
   */
  public function conformOutput(SurfaceInterface $surface, array $output): ViolationSet {
    return $this->conforming->conformOutput($surface, $output);
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(SurfaceInterface $surface, array $values, SurfaceStorageInterface $storage): PreparedValues {
    $prepared = $storage->prepare($surface, $values);
    return $surface->changesNothing() ? $prepared->changingNothing() : $prepared;
  }

  /**
   * {@inheritdoc}
   */
  public function commit(PreparedValues $prepared, SurfaceStorageInterface $storage): void {
    // Decision: see docs/decisions.md#an-operation-states-how-it-behaves.
    if ($prepared->changesNothing) {
      throw new \LogicException('These values were prepared for an operation that changes nothing, and are not written: a read only operation stops after prepare.');
    }
    $storage->commit($prepared);
  }

  /**
   * {@inheritdoc}
   */
  public function submit(SurfaceInterface $surface, array $input, SurfaceStorageInterface $storage, bool $dry_run = FALSE, ?AccessResultInterface $access = NULL): SurfaceResult {
    if ($access !== NULL && $access->isForbidden()) {
      // Before the storage is touched, on purpose: a refused caller must
      // not be able to learn what storage holds by being refused, and a
      // load is a query, a cache entry and sometimes an entity build.
      // The values come back empty for the same reason.
      return new SurfaceResult([], $this->accessViolations($access), access: $access);
    }
    // Locked whole: shown and fixed, so nothing posted can move a key and
    // a write is refused by name rather than written back unchanged.
    // Decision: see docs/decisions.md#locked-at-the-top.
    $locked = $surface->lockedWhole();
    if ($locked !== NULL) {
      return new SurfaceResult([], new ViolationSet([
        new Violation('', '', $this->t('This may be seen and not changed, so nothing was written: @reason', ['@reason' => $locked]), Violation::LOCKED),
      ]), access: $access);
    }
    if ($storage instanceof DeletingStorageInterface && $storage->deletes()) {
      return $this->delete($surface, $input, $storage, $dry_run, $access);
    }
    $current = $storage->load($surface);
    try {
      $values = $this->accept($surface, $input, $current);
    }
    catch (UnknownKeysException $e) {
      return new SurfaceResult($current, $this->accepting->unknownKeyViolations($e), access: $access);
    }
    catch (TypeMismatchException $e) {
      return new SurfaceResult($current, $this->accepting->typeMismatchViolations($e), access: $access);
    }
    catch (VariantMismatchException $e) {
      return new SurfaceResult($current, $this->accepting->variantMismatchViolations($e), access: $access);
    }
    // What storage holds is what the stale rule is judged against, and
    // it was loaded a moment ago for the merge, so the question costs
    // nothing extra here.
    // Blocking nothing: a value sent for a locked key was not taken, and
    // the caller is told so whatever else the run answers.
    $notes = array_values($this->accepting->notTaken());
    // Refused, not erased: a secret a collection item would lose because
    // its position may now hold another item.
    // Decision: see
    // docs/decisions.md#every-door-projects-what-the-read-tool-projects.
    if ($this->accepting->unmatched() !== []) {
      return new SurfaceResult($values, new ViolationSet(array_values($this->accepting->unmatched())), access: $access, notes: $notes);
    }
    $violations = $this->validate($surface, $values, $current);
    if (!$violations->isEmpty()) {
      return new SurfaceResult($values, $violations, access: $access, notes: $notes);
    }
    // What is written: what was accepted, with what storage holds put
    // back where nothing was said or what was said is what it holds. A
    // thing being created is written whole.
    // Decision: see docs/decisions.md#absent-keeps-null-clears.
    $creates = $storage instanceof CreatingStorageInterface && $storage->creates();
    $write = $this->kept($surface, $values, $current, $creates ? NULL : $input);
    $changes = $surface->changesNothing() ? [] : static::changes($write, $current);
    try {
      $prepared = $this->prepare($surface, $write, $storage);
    }
    catch (StorageViolationsException $e) {
      return new SurfaceResult($values, new ViolationSet($this->validating->namingSourcesAtTop(iterator_to_array($e->getViolations(), FALSE), $surface, $values, $current)), access: $access, notes: $notes);
    }
    // The set travels on rather than being replaced by an empty one: it
    // blocks nothing, so the run is valid and committed, and the stale
    // references it carries are how a caller — a form warning, an
    // agent's dry run — learns what to re-choose.
    // An operation that changes nothing stops where a dry run does.
    // Decision: see docs/decisions.md#an-operation-states-how-it-behaves.
    // A write that changes nothing on a thing that exists is not made:
    // it is committed, and nothing moved. One being created is written
    // whatever it holds.
    $writes = !$surface->changesNothing() && ($changes !== [] || $current === [] || $creates);
    if ($dry_run || $surface->changesNothing()) {
      return new SurfaceResult($values, $violations, $prepared, access: $access, notes: $notes, changes: $changes, written: $write, writes: $writes);
    }
    if ($writes) {
      $this->commit($prepared, $storage);
    }
    return new SurfaceResult($values, $violations, $prepared, TRUE, $access, $notes, $changes, $write, $writes);
  }

  /**
   * {@inheritdoc}
   */
  public function commitDryRun(SurfaceResult $dry, SurfaceStorageInterface $storage): SurfaceResult {
    if (!$dry->isValid() || $dry->prepared === NULL || $dry->committed) {
      throw new \LogicException('Only a dry run that was valid and reached prepare can be committed: run the whole submit() instead.');
    }
    // Decision: see
    // docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
    if ($dry->writes) {
      $this->commit($dry->prepared, $storage);
    }
    return new SurfaceResult($dry->values, $dry->violations, $dry->prepared, !$dry->prepared->changesNothing, $dry->access, $dry->notes, $dry->changes, $dry->written, $dry->writes);
  }

  /**
   * Runs an operation that removes the thing its storage holds.
   *
   * A delete takes no values, so none is accepted or validated: what is
   * stored is handed to prepare(), which rehearses the removal with the
   * store's own checks, and commit() removes it, so a thing whose stored
   * values no longer hold to the resource (a region its theme dropped) can
   * still be removed. The values answered are what is stored, the thing
   * removed or that a dry run would remove, and the one change is the
   * whole document, the empty pointer.
   *
   * Decision: see docs/decisions.md#a-placed-block-is-a-resource.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface, built for the delete.
   * @param array $input
   *   What was sent: nothing, or each key is refused.
   * @param \Drupal\surface\Pipeline\DeletingStorageInterface $storage
   *   The storage, which deletes.
   * @param bool $dry_run
   *   TRUE to stop after prepare().
   * @param \Drupal\Core\Access\AccessResultInterface|null $access
   *   The access answer, already found not forbidden.
   *
   * @return \Drupal\surface\Pipeline\SurfaceResult
   *   The result.
   */
  protected function delete(SurfaceInterface $surface, array $input, DeletingStorageInterface $storage, bool $dry_run, ?AccessResultInterface $access): SurfaceResult {
    $current = $storage->load($surface);
    if ($input !== []) {
      $refused = [];
      foreach (array_keys($input) as $key) {
        $refused[] = new Violation((string) $key, '', $this->t('A delete takes no values: it removes what is stored, and @key was sent.', ['@key' => $key]), Violation::UNKNOWN_KEY);
      }
      return new SurfaceResult($current, new ViolationSet($refused), access: $access);
    }
    try {
      $prepared = $this->prepare($surface, $current, $storage);
    }
    catch (StorageViolationsException $e) {
      return new SurfaceResult($current, $e->getViolations(), access: $access);
    }
    if (!$dry_run) {
      $this->commit($prepared, $storage);
    }
    return new SurfaceResult($current, new ViolationSet(), $prepared, !$dry_run, $access, changes: [''], written: $current, writes: TRUE);
  }

  /**
   * Turns a refusal by access into the one violation it is.
   *
   * Filed under no key, with an empty path, rather than under a top
   * level key, because nothing about any particular value was wrong: the
   * run itself was refused, which its code says. The reason travels as a
   * message object, like every other violation, so whoever prints it
   * escapes it once.
   *
   * Decision: see docs/decisions.md#one-result-at-every-door.
   *
   * @param \Drupal\Core\Access\AccessResultInterface $access
   *   The forbidden answer.
   *
   * @return \Drupal\surface\Pipeline\ViolationSet
   *   One violation, with an empty path and the code `access`.
   */
  protected function accessViolations(AccessResultInterface $access): ViolationSet {
    return new ViolationSet([
      new Violation('', '', SurfaceAccess::message($access), Violation::ACCESS),
    ]);
  }

}
