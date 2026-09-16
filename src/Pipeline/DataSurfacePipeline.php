<?php

declare(strict_types=1);

namespace Drupal\data_surface\Pipeline;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\data_surface\DataSurfaceAccess;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Options\DataSurfaceOptions;

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

  /**
   * The data types the casting table has a rule for.
   *
   * Every other type — 'any', a map that declares no properties, and
   * anything a contributed typed data plugin adds — passes through
   * untouched, because the table has nothing to say about it and a cast
   * it did not define would be a guess.
   */
  protected const CAST_TYPES = ['integer', 'float', 'boolean', 'string', 'email', 'uri'];

  /**
   * Constructs a DataSurfacePipeline.
   *
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The typed data manager, used to run the surface's constraints.
   * @param \Drupal\data_surface\Options\DataSurfaceOptions $options
   *   The options service, asked one question and only when a value has
   *   already been refused: is this value among the ones its key offers?
   *   The list that validates and the list that is offered are the same
   *   list, so the stale rule has to read it from the same place a
   *   generated select does, or a form would stash a value the pipeline
   *   then refused.
   */
  public function __construct(
    protected readonly TypedDataManagerInterface $typedDataManager,
    protected readonly DataSurfaceOptions $options,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function accept(DataSurfaceInterface $surface, array $input, array $current = []): array {
    $definitions = $surface->getDefinitions();
    $unknown = array_keys(array_diff_key($input, $definitions->toArray()));
    if ($unknown !== []) {
      throw new UnknownKeysException(array_map('strval', $unknown));
    }
    $values = [];
    foreach ($definitions as $name => $definition) {
      // What the key already holds: the stored value when storage has
      // one, and the declared default only when it has none. A stored
      // NULL is a stored value, so array_key_exists rather than ??.
      $fallback = array_key_exists($name, $current) ? $current[$name] : $surface->getDefault($name);
      if ($surface->isLocked($name)) {
        // A locked key's value space is narrowed to exactly one value,
        // so input cannot move it. That one value is whatever storage
        // holds; the declared default is only the starting point for a
        // key storage has never held.
        $values[$name] = $fallback;
        continue;
      }
      if (!array_key_exists($name, $input)) {
        $values[$name] = $fallback;
        continue;
      }
      $values[$name] = DefinitionMetadata::isSecret($definition)
        ? $this->acceptSecret($definition, $input[$name], $fallback, (string) $name)
        : $this->acceptValue($definition, $input[$name], $fallback, (string) $name);
    }
    return $values;
  }

  /**
   * Accepts one value for a key the surface declared secret.
   *
   * The one documented exception to the rule that input holding nothing
   * means the key holds nothing. A generated form can never echo a
   * stored secret — that is what being secret means — so the box it
   * renders comes up empty every time. Somebody who changes the setting
   * beside it and saves has therefore submitted an empty secret without
   * meaning to say anything about the secret at all, and reading that
   * the ordinary way would empty the stored token on every save of an
   * unrelated setting. So here, and only here, input that holds nothing
   * means the stored value stands.
   *
   * Which leaves no way to remove a secret, so there is one:
   * DataSurfacePipelineInterface::CLEAR_SECRET, sent as the value, means
   * the key holds nothing from now on. Clearing is an explicit act, said
   * out loud, by a checkbox a host renders beside the box or by an API
   * caller that names the marker.
   *
   * Everything else about a secret is ordinary: the value is cast
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
   * @throws \Drupal\data_surface\Pipeline\ShapeMismatchException
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
    $refined = $surface->refine($values);
    $errors = [];
    foreach ($refined->getDefinitions() as $name => $definition) {
      $value = $values[$name] ?? NULL;
      if (!ValueState::isConfigured($value)) {
        // Not configured: nothing to hold to a constraint, and for a
        // required key the surface's own message rather than whichever
        // type-specific one a constraint would have produced. A key that
        // was never set is this case whether it is required or not, and
        // it is never stale: there is no value to keep.
        if ($definition->isRequired()) {
          $errors[] = new SurfaceViolation((string) $name, '', new TranslatableMarkup('@label is required.', [
            '@label' => $definition->getLabel() ?? $name,
          ]));
        }
        continue;
      }
      $typed_data = $this->typedDataManager->create($definition, $value, $name);
      $refusals = [];
      foreach ($typed_data->validate() as $violation) {
        // The message stays the object the constraint built. Flattening
        // it here would render its placeholders once, as plain text, and
        // whatever reads the violation afterwards would escape that text
        // a second time; a form error, a tool result and a log line each
        // render it themselves, at their own boundary.
        $refusals[] = new SurfaceViolation((string) $name, (string) $violation->getPropertyPath(), $violation->getMessage());
      }
      if ($refusals !== [] && $this->isStale($definition, $value, (string) $name, $current)) {
        // The whole key is reported as stale and not re-judged. What
        // else its constraints would say is about a value this run is
        // not changing and could not have chosen — it is what storage
        // holds — and saying it would read as a list of things to fix
        // where there is exactly one: choose again.
        $errors[] = $this->staleViolation($definition, $value, (string) $name);
        continue;
      }
      $errors = array_merge($errors, $refusals);
    }
    return new ViolationSet($errors);
  }

  /**
   * Answers whether a refused value is a stale reference.
   *
   * Three things have to be true at once, and each of them rules out a
   * case that is not stale:
   *
   * - The value is exactly what storage holds for that key. A value that
   *   differs was chosen by whoever sent it, so it is refused however
   *   far outside the list it falls. This is the whole line between
   *   "re-choose this" and "that is not a valid answer".
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
   *   The surface key.
   * @param array $current
   *   The stored values, in surface shape.
   *
   * @return bool
   *   TRUE when the refusal is a stale reference.
   */
  protected function isStale(DataDefinitionInterface $definition, mixed $value, string $name, array $current): bool {
    if ($definition instanceof ListDataDefinitionInterface) {
      return FALSE;
    }
    if (!array_key_exists($name, $current) || $current[$name] !== $value) {
      return FALSE;
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
   *   The surface key.
   *
   * @return \Drupal\data_surface\Pipeline\SurfaceViolation
   *   The stale entry.
   */
  protected function staleViolation(DataDefinitionInterface $definition, mixed $value, string $name): SurfaceViolation {
    return new SurfaceViolation($name, '', new TranslatableMarkup('@label keeps the value @value, which is no longer available. Choose a new one when you can.', [
      '@label' => $definition->getLabel() ?? $name,
      '@value' => (string) $value,
    ]), TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function conformOutput(DataSurfaceInterface $surface, array $output, array $input_values = []): ViolationSet {
    $definitions = $surface->refineOutputs($input_values)->getOutputDefinitions();
    // Omitted is the producer's way of saying "this key is not emitted"
    // from inside an array literal, so it is resolved into absence
    // before anything else looks at the array.
    $output = Omitted::strip($output);
    $errors = [];
    foreach (array_keys(array_diff_key($output, $definitions->toArray())) as $unknown) {
      $errors[] = new SurfaceViolation((string) $unknown, '', new TranslatableMarkup('Unknown output key @key.', [
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
          $errors[] = new SurfaceViolation($name, '', new TranslatableMarkup('@label was not emitted.', [
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
      $typed_data = $this->typedDataManager->create($definition, $output[$name], $name);
      foreach ($typed_data->validate() as $violation) {
        $errors[] = new SurfaceViolation($name, (string) $violation->getPropertyPath(), $violation->getMessage());
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
   * @return \Drupal\data_surface\Pipeline\SurfaceViolation[]
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
      $errors[] = new SurfaceViolation($key, $unknown_path, new TranslatableMarkup('Unknown output key @key.', [
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
   * question: input arrives as text and the casting table converts it.
   * An output is produced by code with the declaration in front of it,
   * nothing converts it, and a consumer reading `integer` in the emitted
   * schema will be handed whatever the producer put there. So the
   * question here is the strict one, and the same table of types the
   * casting rules cover is the table this answers for; anything else —
   * 'any', a contributed type — is left alone, because a rule it never
   * defined would be a guess.
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
   * @return \Drupal\data_surface\Pipeline\SurfaceViolation|null
   *   The violation, or NULL when the type is right or unknown here.
   */
  protected function outputTypeMismatch(DataDefinitionInterface $definition, mixed $value, string $key, string $path): ?SurfaceViolation {
    $type = $definition->getDataType();
    if (!in_array($type, self::CAST_TYPES, TRUE)) {
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
   * @return \Drupal\data_surface\Pipeline\SurfaceViolation
   *   The violation.
   */
  protected function outputShapeViolation(string $key, string $path, string $expected, mixed $value): SurfaceViolation {
    return new SurfaceViolation($key, $path, new TranslatableMarkup('This value must be of type @expected, @actual given.', [
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
  public function prepare(DataSurfaceInterface $surface, array $values, DataSurfaceTargetInterface $target): PreparedValues {
    return $target->prepare($surface, $values);
  }

  /**
   * {@inheritdoc}
   */
  public function commit(PreparedValues $prepared, DataSurfaceTargetInterface $target): void {
    $target->commit($prepared);
  }

  /**
   * {@inheritdoc}
   */
  public function submit(DataSurfaceInterface $surface, array $input, DataSurfaceTargetInterface $target, bool $dry_run = FALSE, ?AccessResultInterface $access = NULL): DataSurfaceResult {
    if ($access !== NULL && $access->isForbidden()) {
      // Before the target is touched, on purpose: a refused caller must
      // not be able to learn what storage holds by being refused, and a
      // load is a query, a cache entry and sometimes an entity build.
      // The values come back empty for the same reason.
      return new DataSurfaceResult([], $this->accessViolations($access), access: $access);
    }
    $current = $target->load($surface);
    try {
      $values = $this->accept($surface, $input, $current);
    }
    catch (UnknownKeysException $e) {
      return new DataSurfaceResult($current, $this->unknownKeyViolations($e), access: $access);
    }
    catch (ShapeMismatchException $e) {
      return new DataSurfaceResult($current, $this->shapeMismatchViolations($e), access: $access);
    }
    // What storage holds is what the stale rule is judged against, and
    // it was loaded a moment ago for the merge, so the question costs
    // nothing extra here.
    $violations = $this->validate($surface, $values, $current);
    if (!$violations->isEmpty()) {
      return new DataSurfaceResult($values, $violations, access: $access);
    }
    try {
      $prepared = $this->prepare($surface, $values, $target);
    }
    catch (TargetViolationsException $e) {
      return new DataSurfaceResult($values, $e->getViolations(), access: $access);
    }
    // The set travels on rather than being replaced by an empty one: it
    // blocks nothing, so the run is valid and committed, and the stale
    // references it carries are how a caller — a form warning, an
    // agent's dry run — learns what to re-choose.
    if ($dry_run) {
      return new DataSurfaceResult($values, $violations, $prepared, access: $access);
    }
    $this->commit($prepared, $target);
    return new DataSurfaceResult($values, $violations, $prepared, TRUE, $access);
  }

  /**
   * Accepts one value against one definition.
   *
   * Not-configured input means the key holds nothing, which is NULL for
   * every type but a list, where it means the list is untouched and the
   * fallback stands. Configured input is then placed by shape: a list
   * normalizes to a clean list of cast items, a complex definition with
   * declared properties merges the input over what the level already
   * holds and recurses, and everything else goes through the casting
   * table. A complex definition that declares no properties describes
   * nothing to recurse into, so its value passes through untouched.
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
   * @throws \Drupal\data_surface\Pipeline\ShapeMismatchException
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
      return $this->cast($definition, $input, $path);
    }
    if (!is_array($input)) {
      throw new ShapeMismatchException($path, 'map', get_debug_type($input));
    }
    $unknown = array_keys(array_diff_key($input, $properties));
    if ($unknown !== []) {
      throw new UnknownKeysException(array_map('strval', $unknown), $path);
    }
    // The result holds exactly the declared properties, in declaration
    // order: a key storage carries that no property declares is
    // storage's own business and does not round-trip through the
    // surface. Each property merges the same way its parent did —
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
   * Accepts a list value against a list definition.
   *
   * Input reaches a list in whatever shape its sender had: a multiple
   * select submits the chosen values keyed by themselves, a payload
   * sends a plain list, and both may carry the empty entries a form
   * leaves behind. All of them mean the same list of items, so the keys
   * are dropped, the not-configured entries with them, and what survives
   * is cast through the item definition — recursing when the items are
   * complex.
   *
   * A list is the one place where not-configured input does not mean
   * NULL: a form that renders no list widget, or a payload that names no
   * list, has said nothing about the list, so what the list already
   * holds stands.
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
   * @throws \Drupal\data_surface\Pipeline\ShapeMismatchException
   *   When configured input for a list is not an array.
   */
  protected function acceptList(ListDataDefinitionInterface $definition, mixed $input, mixed $fallback, string $path): mixed {
    if (!ValueState::isConfigured($input)) {
      return $fallback;
    }
    if (!is_array($input)) {
      throw new ShapeMismatchException($path, 'list', get_debug_type($input));
    }
    $item_definition = $definition->getItemDefinition();
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
   * Casts one configured raw value to its definition's native type.
   *
   * The casting table, and the only place types are converted. Its one
   * rule is that a cast never loses information: a notation that means
   * the same value is converted, and anything else is left exactly as it
   * arrived so that a constraint refuses it with a message about the
   * value rather than a cast quietly turning it into a different one.
   * Casting a value that cannot be held at all — an array for a number —
   * is not a conversion but a mistake, so it is refused here.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to cast through.
   * @param mixed $value
   *   The raw value; always configured, since accept() coerces the rest
   *   to NULL before reaching here.
   * @param string $path
   *   The dotted path of this value, for refusal reporting.
   *
   * @return mixed
   *   The value in the definition's native type, NULL when the cast
   *   produced a value that is itself not configured, and the value
   *   untouched when no rule applies.
   *
   * @throws \Drupal\data_surface\Pipeline\ShapeMismatchException
   *   When an array arrives for a type that holds a single value.
   */
  protected function cast(DataDefinitionInterface $definition, mixed $value, string $path): mixed {
    $type = $definition->getDataType();
    if (!in_array($type, self::CAST_TYPES, TRUE)) {
      return $value;
    }
    if (is_array($value)) {
      throw new ShapeMismatchException($path, $type, get_debug_type($value));
    }
    $cast = match ($type) {
      'integer' => $this->castInteger($value),
      'float' => $this->castFloat($value),
      'boolean' => $this->castBoolean($value),
      default => is_scalar($value) ? (string) $value : $value,
    };
    // A cast whose result is itself not configured — a boolean FALSE
    // asked for as a string is the only way to get there — means the
    // same as not configuring the key at all.
    return ValueState::isConfigured($cast) ? $cast : NULL;
  }

  /**
   * Casts a configured raw value to an integer.
   *
   * An integer literal is an integer however it is spelled, so a string
   * of digits and a float that happens to be whole both become one. A
   * number with a fraction does not: truncating 1.9 to 1 stores a
   * different number than the caller sent, so it is left for the
   * constraint to refuse. A literal too large for a PHP integer is left
   * alone for the same reason.
   *
   * @param mixed $value
   *   The raw value.
   *
   * @return mixed
   *   The integer, or the value untouched when casting would lose
   *   information.
   */
  protected function castInteger(mixed $value): mixed {
    if (is_int($value)) {
      return $value;
    }
    if (is_float($value)) {
      $number = $value;
    }
    elseif (is_string($value)) {
      $trimmed = trim($value);
      if (!is_numeric($trimmed)) {
        return $value;
      }
      // Adding zero yields an integer for an integer literal in range
      // and a float for everything else, which is exactly the
      // distinction this rule needs.
      $number = $trimmed + 0;
    }
    else {
      return $value;
    }
    if (is_int($number)) {
      return $number;
    }
    $whole = is_finite($number)
      && floor($number) === $number
      && $number > (float) PHP_INT_MIN
      && $number < (float) PHP_INT_MAX;
    return $whole ? (int) $number : $value;
  }

  /**
   * Casts a configured raw value to a float.
   *
   * Every number is a float without losing anything, and so is every
   * numeric string. Nothing else is a number.
   *
   * @param mixed $value
   *   The raw value.
   *
   * @return mixed
   *   The float, or the value untouched when it is not a number.
   */
  protected function castFloat(mixed $value): mixed {
    if (is_float($value)) {
      return $value;
    }
    if (is_int($value)) {
      return (float) $value;
    }
    if (is_string($value)) {
      $trimmed = trim($value);
      if (is_numeric($trimmed)) {
        return (float) $trimmed;
      }
    }
    return $value;
  }

  /**
   * Casts a configured raw value to a boolean.
   *
   * Form API hands a checkbox back as an integer, a query string hands
   * it back as '1' or '0', a YAML config action hands back 'yes' or
   * 'off', and a JSON payload hands back a real boolean. All of them
   * mean the same thing here. Anything else is left for validation to
   * refuse rather than being folded into TRUE, which is what made the
   * word 'off' arrive as on.
   *
   * @param mixed $value
   *   The raw value.
   *
   * @return mixed
   *   The boolean, or the value untouched when it spells neither.
   */
  protected function castBoolean(mixed $value): mixed {
    if (is_bool($value)) {
      return $value;
    }
    if ($value === 1 || $value === 0) {
      return (bool) $value;
    }
    if (!is_string($value)) {
      return $value;
    }
    return match (strtolower(trim($value))) {
      '1', 'true', 'on', 'yes' => TRUE,
      '0', 'false', 'off', 'no' => FALSE,
      default => $value,
    };
  }

  /**
   * Turns a refusal by access into the one violation it is.
   *
   * Filed under the reserved key rather than under a surface key,
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
      new SurfaceViolation(self::ACCESS_VIOLATION_KEY, '', DataSurfaceAccess::message($access)),
    ]);
  }

  /**
   * Turns refused keys into violations in the shape validation returns.
   *
   * The key a violation is filed under is always a surface key, so a
   * nested unknown key is reported on its top-level key with the rest of
   * its path, exactly like a constraint violation inside a map.
   *
   * @param \Drupal\data_surface\Pipeline\UnknownKeysException $exception
   *   The refusal from accept().
   *
   * @return \Drupal\data_surface\Pipeline\ViolationSet
   *   The violations, filed under their surface keys.
   */
  protected function unknownKeyViolations(UnknownKeysException $exception): ViolationSet {
    $violations = [];
    foreach ($exception->getKeys() as $key) {
      $path = $exception->getPath() === '' ? $key : $exception->getPath() . '.' . $key;
      $segments = explode('.', $path);
      $name = array_shift($segments);
      $violations[] = new SurfaceViolation(
        (string) $name,
        implode('.', $segments),
        new TranslatableMarkup('Unknown key @key.', ['@key' => $path]),
      );
    }
    return new ViolationSet($violations);
  }

  /**
   * Turns a shape mismatch into a violation, the way unknown keys are.
   *
   * @param \Drupal\data_surface\Pipeline\ShapeMismatchException $exception
   *   The refusal from accept().
   *
   * @return \Drupal\data_surface\Pipeline\ViolationSet
   *   One violation, filed under the surface key that carries the value.
   */
  protected function shapeMismatchViolations(ShapeMismatchException $exception): ViolationSet {
    $segments = explode('.', $exception->getPath());
    $name = array_shift($segments);
    return new ViolationSet([
      new SurfaceViolation(
        (string) $name,
        implode('.', $segments),
        new TranslatableMarkup('This value must be of type @expected, @actual given.', [
          '@expected' => $exception->getExpected(),
          '@actual' => $exception->getActual(),
        ]),
      ),
    ]);
  }

}
