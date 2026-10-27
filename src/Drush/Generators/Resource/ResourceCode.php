<?php

declare(strict_types=1);

namespace Drupal\data_surface\Drush\Generators\Resource;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Entry;
use Drupal\data_surface\Reference\References;
use Drupal\data_surface\Refinement\Condition;
use Drupal\data_surface\Refinement\DeclaredRefiner;
use Drupal\data_surface\Refinement\Rule;

/**
 * Writes the defineInputs() of a built resource back out as PHP.
 *
 * The resource generator builds what it is about to write (the answers of
 * its interview, or a schema read by the schema reader) and this writes
 * the built keys as the calls an author would make: add() for a value,
 * addList() for a list of values, addDefinition() of a map, attach() and
 * attachList() of a structure written as a class of its own, and
 * narrow()->when() for each declared rule, each key's label, description,
 * required and constraints chained as core's setters. What it cannot write
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
   * refused until it is written.
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
   * @param \Drupal\data_surface\DataSurfaceInterface $built
   *   The resource, sealed as declared.
   *
   * @return array{constants: string, inputs: string, uses: string[], structures: list<array{class: string, id: string, label: string, constants: string, inputs: string, uses: string[]}>, always_held: string[], comments: int}
   *   The constants that name its keys, the body of its defineInputs(),
   *   the classes it uses, the structures it attaches, each written the
   *   same way, the keys its storage always holds, and how many comments
   *   name what is left to write.
   */
  public function write(DataSurfaceInterface $built): array {
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
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
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
  protected function frame(DataSurfaceInterface $surface, string $path): array {
    $map = $surface->getDefinitions();
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
      foreach ($entry->refiners[DataSurfaceInterface::OWNER] ?? [] as $refiner) {
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
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The frame.
   * @param \Drupal\data_surface\Entry $entry
   *   The key.
   * @param string $path
   *   The frame's dotted path, a dot at its end; empty at the top.
   *
   * @return string
   *   The lines.
   *
   * @phpstan-impure
   */
  protected function key(DataSurfaceInterface $surface, Entry $entry, string $path): string {
    $name = $entry->name;
    $at = $path . $name;
    $definition = $entry->definition;
    $comments = $this->leftComments($at, $name, $entry->attachment === NULL && $entry->list === NULL);
    if ($entry->variants !== NULL) {
      $comments[] = sprintf('From the schema, not written here: %s holds variants chosen by %s. Declare each as a #[Variant] structure and attach them with attachVariants(%s, by: %s).', $this->named($name), $this->named($entry->variants->by), $this->ref($name), $this->ref($entry->variants->by));
      $this->comments++;
      return $this->comment($comments);
    }
    if ($entry->attachment !== NULL && DefinitionMetadata::eachOf($definition) !== NULL) {
      $comments[] = sprintf('From the schema, not written here: %s holds one entry per thing the site has. Declare it with addEach() or attachEach() and a provider.', $this->named($name));
      $this->comments++;
      return $this->comment($comments);
    }
    [$label, $unlabeled] = $this->label($definition, $name);
    if ($unlabeled) {
      $this->unlabeled[] = $name;
    }
    $calls = [];
    if ($entry->list !== NULL || $entry->attachment !== NULL) {
      $child = $entry->list !== NULL ? $entry->list->item : $entry->attachment;
      $class = $this->structure($child->child, $child->source ?? $at, $name, $label);
      if ($entry->list !== NULL) {
        $count = $this->constraintsOf($definition)['Count'] ?? [];
        $bounds = '';
        foreach (['min', 'max'] as $bound) {
          $bounds .= isset($count[$bound]) ? sprintf(', %s: %s', $bound, PhpLiteral::scalar($count[$bound])) : '';
        }
        $head = sprintf('$inputs->attachList(%s, %s::class%s)', $this->ref($name), $class, $bounds);
      }
      else {
        $head = sprintf('$inputs->attach(%s, %s::class)', $this->ref($name), $class);
      }
      $calls[] = '->setLabel(' . PhpLiteral::translated($label) . ')';
      $calls = [...$calls, ...$this->described($definition)];
    }
    else {
      $default = $surface->getDefault($name);
      $head = $this->declaration($name, $definition, $label, $default);
      $map = $definition instanceof ComplexDataDefinitionInterface && !$definition instanceof ListDataDefinitionInterface;
      $calls = [
        ...($map ? ['->setLabel(' . PhpLiteral::translated($label) . ')'] : []),
        ...$this->described($definition),
        ...$this->constraintCalls($definition, $this->named($name), $comments),
      ];
    }
    if ($entry->locked) {
      $comments[] = sprintf('The schema makes %s readOnly, at the value it starts from: lock it with an access class, or leave it out.', $this->named($name));
      $this->comments++;
    }
    if (DefinitionMetadata::isSecret($definition)) {
      $comments[] = sprintf('The schema makes %s writeOnly: a secret, which a storage holds apart. See docs/declaring-a-resource.md.', $this->named($name));
      $this->comments++;
    }
    if (DefinitionMetadata::isAlwaysHeld($definition) && !$definition->isRequired()) {
      $this->alwaysHeld[] = $at;
    }
    return ($comments === [] ? '' : $this->comment($comments) . "\n") . static::chain($head, $calls);
  }

  /**
   * Writes the declaring call of a value, a list or a map.
   *
   * @param string $name
   *   The key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   Its definition.
   * @param string $label
   *   Its label.
   * @param mixed $default
   *   What it starts from.
   *
   * @return string
   *   The call, as it opens the statement.
   */
  protected function declaration(string $name, DataDefinitionInterface $definition, string $label, mixed $default): string {
    $default_argument = $default === NULL ? '' : ', default: ' . PhpLiteral::write($default, self::INDENT);
    if ($definition instanceof ListDataDefinitionInterface) {
      return sprintf('$inputs->addList(%s, %s, %s%s)', $this->ref($name), $this->item($definition->getItemDefinition(), self::INDENT + 2), PhpLiteral::translated($label), $default_argument);
    }
    if ($definition instanceof ComplexDataDefinitionInterface) {
      $this->uses[MapDataDefinition::class] = TRUE;
      $map = $this->map($definition, self::INDENT + 2);
      $nested = $default ?? $this->mapDefault($definition);
      return sprintf('$inputs->addDefinition(%s, %s%s)', $this->ref($name), $map, $nested === NULL ? '' : ', default: ' . PhpLiteral::write($nested, self::INDENT));
    }
    return sprintf('$inputs->add(%s, %s, %s%s)', $this->ref($name), PhpLiteral::string($this->type($definition)), PhpLiteral::translated($label), $default_argument);
  }

  /**
   * Writes a list's item.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $item
   *   The item's definition.
   * @param int $indent
   *   The indentation of its chained calls.
   *
   * @return string
   *   Its type, or its definition where it says more.
   */
  protected function item(DataDefinitionInterface $item, int $indent): string {
    $calls = [];
    $own = DefinitionMetadata::ownLabel($item);
    if ($own !== NULL && (string) $own !== '') {
      $calls[] = '->setLabel(' . PhpLiteral::translated(static::text($own)) . ')';
    }
    $comments = [];
    $calls = [...$calls, ...$this->constraintCalls($item, '', $comments, $indent)];
    if ($calls === []) {
      return PhpLiteral::string($this->type($item));
    }
    $this->uses[DataDefinition::class] = TRUE;
    return 'DataDefinition::create(' . PhpLiteral::string($this->type($item)) . ')' . static::calls($calls, $indent);
  }

  /**
   * Writes a map's definition, each property its own.
   *
   * @param \Drupal\Core\TypedData\ComplexDataDefinitionInterface $map
   *   The map.
   * @param int $indent
   *   The indentation of its chained calls.
   *
   * @return string
   *   The expression.
   */
  protected function map(ComplexDataDefinitionInterface $map, int $indent): string {
    $calls = [];
    foreach ($map->getPropertyDefinitions() as $name => $property) {
      $name = (string) $name;
      [$label] = $this->label($property, $name);
      $own = ['->setLabel(' . PhpLiteral::translated($label) . ')', ...$this->described($property)];
      if ($property instanceof ComplexDataDefinitionInterface && !$property instanceof ListDataDefinitionInterface) {
        $inner = $this->map($property, $indent + 2) . static::calls($own, $indent + 2);
      }
      elseif ($property instanceof ListDataDefinitionInterface) {
        $item = PhpLiteral::string($this->type($property->getItemDefinition()));
        $inner = 'ListDataDefinition::create(' . $item . ')' . static::calls($own, $indent + 2);
        $this->uses[ListDataDefinition::class] = TRUE;
      }
      else {
        $this->uses[DataDefinition::class] = TRUE;
        $comments = [];
        $constraints = $this->constraintCalls($property, '', $comments, $indent + 2);
        $own = [...$own, ...$constraints];
        $inner = 'DataDefinition::create(' . PhpLiteral::string($this->type($property)) . ')' . static::calls($own, $indent + 2);
      }
      $calls[] = '->setPropertyDefinition(' . PhpLiteral::string($name) . ', ' . $inner . ')';
    }
    return 'MapDataDefinition::create()' . static::calls($calls, $indent);
  }

  /**
   * Gives what a map's properties start from, together.
   *
   * @param \Drupal\Core\TypedData\ComplexDataDefinitionInterface $map
   *   The map.
   *
   * @return array|null
   *   Each property's default, by name; NULL when none has one.
   */
  protected function mapDefault(ComplexDataDefinitionInterface $map): ?array {
    $defaults = [];
    foreach ($map->getPropertyDefinitions() as $name => $property) {
      if ($property instanceof ComplexDataDefinitionInterface && !$property instanceof ListDataDefinitionInterface) {
        $inner = $this->mapDefault($property);
        if ($inner !== NULL) {
          $defaults[(string) $name] = $inner;
        }
      }
      elseif (DefinitionMetadata::hasDefaultValue($property)) {
        $defaults[(string) $name] = DefinitionMetadata::getDefaultValue($property);
      }
    }
    return $defaults === [] ? NULL : $defaults;
  }

  /**
   * Writes a structure a key attaches, as a class of its own.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $child
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
  protected function structure(DataSurfaceInterface $child, string $source, string $name, string $label): string {
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
   * Writes a definition's description and whether it is required.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return string[]
   *   The chained calls.
   */
  protected function described(DataDefinitionInterface $definition): array {
    $calls = [];
    $description = $definition->getDescription();
    if ($description !== NULL && (string) $description !== '') {
      $calls[] = '->setDescription(' . PhpLiteral::translated(static::text($description)) . ')';
    }
    if ($definition->isRequired()) {
      $calls[] = '->setRequired(TRUE)';
    }
    return $calls;
  }

  /**
   * Writes a definition's constraints and settings.
   *
   * A JsonSchema constraint is written as a comment naming its keywords,
   * a reference's constraint is its type (declaration()).
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
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
  protected function constraintCalls(DataDefinitionInterface $definition, string $at, array &$comments, int $indent = self::INDENT + 2): array {
    $calls = [];
    foreach ($this->constraintsOf($definition) as $constraint => $options) {
      $constraint = (string) $constraint;
      if ($constraint === References::CONSTRAINT) {
        continue;
      }
      if ($constraint === 'JsonSchema') {
        $schema = is_array($options['schema'] ?? NULL) ? $options['schema'] : [];
        $comments[] = sprintf('From the schema, not written here: %s on %s, which no constraint here states; the reader keeps it as a JsonSchema constraint. Write a constraint for it.', static::keywords($schema), $at === '' ? 'each item' : $at);
        $this->comments++;
        continue;
      }
      $translate = static fn (array $path): bool => in_array(end($path), ['labels', 'descriptions', 'message'], TRUE)
        || (count($path) >= 2 && in_array($path[count($path) - 2], ['labels', 'descriptions'], TRUE))
        || ($constraint === 'LabeledChoice' && count($path) === 2 && $path[0] === 'choices' && is_string($path[1]));
      $calls[] = sprintf('->addConstraint(%s, %s)', PhpLiteral::string($constraint), PhpLiteral::write(is_array($options) ? $options : [], $indent, $translate));
    }
    $settings = $definition instanceof DataDefinition ? ($definition->toArray()['settings'] ?? []) : [];
    foreach (is_array($settings) ? $settings : [] as $setting => $value) {
      $calls[] = sprintf('->setSetting(%s, %s)', PhpLiteral::string((string) $setting), PhpLiteral::write($value, $indent));
    }
    return $calls;
  }

  /**
   * Gives the constraints a definition declares itself.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return array<string, mixed>
   *   Its own constraints, not those of its type.
   */
  protected function constraintsOf(DataDefinitionInterface $definition): array {
    $constraints = $definition instanceof DataDefinition ? ($definition->toArray()['constraints'] ?? []) : [];
    return is_array($constraints) ? $constraints : [];
  }

  /**
   * Writes a declared rule.
   *
   * @param \Drupal\data_surface\Refinement\Rule $rule
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
      $calls[] = sprintf('->constrain(%s, %s)', PhpLiteral::string((string) $constraint), PhpLiteral::write($options, self::INDENT + 2, static fn (array $path): bool => end($path) === 'message'));
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
   * @param \Drupal\data_surface\Refinement\Condition $condition
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
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param string $name
   *   The key.
   *
   * @return array{0: string, 1: bool}
   *   The label, and whether it was made from the name.
   */
  protected function label(DataDefinitionInterface $definition, string $name): array {
    $label = DefinitionMetadata::ownLabel($definition) ?? $definition->getLabel();
    if ($label === NULL || (string) $label === '') {
      return [Unicode::ucfirst(str_replace('_', ' ', $name)), TRUE];
    }
    return [static::text($label), FALSE];
  }

  /**
   * Gives the data type a key is declared with.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return string
   *   Its type, or `entity:<type>[:<bundle>]` for a reference.
   */
  protected function type(DataDefinitionInterface $definition): string {
    $target = References::target($definition);
    if ($target !== NULL) {
      return 'entity:' . $target['entityType'] . (count($target['bundles']) === 1 ? ':' . $target['bundles'][0] : '');
    }
    return $definition->getDataType();
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
