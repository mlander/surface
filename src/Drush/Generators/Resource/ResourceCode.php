<?php

declare(strict_types=1);

namespace Drupal\surface\Drush\Generators\Resource;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\SchemaFacts;
use Drupal\surface\Contract\Secrets;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Entry;
use Drupal\surface\Reference\References;
use Drupal\surface\Refinement\Condition;
use Drupal\surface\Refinement\DeclaredRefiner;
use Drupal\surface\Refinement\Rule;
use PortableSchema\Builder\Schema;

/**
 * Writes the defineInputs() of a built resource back out as PHP.
 *
 * The resource generator builds what it is about to write (the answers of
 * its interview, or a schema read by the schema reader) and this writes
 * the built keys as the calls an author would make: add() with the
 * schema of a value or a list (a reference among them, its check
 * written), its label the schema's title and its description, bounds,
 * checks, default and requiredness the schema's own calls; addMap() for
 * a map, attach() and attachList() of a structure written as a class of
 * its own, and narrow()->when() for each declared rule, its effect a
 * schema. What it cannot write
 * as a call is written as a comment above the key it is about, naming it:
 * a constraint the reader kept as JsonSchema (a foreign constraint), with
 * the keywords it holds; variants, a mixin, a port; and, for a config
 * schema, what its document could not carry. The optional keys a schema
 * says are always held are the storage's ALWAYS_HELD.
 *
 * Each frame's keys are named once, as constants of its class in
 * declaration order (constantNames()), and every call and comment after
 * names the key by its constant: self::VENUE, never 'venue'.
 *
 * Decision: see
 * docs/decisions.md#the-generator-writes-what-the-first-page-shows.
 */
final class ResourceCode {

  /**
   * Where a statement of defineInputs() starts.
   */
  protected const INDENT = 4;

  /**
   * How long a comment line may be.
   */
  protected const WIDTH = 80;

  /**
   * The constraints a config schema may state that a resource must state.
   *
   * The build holds a resource stored in a config object to these where
   * its schema states them (HeldToConfigSchemaTrait), so one left out is
   * refused until it is written. Which ids, not their keywords
   * (ConstraintKeywords).
   */
  protected const HELD = ['Regex', 'Length', 'Range', 'Choice', 'Count', 'NotBlank', 'Email'];

  /**
   * The classes the code being written uses, by name.
   *
   * @var array<string, true>
   */
  protected array $uses = [];

  /**
   * The structures written so far, by what each was built from.
   *
   * @var array<string, array{class: string, id: string, label: string, constants: string, inputs: string, uses: string[]}>
   */
  protected array $structures = [];

  /**
   * The optional keys the schema says are always held, by dotted path.
   *
   * @var string[]
   */
  protected array $alwaysHeld = [];

  /**
   * How many comments name something left for the author to write.
   */
  protected int $comments = 0;

  /**
   * The keys of the frame being written that have no label of their own.
   *
   * @var string[]
   */
  protected array $unlabeled = [];

  /**
   * The constant of each key of the frame being written, by key.
   *
   * @var array<string, string>
   */
  protected array $constants = [];

  /**
   * Constructs a ResourceCode.
   *
   * @param string $module
   *   The module the code is written in.
   * @param string $id
   *   The resource's id, which a structure's id starts with.
   * @param array<string, array{constraints?: array<string, mixed>, type?: string, default?: mixed}> $left
   *   What a config schema states that its document does not, by dotted
   *   key path.
   */
  public function __construct(
    protected readonly string $module,
    protected readonly string $id,
    protected readonly array $left = [],
  ) {}

  /**
   * Writes a built resource's keys.
   *
   * @param \Drupal\surface\SurfaceInterface $built
   *   The resource, sealed as declared.
   *
   * @return array{constants: string, inputs: string, uses: string[], structures: list<array{class: string, id: string, label: string, constants: string, inputs: string, uses: string[]}>, always_held: string[], comments: int}
   *   The constants that name its keys, the body of its defineInputs(),
   *   the classes it uses, the structures it attaches, each written the
   *   same way, the keys its storage always holds, and how many comments
   *   name what is left to write.
   */
  public function write(SurfaceInterface $built): array {
    $this->uses = [];
    $this->structures = [];
    $this->alwaysHeld = [];
    $this->comments = 0;
    [$constants, $inputs] = $this->frame($built, '');
    return [
      'constants' => $constants,
      'inputs' => $inputs,
      'uses' => static::sorted($this->uses),
      'structures' => array_values($this->structures),
      'always_held' => $this->alwaysHeld,
      'comments' => $this->comments,
    ];
  }

  /**
   * Writes the statements of one frame.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The frame.
   * @param string $path
   *   Its dotted path in the resource, a dot at its end; empty at the top.
   *
   * @return array{0: string, 1: string}
   *   The constants that name its keys, and its statements, a blank line
   *   between each two.
   *
   * @phpstan-impure
   */
  protected function frame(SurfaceInterface $surface, string $path): array {
    $map = $surface->entries();
    // A structure's frame is written while its parent's is: each names
    // its own keys.
    $parent_constants = $this->constants;
    $this->constants = static::constantNames(array_map('strval', array_keys($map->entries())));
    $constants = [];
    foreach (array_keys($map->entries()) as $name) {
      $constants[] = $this->constant((string) $name);
    }
    $statements = [];
    $top = $path === '' ? $this->leftComments('', '', FALSE) : [];
    foreach ($map->frameConstraints() as $schema) {
      $top[] = sprintf('From the schema, not written here: %s on the whole, which no key states; the reader keeps it as a JsonSchema constraint. Write it as a rule or a #[RefinesInput] method.', static::keywords($schema));
      $this->comments++;
    }
    foreach ($map->mixins() as $mixin) {
      $top[] = sprintf('From the schema, written as keys of this class: %s, a structure mixed in. Mix it in with mixIn() once it is a class.', implode(', ', array_map($this->named(...), array_map('strval', $mixin->keys))));
      $this->comments++;
    }
    foreach ($map->ports() as $port) {
      $top[] = sprintf('From the schema, not written here: the port %s, a value read from the container. Declare it with reads().', $port->name);
      $this->comments++;
    }
    if ($top !== []) {
      $statements[] = $this->comment($top);
    }
    $rules = [];
    $parent_unlabeled = $this->unlabeled;
    $this->unlabeled = [];
    $keys = [];
    foreach ($map->entries() as $name => $entry) {
      $keys[] = $this->key($surface, $entry, $path);
      foreach ($entry->refiners[SurfaceInterface::DECLARING_CLASS] ?? [] as $refiner) {
        if ($refiner instanceof DeclaredRefiner && $refiner->module === NULL && $refiner->at === NULL) {
          foreach ($refiner->rules[(string) $name] ?? [] as $rule) {
            $rules[spl_object_id($rule)] = $rule;
          }
        }
      }
    }
    if ($this->unlabeled !== []) {
      $statements[] = $this->comment([sprintf('The schema gives no label to %s: each is named from its key.', implode(', ', array_map($this->named(...), $this->unlabeled)))]);
    }
    $this->unlabeled = $parent_unlabeled;
    $statements = [...$statements, ...$keys];
    foreach ($rules as $rule) {
      $statements[] = $this->rule($rule);
    }
    $this->constants = $parent_constants;
    return [
      implode("\n", $constants),
      implode("\n\n", array_filter($statements, static fn (string $statement): bool => $statement !== '')),
    ];
  }

  /**
   * Names each key of a frame as a constant of its class.
   *
   * A key's constant is the key upper-cased, a camelCase key split at
   * each capital, and every run of characters other than ASCII letters
   * and digits one underscore; a key that would start with a digit, or
   * leave nothing, is prefixed KEY_. Where two keys would share a name,
   * or a key would be named CLASS, which PHP reserves, the later key in
   * declaration order takes the first free suffix: _2, _3, and so on.
   *
   * @param string[] $keys
   *   The keys, in declaration order.
   *
   * @return array<string, string>
   *   Each key's constant, by key.
   */
  public static function constantNames(array $keys): array {
    $names = [];
    $taken = ['CLASS' => TRUE];
    foreach ($keys as $key) {
      $name = (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $key);
      $name = strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $name), '_'));
      if ($name === '' || ctype_digit($name[0])) {
        $name = rtrim('KEY_' . $name, '_');
      }
      $unique = $name;
      for ($i = 2; isset($taken[$unique]); $i++) {
        $unique = $name . '_' . $i;
      }
      $taken[$unique] = TRUE;
      $names[$key] = $unique;
    }
    return $names;
  }

  /**
   * Writes the constant that names one key.
   *
   * No docblock: the label on the key's add() line says what the key is,
   * and the bare constants read as the method's table of contents.
   *
   * @param string $name
   *   The key.
   *
   * @return string
   *   The line, indented as a class member.
   */
  protected function constant(string $name): string {
    return sprintf('  public const %s = %s;', $this->constants[$name], PhpLiteral::string($name));
  }

  /**
   * Writes a key as an argument: its constant, where its frame names it.
   *
   * @param string $name
   *   The key.
   *
   * @return string
   *   The expression.
   */
  protected function ref(string $name): string {
    return isset($this->constants[$name]) ? 'self::' . $this->constants[$name] : PhpLiteral::string($name);
  }

  /**
   * Names a key in a comment: its constant, where its frame names it.
   *
   * @param string $name
   *   The key.
   *
   * @return string
   *   The words.
   */
  protected function named(string $name): string {
    return isset($this->constants[$name]) ? 'self::' . $this->constants[$name] : $name;
  }

  /**
   * Writes one key's statement, and the comments above it.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The frame.
   * @param \Drupal\surface\Entry $entry
   *   The key.
   * @param string $path
   *   The frame's dotted path, a dot at its end; empty at the top.
   *
   * @return string
   *   The lines.
   *
   * @phpstan-impure
   */
  protected function key(SurfaceInterface $surface, Entry $entry, string $path): string {
    $name = $entry->name;
    $at = $path . $name;
    // A key's facts are read from its schema, a key that holds a child
    // frame's too (Entry::schema()).
    $schema = $entry->schema();
    $comments = $this->leftComments($at, $name, $entry->attachment === NULL && $entry->list === NULL);
    if ($entry->variants !== NULL) {
      $comments[] = sprintf('From the schema, not written here: %s holds variants chosen by %s. Declare each as a #[Variant] structure and attach them with attachVariants(%s, by: %s).', $this->named($name), $this->named($entry->variants->by), $this->ref($name), $this->ref($entry->variants->by));
      $this->comments++;
      return $this->comment($comments);
    }
    if ($entry->attachment !== NULL && SchemaFacts::each($schema) !== NULL) {
      $comments[] = sprintf('From the schema, not written here: %s holds one entry per thing the site has. Declare it with addEach() or attachEach() and a provider.', $this->named($name));
      $this->comments++;
      return $this->comment($comments);
    }
    [$label, $unlabeled] = $this->label(SchemaFacts::ownLabel($schema) ?? SchemaFacts::label($schema), $name);
    if ($unlabeled) {
      $this->unlabeled[] = $name;
    }
    if ($entry->list !== NULL || $entry->attachment !== NULL) {
      $child = $entry->list !== NULL ? $entry->list->item : $entry->attachment;
      $class = $this->structure($child->child, $child->source ?? $at, $name, $label);
      if ($entry->list !== NULL) {
        $count = SchemaFacts::declaredConstraints($schema)['Count'] ?? [];
        $bounds = '';
        foreach (['min', 'max'] as $bound) {
          $bounds .= is_array($count) && isset($count[$bound]) ? sprintf(', %s: %s', $bound, PhpLiteral::scalar($count[$bound])) : '';
        }
        $head = sprintf('$inputs->attachList(%s, %s::class, %s%s%s)', $this->ref($name), $class, PhpLiteral::translated($label), $bounds, $this->attachArguments($schema));
      }
      else {
        $head = sprintf('$inputs->attach(%s, %s::class, %s%s)', $this->ref($name), $class, PhpLiteral::translated($label), $this->attachArguments($schema));
      }
      $calls = [];
    }
    elseif (static::namesDigits($schema)) {
      // A map whose keys a schema cannot name: addMap() declares them.
      $default = $surface->getDefault($name);
      $head = $this->mapCall($name, $schema, $label, is_array($default) ? $default : [], $comments);
      $calls = [];
    }
    else {
      [$base, $calls] = $this->schemaOf($schema, $label, $surface->getDefault($name), $this->named($name), $comments, self::INDENT + 2);
      $head = sprintf('$inputs->add(%s, %s', $this->ref($name), $base);
      $calls = static::closed($calls, $head);
    }
    if ($entry->locked) {
      $comments[] = sprintf('The schema makes %s readOnly, at the value it starts from: lock it with an access class, or leave it out.', $this->named($name));
      $this->comments++;
    }
    if (Secrets::marked($schema)) {
      $comments[] = sprintf('The schema makes %s writeOnly: a secret, which a storage holds apart. See docs/declaring-a-resource.md.', $this->named($name));
      $this->comments++;
    }
    if (SchemaFacts::isAlwaysHeld($schema) && !SchemaFacts::isRequired($schema)) {
      $this->alwaysHeld[] = $at;
    }
    return ($comments === [] ? '' : $this->comment($comments) . "\n") . static::chain($head, $calls);
  }

  /**
   * Writes the schema of a value or a list, as add() takes it.
   *
   * The schema the verbs replay writes: its base (`Schema::string()`, a
   * list of its item, `Schema::options()` of a labeled list,
   * `Schema::enum()` of a list of values), then its title, description,
   * format, bounds, checks, settings, default and whether it is required,
   * each a call a line.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The key's schema.
   * @param string|null $label
   *   Its label; NULL for a list's item with none of its own.
   * @param mixed $default
   *   What it starts from.
   * @param string $at
   *   The key, as a comment names it; empty for a list's item.
   * @param string[] $comments
   *   The comments above the key, added to.
   * @param int $indent
   *   The indentation of the chained calls.
   *
   * @return array{0: string, 1: string[]}
   *   The base, as it opens the expression, and the chained calls.
   */
  protected function schemaOf(Schema $schema, ?string $label, mixed $default, string $at, array &$comments, int $indent): array {
    $this->uses[Schema::class] = TRUE;
    $calls = [];
    $declared = SchemaFacts::declaredConstraints($schema);
    if (DefinitionSchema::isComplex($schema) && !DefinitionSchema::isList($schema)) {
      $base = $this->objectOf($schema, is_array($default) ? $default : [], $comments, $indent - 2);
      // What a map starts from is its keys' defaults, written inside it.
      $default = NULL;
    }
    elseif (DefinitionSchema::isList($schema)) {
      $item = static::itemOf($schema);
      $own = SchemaFacts::ownLabel($item);
      [$item_base, $item_calls] = $this->schemaOf($item, $own !== NULL && (string) $own !== '' ? static::text($own) : NULL, NULL, '', $comments, $indent + 2);
      $base = 'Schema::list(' . $item_base . static::calls($item_calls, $indent + 2) . ')';
    }
    else {
      [$base, $declared, $format] = $this->base($schema, $declared, $indent - 2);
    }
    if ($label !== NULL) {
      $calls[] = '->title(' . PhpLiteral::translated($label) . ')';
    }
    $description = SchemaFacts::description($schema);
    if ((is_string($description) || $description instanceof \Stringable) && (string) $description !== '') {
      $calls[] = '->description(' . PhpLiteral::translated(static::text($description)) . ')';
    }
    if (isset($format)) {
      $calls[] = '->format(' . PhpLiteral::string($format) . ')';
    }
    $calls = [...$calls, ...$this->ruleCalls($declared, $at, $comments, $indent)];
    $settings = is_a(DefinitionSchema::classOf($schema), DataDefinition::class, TRUE) ? SchemaFacts::settings($schema) : [];
    // The build writes the content mark on a reference key itself.
    unset($settings[References::CONTENT]);
    if ($settings !== []) {
      $calls[] = "->annotation('x-surface-settings', " . PhpLiteral::write($settings, $indent) . ')';
    }
    if ($default !== NULL) {
      $calls[] = '->default(' . PhpLiteral::write($default, $indent) . ')';
    }
    if (SchemaFacts::isRequired($schema)) {
      $calls[] = '->required()';
    }
    return [$base, $calls];
  }

  /**
   * Writes the base of a value's schema, and what it says of its own.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The value's schema.
   * @param array $declared
   *   Its declared constraints, by plugin id.
   * @param int $indent
   *   The indentation of the statement the base opens.
   *
   * @return array{0: string, 1: array, 2: string|null}
   *   The base; the constraints it does not say; the format to write.
   */
  protected function base(Schema $schema, array $declared, int $indent): array {
    $type = SchemaFacts::dataType($schema);
    $format = match ($type) {
      'email' => 'email',
      'uri' => 'uri',
      'datetime_iso8601' => 'date-time',
      default => NULL,
    };
    // Core's email type holds its Email constraint by itself.
    if ($type === 'email' && ($declared['Email'] ?? NULL) === []) {
      unset($declared['Email']);
    }
    if (array_key_exists('Uuid', $declared) && in_array($declared['Uuid'], [[], NULL], TRUE) && $format === NULL) {
      // The format the reader reads as the Uuid constraint.
      unset($declared['Uuid']);
      $format = 'uuid';
    }
    $labeled = isset($declared['LabeledChoice']) && is_array($declared['LabeledChoice']) ? static::labeledChoice($declared['LabeledChoice']) : NULL;
    if ($labeled !== NULL && array_keys($labeled) === ['labels']) {
      unset($declared['LabeledChoice']);
      $options = PhpLiteral::write($labeled['labels'], $indent, static fn (array $path): bool => count($path) === 1);
      return ['Schema::options(' . $options . ')', $declared, $format];
    }
    $choice = $declared['Choice'] ?? NULL;
    if (is_array($choice) && array_keys($choice) === ['choices'] && is_array($choice['choices']) && $choice['choices'] !== []) {
      unset($declared['Choice']);
      return ['Schema::enum(' . PhpLiteral::write(array_values($choice['choices']), $indent) . ')', $declared, $format];
    }
    $base = match ($type) {
      'integer' => 'Schema::integer()',
      'float' => 'Schema::number()',
      'boolean' => 'Schema::bool()',
      'timestamp' => "Schema::of('portable:timestamp')",
      default => 'Schema::string()',
    };
    return [$base, $declared, $format];
  }

  /**
   * Writes a value's own constraints as the schema's keywords and checks.
   *
   * A bound is its keywords (`maxLength`, `minimum` and so on), a list's
   * uniqueness `uniqueItems`, any other constraint the check of it with
   * its options; a JsonSchema constraint is a comment naming its keywords.
   *
   * @param array $declared
   *   The constraints, by plugin id.
   * @param string $at
   *   The key, as a comment names it; empty for a list's item.
   * @param string[] $comments
   *   The comments above the key, added to.
   * @param int $indent
   *   The indentation of the chained calls.
   *
   * @return string[]
   *   The chained calls.
   */
  protected function ruleCalls(array $declared, string $at, array &$comments, int $indent): array {
    $bounds = [
      'Length' => ['min' => 'minLength', 'max' => 'maxLength'],
      'Range' => ['min' => 'minimum', 'max' => 'maximum'],
      'Count' => ['min' => 'minItems', 'max' => 'maxItems'],
    ];
    $calls = [];
    foreach ($declared as $constraint => $options) {
      $constraint = (string) $constraint;
      if ($constraint === 'JsonSchema') {
        $json_schema = is_array($options['schema'] ?? NULL) ? $options['schema'] : [];
        $comments[] = sprintf('From the schema, not written here: %s on %s, which no constraint here states; the reader keeps it as a JsonSchema constraint. Write a constraint for it.', static::keywords($json_schema), $at === '' ? 'each item' : $at);
        $this->comments++;
        continue;
      }
      if (isset($bounds[$constraint]) && is_array($options) && $options !== [] && array_diff_key($options, $bounds[$constraint]) === []) {
        foreach ($bounds[$constraint] as $option => $keyword) {
          if (array_key_exists($option, $options)) {
            $calls[] = sprintf('->%s(%s)', $keyword, PhpLiteral::write($options[$option], $indent));
          }
        }
        continue;
      }
      if ($constraint === 'UniqueItems' && in_array($options, [[], NULL], TRUE)) {
        $calls[] = '->uniqueItems(TRUE)';
        continue;
      }
      if ($constraint === 'LabeledChoice' && is_array($options)) {
        $options = static::labeledChoice($options);
      }
      $translate = static fn (array $path): bool => in_array(end($path), ['labels', 'descriptions', 'message'], TRUE)
        || (count($path) >= 2 && in_array($path[count($path) - 2], ['labels', 'descriptions'], TRUE))
        || ($constraint === 'LabeledChoice' && count($path) === 2 && $path[0] === 'choices' && is_string($path[1]));
      $calls[] = is_array($options) && $options !== []
        ? sprintf('->check(%s, %s)', PhpLiteral::string('drupal:' . $constraint), PhpLiteral::write($options, $indent, $translate))
        : sprintf('->check(%s)', PhpLiteral::string('drupal:' . $constraint));
    }
    return $calls;
  }

  /**
   * Closes the add() call a key's schema opens.
   *
   * @param string[] $calls
   *   The schema's chained calls.
   * @param string $head
   *   The call's head, closed in place where the schema chains nothing.
   *
   * @return string[]
   *   The calls, the last one closing add().
   */
  protected static function closed(array $calls, string &$head): array {
    if ($calls === []) {
      $head .= ')';
      return [];
    }
    $calls[count($calls) - 1] .= ')';
    return $calls;
  }

  /**
   * Gets the schema of a list's item.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The list's schema.
   *
   * @return \PortableSchema\Builder\Schema
   *   Its item's schema.
   *
   * @throws \LogicException
   *   Where the schema holds none: a built list's schema holds its item's.
   */
  protected static function itemOf(Schema $schema): Schema {
    return DefinitionSchema::itemOf($schema) ?? throw new \LogicException('A list\'s schema holds no schema of its item: a built schema holds one.');
  }

  /**
   * Answers whether a map names a key with digits alone.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The key's schema.
   *
   * @return bool
   *   TRUE for a map one of whose keys is digits alone, which an object
   *   schema refuses to name, since it would be served as a list.
   */
  protected static function namesDigits(Schema $schema): bool {
    if (!DefinitionSchema::isComplex($schema) || DefinitionSchema::isList($schema)) {
      return FALSE;
    }
    foreach (array_keys(SchemaFacts::properties($schema)) as $name) {
      if (ctype_digit((string) $name)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Writes a map whose keys a schema cannot name as addMap() with its keys.
   *
   * @param string $key
   *   The key.
   * @param \PortableSchema\Builder\Schema $map
   *   The map's schema.
   * @param string $label
   *   Its label.
   * @param array $default
   *   What it starts from, by key.
   * @param string[] $comments
   *   The comments above the key, added to.
   *
   * @return string
   *   The call, closed.
   */
  protected function mapCall(string $key, Schema $map, string $label, array $default, array &$comments): string {
    $inner = self::INDENT + 2;
    $lines = '';
    foreach (SchemaFacts::properties($map) as $name => $property) {
      $name = (string) $name;
      [$property_label] = $this->label(SchemaFacts::ownLabel($property) ?? SchemaFacts::label($property), $name);
      $starts = $default[$name] ?? (SchemaFacts::hasDefault($property) ? SchemaFacts::default($property) : NULL);
      [$base, $calls] = $this->schemaOf($property, $property_label, $starts, '', $comments, $inner + 2);
      $head = sprintf('$map->add(%s, %s', PhpLiteral::string($name), $base);
      $calls = static::closed($calls, $head);
      $lines .= "\n" . str_repeat(' ', $inner) . $head . static::calls($calls, $inner + 2) . ';';
    }
    $description = SchemaFacts::description($map);
    $argument = (is_string($description) || $description instanceof \Stringable) && (string) $description !== ''
      ? ', description: ' . PhpLiteral::translated(static::text($description))
      : '';
    return sprintf('$inputs->addMap(%s, %s, function (BlueprintInterface $map): void {', $this->ref($key), PhpLiteral::translated($label)) . $lines . "\n" . str_repeat(' ', self::INDENT) . '}' . $argument . ')';
  }

  /**
   * Writes a map's keys as the object schema that holds them.
   *
   * @param \PortableSchema\Builder\Schema $map
   *   The map's schema.
   * @param array $default
   *   What the map starts from, by key.
   * @param string[] $comments
   *   The comments above the key, added to.
   * @param int $indent
   *   The indentation of the statement the object opens.
   *
   * @return string
   *   `Schema::object([...])`, each key a schema.
   */
  protected function objectOf(Schema $map, array $default, array &$comments, int $indent): string {
    $inner = $indent + 2;
    $lines = '';
    foreach (SchemaFacts::properties($map) as $name => $property) {
      $name = (string) $name;
      [$property_label] = $this->label(SchemaFacts::ownLabel($property) ?? SchemaFacts::label($property), $name);
      $starts = $default[$name] ?? (SchemaFacts::hasDefault($property) ? SchemaFacts::default($property) : NULL);
      [$base, $calls] = $this->schemaOf($property, $property_label, $starts, '', $comments, $inner + 2);
      $lines .= "\n" . str_repeat(' ', $inner) . PhpLiteral::string($name) . ' => ' . $base . static::calls($calls, $inner + 2) . ',';
    }
    return 'Schema::object([' . $lines . "\n" . str_repeat(' ', $indent) . '])';
  }

  /**
   * Writes a structure a key attaches, as a class of its own.
   *
   * @param \Drupal\surface\SurfaceInterface $child
   *   The structure, built.
   * @param string $source
   *   What it was built from: one class for one source.
   * @param string $name
   *   The key it is attached at.
   * @param string $label
   *   The key's label.
   *
   * @return string
   *   The class's short name.
   */
  protected function structure(SurfaceInterface $child, string $source, string $name, string $label): string {
    if (!isset($this->structures[$source])) {
      $class = static::className($name) . 'Structure';
      $taken = array_column($this->structures, 'class');
      for ($i = 2; in_array($class, $taken, TRUE); $i++) {
        $class = static::className($name) . $i . 'Structure';
      }
      // Its own frame: what it uses is its own.
      $uses = $this->uses;
      $this->uses = [];
      $this->structures[$source] = [
        'class' => $class,
        'id' => '',
        'label' => $label,
        'constants' => '',
        'inputs' => '',
        'uses' => [],
      ];
      [$constants, $inputs] = $this->frame($child, $name . '.');
      $this->structures[$source] = [
        'class' => $class,
        'id' => $this->id . '.' . $name,
        'label' => $label,
        'constants' => $constants,
        'inputs' => $inputs,
        'uses' => static::sorted($this->uses),
      ];
      $this->uses = $uses;
    }
    $this->uses['Drupal\\' . $this->module . '\\Structure\\' . $this->structures[$source]['class']] = TRUE;
    return $this->structures[$source]['class'];
  }

  /**
   * Writes what an attach verb is told by name: description and required.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The key's schema.
   *
   * @return string
   *   The arguments, each starting with a comma; empty for none.
   */
  protected function attachArguments(Schema $schema): string {
    $arguments = '';
    $description = SchemaFacts::description($schema);
    if ((is_string($description) || $description instanceof \Stringable) && (string) $description !== '') {
      $arguments .= ', description: ' . PhpLiteral::translated(static::text($description));
    }
    if (SchemaFacts::isRequired($schema)) {
      $arguments .= ', required: TRUE';
    }
    return $arguments;
  }

  /**
   * Writes a LabeledChoice read from a schema in its plain spelling.
   *
   * The reader gives `choices` and a label for each; where every value is
   * the key PHP keeps it under, the labels alone say both, the spelling
   * docs/options.md reaches for first.
   *
   * @param array $options
   *   The constraint's options.
   *
   * @return array
   *   The options, `labels` alone where they say every value.
   */
  protected static function labeledChoice(array $options): array {
    $choices = $options['choices'] ?? NULL;
    $labels = $options['labels'] ?? NULL;
    if (!is_array($choices) || !array_is_list($choices) || !is_array($labels) || count($labels) !== count($choices)) {
      return $options;
    }
    $ordered = [];
    foreach ($choices as $choice) {
      if ((!is_int($choice) && !is_string($choice)) || !array_key_exists($choice, $labels)) {
        return $options;
      }
      $ordered[$choice] = $labels[$choice];
    }
    // A numeric string is no key PHP keeps as it is ('1' becomes 1).
    if (array_keys($ordered) !== $choices) {
      return $options;
    }
    unset($options['choices']);
    return ['labels' => $ordered] + $options;
  }

  /**
   * Writes what a rule adds, as the schema Rule::constrain() takes.
   *
   * A bound with no message is its keywords (`maximum`, `minLength` and so
   * on), the values to keep `enum`, anything else the check of its
   * constraint with its options.
   *
   * @param string $constraint
   *   The constraint the rule holds.
   * @param array $options
   *   Its options.
   *
   * @return string
   *   The schema, as PHP.
   */
  protected function ruleSchema(string $constraint, array $options): string {
    $this->uses[Schema::class] = TRUE;
    $keywords = [
      'Range' => ['min' => 'minimum', 'max' => 'maximum'],
      'Length' => ['min' => 'minLength', 'max' => 'maxLength'],
      'Count' => ['min' => 'minItems', 'max' => 'maxItems'],
    ];
    if (isset($keywords[$constraint]) && array_diff_key($options, $keywords[$constraint]) === []) {
      $stated = [];
      foreach ($keywords[$constraint] as $option => $keyword) {
        if (array_key_exists($option, $options)) {
          $stated[$keyword] = $options[$option];
        }
      }
      return 'Schema::keywords(' . PhpLiteral::write($stated, self::INDENT + 2) . ')';
    }
    if ($constraint === 'Choice' && array_keys($options) === ['choices'] && is_array($options['choices'])) {
      return 'Schema::enum(' . PhpLiteral::write(array_values($options['choices']), self::INDENT + 2) . ')';
    }
    return sprintf("Schema::any()->check('drupal:%s', %s)", $constraint, PhpLiteral::write($options, self::INDENT + 2, static fn (array $path): bool => end($path) === 'message'));
  }

  /**
   * Writes a declared rule.
   *
   * @param \Drupal\surface\Refinement\Rule $rule
   *   The rule.
   *
   * @return string
   *   The statement.
   */
  protected function rule(Rule $rule): string {
    $this->uses[Condition::class] = TRUE;
    $calls = [];
    $condition = $rule->condition();
    if ($condition !== NULL) {
      $calls[] = '->when(' . $this->condition($condition) . ')';
    }
    foreach ($rule->constraints() as $constraint => $options) {
      $calls[] = '->constrain(' . $this->ruleSchema((string) $constraint, $options) . ')';
    }
    if ($rule->requires()) {
      $calls[] = '->require()';
    }
    if ($rule->rulesOut()) {
      $calls[] = '->doesNotApply()';
    }
    $description = $rule->description();
    if ($description !== NULL) {
      $calls[] = '->because(' . PhpLiteral::translated(static::text($description)) . ')';
    }
    return static::chain('$inputs->narrow(' . $this->ref($rule->key) . ')', $calls);
  }

  /**
   * Writes a condition.
   *
   * @param \Drupal\surface\Refinement\Condition $condition
   *   The condition.
   *
   * @return string
   *   The expression, each key named by its constant.
   */
  protected function condition(Condition $condition): string {
    if ($condition->conditions !== []) {
      return sprintf('Condition::%s(%s)', $condition->kind, implode(', ', array_map($this->condition(...), $condition->conditions)));
    }
    $arguments = [$this->ref((string) $condition->key)];
    if ($condition->operand !== NULL) {
      $arguments[] = PhpLiteral::write($condition->operand);
    }
    return sprintf('Condition::%s(%s)', $condition->kind, implode(', ', $arguments));
  }

  /**
   * Writes what a config schema states of a key that was not carried.
   *
   * @param string $at
   *   The key's dotted path; empty for the object itself.
   * @param string $name
   *   The key, as the comment names it.
   * @param bool $deep
   *   TRUE to write what lies below the key too: for a key written in one
   *   statement, a map's properties and a list's items. A structure's
   *   frame writes its own.
   *
   * @return string[]
   *   The comments.
   */
  protected function leftComments(string $at, string $name, bool $deep): array {
    $comments = [];
    foreach ($this->left as $path => $left) {
      $path = (string) $path;
      $below = $at !== '' && str_starts_with($path, $at . '.');
      if ($path !== $at && !($below && ($deep || $path === $at . '.*'))) {
        continue;
      }
      $key = $this->named($name);
      $what = match (TRUE) {
        $path === '' => 'the object',
        !$below => $key,
        str_ends_with($path, '.*') => 'each item of ' . $key . substr($path, strlen($at), -2),
        default => $key . substr($path, strlen($at)),
      };
      foreach ($left['constraints'] ?? [] as $constraint => $options) {
        $held = in_array((string) $constraint, self::HELD, TRUE);
        $comments[] = sprintf(
          'The config schema states %s on %s, which is not written here: %s',
          trim($constraint . ' ' . (is_array($options) && $options !== [] ? (string) json_encode($options, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '')),
          $what,
          $held ? 'the build refuses this resource until it states it too.' : 'storage still holds every write to it.',
        );
        $this->comments++;
      }
      if (isset($left['type'])) {
        $comments[] = sprintf('The config schema names the type of %s through another value (%s): read as a value of any type.', $what, $left['type']);
        $this->comments++;
      }
      if (array_key_exists('default', $left)) {
        $comments[] = sprintf('The site holds %s at %s now, which is not a value of the key as its schema reads: no default is written.', (string) json_encode($left['default'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $what);
        $this->comments++;
      }
    }
    return $comments;
  }

  /**
   * Gives a definition's label, or its key's name for one with none.
   *
   * @param mixed $label
   *   The label its declaring class gave it, else its label, as held; NULL for
   *   none.
   * @param string $name
   *   The key.
   *
   * @return array{0: string, 1: bool}
   *   The label, and whether it was made from the name.
   */
  protected function label(mixed $label, string $name): array {
    if (!is_string($label) && !$label instanceof \Stringable) {
      $label = $label === NULL ? NULL : (string) $label;
    }
    if ($label === NULL || (string) $label === '') {
      return [Unicode::ucfirst(str_replace('_', ' ', $name)), TRUE];
    }
    return [static::text($label), FALSE];
  }

  /**
   * Writes comment lines, wrapped.
   *
   * @param string[] $comments
   *   The comments, one a paragraph.
   *
   * @return string
   *   The lines.
   */
  protected function comment(array $comments): string {
    $pad = str_repeat(' ', self::INDENT) . '// ';
    $lines = [];
    foreach ($comments as $comment) {
      foreach (explode("\n", wordwrap($comment, self::WIDTH - strlen($pad), "\n", FALSE)) as $line) {
        $lines[] = $pad . $line;
      }
    }
    return implode("\n", $lines);
  }

  /**
   * Writes a statement: its opening call, then each chained call.
   *
   * @param string $head
   *   The opening call.
   * @param string[] $calls
   *   The chained calls.
   *
   * @return string
   *   The statement, indented, ending in a semicolon.
   */
  protected static function chain(string $head, array $calls): string {
    return str_repeat(' ', self::INDENT) . $head . static::calls($calls, self::INDENT + 2) . ';';
  }

  /**
   * Writes chained calls, one a line.
   *
   * @param string[] $calls
   *   The calls.
   * @param int $indent
   *   Their indentation.
   *
   * @return string
   *   The lines, each starting with a newline.
   */
  protected static function calls(array $calls, int $indent): string {
    $out = '';
    foreach ($calls as $call) {
      $out .= "\n" . str_repeat(' ', $indent) . $call;
    }
    return $out;
  }

  /**
   * Names the keywords of a schema a constraint holds.
   *
   * @param array $schema
   *   The schema.
   *
   * @return string
   *   The keywords, `if`/`then` for a conditional.
   */
  protected static function keywords(array $schema): string {
    $keywords = [];
    foreach ($schema as $keyword => $value) {
      if ($keyword === '$defs') {
        continue;
      }
      if ($keyword === 'allOf' && is_array($value)) {
        foreach ($value as $part) {
          $keywords[] = is_array($part) ? static::keywords($part) : 'allOf';
        }
        continue;
      }
      $keywords[] = $keyword === 'if' ? 'if/then' : ($keyword === 'then' || $keyword === 'else' ? NULL : (string) $keyword);
    }
    return implode(', ', array_unique(array_filter($keywords)));
  }

  /**
   * Gives a text as written, untranslated.
   *
   * @param string|\Stringable $text
   *   The text.
   *
   * @return string
   *   The string.
   */
  protected static function text(string|\Stringable $text): string {
    return $text instanceof TranslatableMarkup ? $text->getUntranslatedString() : (string) $text;
  }

  /**
   * Names a class for a key.
   *
   * @param string $name
   *   The key.
   *
   * @return string
   *   Its name in UpperCamelCase.
   */
  public static function className(string $name): string {
    return str_replace(' ', '', ucwords(str_replace(['_', '-', '.'], ' ', $name)));
  }

  /**
   * Sorts the classes used, as `use` lines are sorted.
   *
   * @param array<string, true> $uses
   *   The classes.
   *
   * @return string[]
   *   Their names, sorted.
   */
  protected static function sorted(array $uses): array {
    $names = array_keys($uses);
    usort($names, 'strcmp');
    return $names;
  }

}
