<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\surface\SurfaceBuilderInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Contract\LeafTypes;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Reference\References;
use Drupal\surface\Refinement\Rule;
use Drupal\surface\Resolve;
use Drupal\surface\Resource\Arrangement;
use Drupal\surface\Resource\ArrangementGroup;
use Drupal\surface\Resource\BlueprintAdditionsInterface;
use Drupal\surface\Resource\ComputesValueInterface;
use Drupal\surface\Resource\DefinesInputsInterface;

/**
 * What every blueprint does: add, over the engine's builder.
 *
 * The two blueprints differ only in where an added key lands, which is the
 * one method each implements. Everything else (creating the definition
 * from a type name, refusing a key added twice, rewording a key,
 * recording a child for the build step to build) is said here once.
 *
 * Adding is never replacing. The builder's own setters replace silently,
 * because the build step itself rewrites a definition (a context's lock,
 * a build-time refiner's result); nothing an author writes may change
 * what was declared, so a second add of one key is refused naming it.
 *
 * @internal
 */
abstract class BlueprintAdapterBase implements BlueprintAdditionsInterface {

  /**
   * The keys added through this blueprint, in order.
   *
   * @var string[]
   */
  protected array $keys = [];

  /**
   * The children attached through this blueprint, keyed by key.
   *
   * @var array<string, class-string>
   */
  protected array $attachments = [];

  /**
   * The ports each child attached through this blueprint reads, by key.
   *
   * @var array<string, array<string, string>>
   */
  protected array $wirings = [];

  /**
   * The rules declared through this blueprint, in order.
   *
   * @var \Drupal\surface\Refinement\Rule[]
   */
  protected array $rules = [];

  /**
   * The collections attached through this blueprint, keyed by key.
   *
   * @var array<string, string>
   */
  protected array $lists = [];

  /**
   * The keys declared with a shape through this blueprint, by key.
   *
   * @var array<string, \Drupal\Core\TypedData\DataDefinition>
   */
  protected array $shaped = [];

  /**
   * The definitions of the plain keys added through this blueprint, by key.
   *
   * @var array<string, \Drupal\Core\TypedData\DataDefinitionInterface>
   */
  protected array $definitions = [];

  /**
   * What resolves a shape and its alters; NULL applies no alter.
   */
  protected ?Shapes $shapes = NULL;

  /**
   * What builds a key that refers to an entity; NULL declares none.
   */
  protected ?References $references = NULL;

  /**
   * Constructs a blueprint over a builder.
   *
   * @param \Drupal\surface\SurfaceBuilderInterface $builder
   *   The engine's builder the blueprint fills.
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The typed data manager, which turns a type name into the right
   *   definition class: a 'map' is a MapDataDefinition, not a plain one.
   * @param bool $outputs
   *   TRUE when this blueprint describes outputs rather than inputs.
   */
  public function __construct(
    protected readonly SurfaceBuilderInterface $builder,
    protected readonly TypedDataManagerInterface $typedDataManager,
    protected readonly bool $outputs = FALSE,
  ) {
  }

  /**
   * Hands the blueprint what resolves shapes and their alters.
   *
   * @param \Drupal\surface\ResourceBuild\Shapes|null $shapes
   *   The resolver; NULL declares a shape as it is, with no alter.
   *
   * @return $this
   */
  public function useShapes(?Shapes $shapes): static {
    $this->shapes = $shapes;
    return $this;
  }

  /**
   * Hands the blueprint what builds a key that refers to an entity.
   *
   * @param \Drupal\surface\Reference\References|null $references
   *   The service; NULL refuses a reference key.
   *
   * @return $this
   */
  public function useReferences(?References $references): static {
    $this->references = $references;
    return $this;
  }

  /**
   * {@inheritdoc}
   *
   * The type is a row of the type table (LeafTypes). It may be a shape's
   * class: the key then starts from the shape as its alters left it, and
   * may only narrow it. It may be a data type core's config schema names
   * and typed data does not, `machine_name`: the key is then the string
   * underneath, with the type's constraints, and may only narrow them.
   * Either resolves to a row; any other type is refused here, naming the
   * method for what it was meant as.
   * Decision: see docs/decisions.md#a-shape-is-a-named-definition.
   * Decision: see
   * docs/decisions.md#one-table-of-leaf-types-read-by-the-blueprint-the-emitter-and-the-reader.
   */
  public function add(string $key, string $type, string|\Stringable $label, mixed $default = NULL): DataDefinition {
    $verb = sprintf('add("%s", "%s")', $key, $type);
    if (str_contains($type, '\\')) {
      $definition = $this->fromShape($key, $type);
      LeafTypes::assertHeld($verb, $definition);
      $definition->setLabel($label);
      $this->addKey($key, $definition, $default);
      $this->shaped[$key] = $definition;
      return $definition;
    }
    $named = LeafTypes::has($type) ? NULL : $this->shapes?->configType($type, $this->typedDataManager);
    if ($named !== NULL) {
      // A config schema data type, `machine_name`: a string with the
      // type's constraints as its own, which the key may only narrow.
      // Decision: see
      // docs/decisions.md#a-machine-name-is-a-computed-identity.
      LeafTypes::assertHeld($verb, $named);
      $named->setLabel($label);
      $this->addKey($key, $named, $default);
      $this->shaped[$key] = $named;
      return $named;
    }
    if (!LeafTypes::has($type)) {
      throw new \LogicException(sprintf(
        '%s declares a key of the %s type, which JSON Schema has no type for. add() takes one of %s, a shape\'s class or a config schema type of one of them; %s',
        $verb,
        $type,
        implode(', ', array_keys(LeafTypes::ROWS)),
        LeafTypes::meant($type),
      ));
    }
    $definition = $this->typedDataManager->createDataDefinition($type);
    assert($definition instanceof DataDefinition);
    $definition->setLabel($label);
    $this->addKey($key, $definition, $default);
    return $definition;
  }

  /**
   * Declares one entry of a map of keys from the site.
   *
   * As add() declares a key, unless addEach() named the entity type each
   * entry refers to: the id then typed as the entity type keeps it, with
   * the constraint naming the type and the bundles (References::definition()).
   *
   * @param string $key
   *   The entry's key.
   * @param string $type
   *   The type addEach() was handed; ignored for a reference.
   * @param string|\Stringable $label
   *   The entry's label.
   * @param mixed $default
   *   What it starts from.
   * @param string|null $to
   *   The entity type an entry refers to, or NULL for an entry of a type.
   * @param string[] $bundles
   *   The bundles it may name; none for every bundle.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The entry's definition.
   *
   * @internal
   *   For the build step alone.
   */
  public function addEntry(string $key, string $type, string|\Stringable $label, mixed $default = NULL, ?string $to = NULL, array $bundles = []): DataDefinition {
    return $to === NULL
      ? $this->add($key, $type, $label, $default)
      : $this->addReference($key, $to, $bundles, $label, $default);
  }

  /**
   * Adds a key that holds one entity's id.
   *
   * @param string $key
   *   The key.
   * @param string $to
   *   The entity type it refers to.
   * @param string[] $bundles
   *   The bundles it may name; none for every bundle.
   * @param string|\Stringable $label
   *   The label.
   * @param mixed $default
   *   The id it starts from, or NULL.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The key's definition: the id, typed as the entity type's id key,
   *   with the constraint naming the type and the bundles.
   *
   * @throws \LogicException
   *   When the blueprint cannot build one, or the entity type is none the
   *   site has.
   */
  protected function addReference(string $key, string $to, array $bundles, string|\Stringable $label, mixed $default): DataDefinition {
    if ($this->references === NULL) {
      throw new \LogicException(sprintf('addEach() declares "%s" as a reference to %s where nothing builds one: a reference per thing the site has is declared by a resource or a structure.', $key, $to));
    }
    $definition = $this->references->definition($key, $to, $bundles);
    $definition->setLabel($label);
    $this->addKey($key, $definition, $default);
    return $definition;
  }

  /**
   * {@inheritdoc}
   *
   * The list is built here, so an author never reaches for core's
   * constructor, whose create() asks the typed data manager for the item.
   */
  public function addList(string $key, string|DataDefinitionInterface $item, string|\Stringable $label, mixed $default = NULL): ListDataDefinition {
    $shaped = NULL;
    if (is_string($item) && str_contains($item, '\\')) {
      if (is_subclass_of($item, DefinesInputsInterface::class)) {
        throw new \LogicException(sprintf('addList("%s") was handed %s, a resource or structure, as its item: a list of groups of keys is a collection, attachList(\'%s\', ...).', $key, $item, $key));
      }
      $item = $shaped = $this->fromShape($key, $item, 'addList');
    }
    elseif (is_string($item)) {
      if (!LeafTypes::has($item)) {
        throw new \LogicException(sprintf('addList("%s", "%s") declares items of the %s type, which JSON Schema has no type for. Each item is one of %s, or a shape\'s class; %s', $key, $item, $item, implode(', ', array_keys(LeafTypes::ROWS)), LeafTypes::meant($item)));
      }
      $item = $this->typedDataManager->createDataDefinition($item);
    }
    $list = (new ListDataDefinition(['type' => 'list'], $item))->setLabel($label);
    $this->addKey($key, $list, $default, 'addList');
    if ($shaped !== NULL) {
      // Each item starts from the shape, held to it as a key declared with
      // it is: by the name of the key's items.
      $this->shaped[$key . '.*'] = $shaped;
    }
    return $list;
  }

  /**
   * {@inheritdoc}
   *
   * The map is built whole before it is declared, so every key inside is
   * held to the type table with it; what its keys start from is its
   * default.
   *
   * Decision: see docs/decisions.md#an-anonymous-map.
   */
  public function addMap(string $key, string|\Stringable $label, callable $define): MapDataDefinition {
    $map = MapDataDefinition::create();
    $map->setLabel($label);
    $inner = $this->mapBlueprint($map, $key)
      ->useShapes($this->shapes)
      ->useReferences($this->references);
    $define($inner);
    $this->addKey($key, $map, $inner->defaults() === [] ? NULL : $inner->defaults());
    return $map;
  }

  /**
   * Builds the blueprint a map's callable is handed first.
   *
   * @param \Drupal\Core\TypedData\MapDataDefinition $map
   *   The map.
   * @param string $key
   *   Its key.
   *
   * @return \Drupal\surface\ResourceBuild\MapBlueprint
   *   The blueprint, which says why no structure can stand for the map
   *   where none can.
   */
  protected function mapBlueprint(MapDataDefinition $map, string $key): MapBlueprint {
    return new MapBlueprint($map, $this->pathOf($key), $this, $this->builder, $this->typedDataManager, $this->outputs, $this->unframed());
  }

  /**
   * Says why a map declared here cannot be attached as a structure.
   *
   * @return string|null
   *   The reason, or NULL where it can.
   */
  protected function unframed(): ?string {
    return 'only a resource\'s or a structure\'s own inputs attach one: an alter attaches a structure at its own mount alone.';
  }

  /**
   * Names a key of this blueprint for a message about a map inside it.
   *
   * @param string $key
   *   The key.
   *
   * @return string
   *   Its path from the frame the blueprint fills.
   */
  protected function pathOf(string $key): string {
    return $key;
  }

  /**
   * Starts a key from the shape a class names.
   *
   * @param string $key
   *   The key, for the messages.
   * @param string $type
   *   The class handed to add() or addList().
   * @param string $verb
   *   The method it was handed to, for the message.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The key's definition, marked with the shape.
   *
   * @throws \LogicException
   *   When the class is no shape.
   */
  protected function fromShape(string $key, string $type, string $verb = 'add'): DataDefinition {
    if (!Shapes::isShape($type)) {
      throw new \LogicException(is_subclass_of($type, DefinesInputsInterface::class)
        ? sprintf('%s("%s") was handed %s, a resource or structure, as a type: a group of keys is attached at the key, attach(\'%s\', ...), or mixed in, mixIn().', $verb, $key, $type, $key)
        : sprintf('%s("%s") was handed %s, which is no typed data type and no shape: a shape implements %s and carries #[Shape].', $verb, $key, $type, 'Drupal\surface\Resource\ShapeInterface'));
    }
    return $this->shapes?->forKey($type) ?? Shapes::unaltered($type);
  }

  /**
   * Gets the keys declared with a shape through this blueprint.
   *
   * @return array<string, \Drupal\Core\TypedData\DataDefinition>
   *   Each key's definition, by the key's name in messages.
   */
  public function shapedKeys(): array {
    return $this->shaped;
  }

  /**
   * Refuses a shape where a child belongs.
   *
   * @param string $verb
   *   The method, for the message.
   * @param string $key
   *   The key.
   * @param string $child
   *   What it was handed.
   *
   * @throws \LogicException
   *   When it is a shape.
   */
  protected static function assertNoShape(string $verb, string $key, string $child): void {
    if (Shapes::isShape($child)) {
      throw new \LogicException(sprintf('%s("%s") was handed %s, a shape: a shape is one value, declared at a key with add(\'%s\', %s::class, ...); %s() holds a resource or structure.', $verb, $key, $child, $key, substr($child, (int) strrpos($child, '\\') + 1), $verb));
    }
  }

  /**
   * {@inheritdoc}
   *
   * One value: a map or a list is refused, naming the method that declares
   * it, so every object a blueprint holds is declared by a method that is
   * one JSON Schema construct.
   *
   * Decision: see docs/decisions.md#a-blueprint-takes-no-complex-data-types.
   */
  public function addDefinition(string $key, DataDefinitionInterface $definition, mixed $default = NULL): DataDefinitionInterface {
    if (!LeafTypes::isLeaf($definition)) {
      throw new \LogicException(sprintf(
        'addDefinition("%s") was handed a %s: addDefinition() declares one value, of one of %s; %s',
        $key,
        $definition instanceof ListDataDefinitionInterface ? 'list' : 'map',
        implode(', ', array_keys(LeafTypes::ROWS)),
        LeafTypes::meant($definition instanceof ListDataDefinitionInterface ? 'list' : 'map'),
      ));
    }
    return $this->addKey($key, $definition, $default);
  }

  /**
   * Declares a key this blueprint built, or one value addDefinition() took.
   *
   * What every declaring verb comes to: the key is refused when it is
   * declared already, every value inside is held to the type table, and the
   * definition is put where this blueprint's keys live.
   *
   * @param string $key
   *   The key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition: one value, a list or a map.
   * @param mixed $default
   *   The default value; NULL declares none.
   * @param string $verb
   *   The method the author called, for the message.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The definition declared, the one handed in.
   *
   * @template T of \Drupal\Core\TypedData\DataDefinitionInterface
   * @phpstan-param T $definition
   * @phpstan-return T
   *
   * @throws \LogicException
   *   When the key is declared already, or a value inside is of a type
   *   add() refuses.
   */
  protected function addKey(string $key, DataDefinitionInterface $definition, mixed $default = NULL, string $verb = 'addDefinition'): DataDefinitionInterface {
    // Every value inside is held to the type table, as add() holds one.
    // Decision: see
    // docs/decisions.md#one-table-of-leaf-types-read-by-the-blueprint-the-emitter-and-the-reader.
    LeafTypes::assertHeld(sprintf('%s("%s")', $verb, $key), $definition);
    $this->assertNoPort($key);
    if (in_array($key, $this->declared(), TRUE) || $this->isDeclared($key)) {
      throw new \LogicException(sprintf(
        'The %s "%s" was already added to this blueprint. Adding is never replacing: what one declaration said, another may not change.',
        $this->outputs ? 'output' : 'input',
        $key,
      ));
    }
    $this->declare($key, $definition, $default);
    $this->keys[] = $key;
    $this->definitions[$key] = $definition;
    return $definition;
  }

  /**
   * Gets the definitions of the plain keys added through this blueprint.
   *
   * What the build checks once the declaration is done, when what an author
   * adds to a key with the core setters is there: a reference key's
   * constraint (References::settle()).
   *
   * @return array<string, \Drupal\Core\TypedData\DataDefinitionInterface>
   *   Each definition, by key, in declaration order.
   */
  public function definitions(): array {
    return $this->definitions;
  }

  /**
   * {@inheritdoc}
   */
  public function attach(string $key, string $structure, string|\Stringable|null $label = NULL, array $reads = []): MapDataDefinition {
    static::assertNoShape('attach', $key, $structure);
    static::assertReads($key, $reads);
    $shell = $this->reserveChild('attach', $key);
    $this->attachments[$key] = $structure;
    $this->wirings[$key] = $reads;
    return static::labeled($shell, $label);
  }

  /**
   * Gets the ports a child attached through this blueprint reads.
   *
   * @param string $key
   *   The key the child sits at.
   *
   * @return array<string, string>
   *   Each wired port, mapped to the container's key it reads.
   */
  public function wiring(string $key): array {
    return $this->wirings[$key] ?? [];
  }

  /**
   * Refuses a wiring that is not a port name mapped to a key.
   *
   * @param string $key
   *   The key the child sits at.
   * @param array $reads
   *   The wiring.
   *
   * @throws \InvalidArgumentException
   *   When an entry is not one.
   */
  protected static function assertReads(string $key, array $reads): void {
    foreach ($reads as $port => $wired) {
      if (!is_string($port) || $port === '' || !is_string($wired) || $wired === '') {
        throw new \InvalidArgumentException(sprintf('attach() at "%s" was handed reads that do not map a port, by its name, to a key of the container, by its name: [\'port\' => \'key\'].', $key));
      }
    }
  }

  /**
   * Refuses a key that this blueprint reads as a port.
   *
   * @param string $key
   *   The key.
   *
   * @throws \LogicException
   *   When it is a port.
   */
  protected function assertNoPort(string $key): void {
  }

  /**
   * {@inheritdoc}
   */
  public function attachList(string $key, string $structure, string|\Stringable|null $label = NULL, ?int $min = NULL, ?int $max = NULL): ListDataDefinition {
    static::assertNoShape('attachList', $key, $structure);
    if (($min !== NULL && $min < 0) || ($max !== NULL && $max < 0) || ($min !== NULL && $max !== NULL && $min > $max)) {
      throw new \InvalidArgumentException(sprintf(
        'attachList() was given %s to %s items for "%s": a collection holds from none upwards, and its fewest cannot be more than its most.',
        $min ?? 'any',
        $max ?? 'unlimited',
        $key,
      ));
    }
    if ($this->outputs) {
      throw new \LogicException(sprintf('attachList() cannot place a collection at the output "%s": outputs do not hold attached structures yet.', $key));
    }
    $this->assertNoPort($key);
    if (in_array($key, $this->declared(), TRUE) || $this->isDeclared($key)) {
      throw new \LogicException(sprintf(
        'The input "%s" was already added to this blueprint. Adding is never replacing: what one declaration said, another may not change.',
        $key,
      ));
    }
    $shell = new ListDataDefinition([], MapDataDefinition::create());
    $count = array_filter(['min' => $min, 'max' => $max], static fn (?int $bound): bool => $bound !== NULL);
    if ($count !== []) {
      $shell->addConstraint('Count', $count);
    }
    $this->declare($key, $shell, NULL);
    $this->lists[$key] = $structure;
    return static::labeled($shell, $label);
  }

  /**
   * {@inheritdoc}
   *
   * The owner's blueprint lists its own values in the key's constraint, so
   * only an alter's blueprint offers more.
   */
  public function extendChoices(string $key, array $choices): static {
    throw new \LogicException(sprintf(
      'extendChoices() was asked to widen "%s" from the owner\'s own blueprint. An owner lists its own values in the key\'s constraint; extendChoices() is how an alter offers more.',
      $key,
    ));
  }

  /**
   * {@inheritdoc}
   *
   * Recorded here and bound by the build step, which checks the rule
   * against the shape once every alter has added its keys.
   */
  public function narrow(string $key): Rule {
    if ($this->outputs) {
      throw new \LogicException(sprintf('narrow() was asked to narrow the output "%s". Outputs are never refined: a rule narrows what may be sent, and nobody sends an output.', $key));
    }
    return $this->rules[] = new Rule($key);
  }

  /**
   * Gets the rules declared through this blueprint, in order.
   *
   * @return \Drupal\surface\Refinement\Rule[]
   *   The rules.
   */
  public function rules(): array {
    return $this->rules;
  }

  /**
   * {@inheritdoc}
   *
   * Written on the key's definition now, every name translated to the
   * path its value is stored at in the frame declaring it, so
   * Resources::resolved() reads it from what storage loads with nothing
   * more to look up. A structure's paths are its own, applied where the
   * structure is held; a mixed-in structure's are its owner's.
   *
   * Decision: see docs/decisions.md#a-key-may-stand-for-another-at-use.
   */
  public function resolve(string $key, array $from, string $using): static {
    $refuse = fn (string $why): \LogicException => new \LogicException(sprintf('resolve("%s") in %s %s', $key, $this->who(), $why));
    if ($this->outputs) {
      throw $refuse('resolves an output: an output is emitted, never stored, so it has nothing to resolve; a resolve reads a resource\'s stored inputs.');
    }
    if (!is_subclass_of($using, ComputesValueInterface::class)) {
      throw $refuse(sprintf('names %s, which implements no %s: the class it names has a static compute().', $using, ComputesValueInterface::class));
    }
    if ($from === [] || !array_is_list($from)) {
      throw $refuse('names no key it is resolved from: from: lists one or more keys.');
    }
    $path = $this->storedPath($key);
    $definition = $path === NULL ? NULL : $this->find($key);
    if ($path === NULL || $definition === NULL) {
      throw $refuse('names a key nothing has declared: it resolves the owner\'s key by its name, or a key this blueprint added, once declared.');
    }
    if ($definition instanceof ComplexDataDefinitionInterface || $definition instanceof ListDataDefinitionInterface) {
      throw $refuse('names a map or a list: a resolved key holds one value.');
    }
    $resolved = DefinitionMetadata::resolveOf($definition);
    if ($resolved !== NULL) {
      // Decision: see docs/decisions.md#a-key-may-stand-for-another-at-use.
      throw $refuse(sprintf('names a key %s resolves already. A key is resolved once: while both resolves\' sources held a value there would be no right answer, and each alter rules out the owner\'s key alone, never another alter\'s. One module stands in for a key.', $resolved->by));
    }
    $paths = [];
    foreach ($from as $source) {
      $at = $source === $key ? NULL : $this->storedPath($source);
      if ($at === NULL) {
        throw $refuse(sprintf('is resolved from "%s", which is %s.', $source, $source === $key ? 'the key itself: a key is resolved from others' : 'no key declared: the owner\'s key by its name, or a key this blueprint added'));
      }
      $paths[$source] = $at;
    }
    DefinitionMetadata::setResolve($definition, new Resolve($paths, $using, $this->who()));
    return $this;
  }

  /**
   * Gets the path a declared key's value is stored at, for resolve().
   *
   * @param string $key
   *   The key, as resolve() was given it.
   *
   * @return string|null
   *   Its dotted path in the stored values, or NULL when this blueprint
   *   knows no input of that name.
   */
  protected function storedPath(string $key): ?string {
    return $this->outputs || $this->builder->getDefinition($key) === NULL ? NULL : $key;
  }

  /**
   * Names the declaration this blueprint fills, for a message.
   *
   * @return string
   *   The owner, or the alter.
   */
  protected function who(): string {
    return 'this blueprint';
  }

  /**
   * {@inheritdoc}
   */
  public function describe(string $key, string|\Stringable|null $label = NULL, string|\Stringable|null $description = NULL, ?string $after = NULL, ?ArrangementGroup $group = NULL, ?int $weight = NULL, string|\Stringable|null $placeholder = NULL, string|\Stringable|null $prefix = NULL, string|\Stringable|null $suffix = NULL, string|\Stringable|null $help = NULL): static {
    $definition = $this->find($key);
    if ($definition === NULL) {
      throw new \LogicException(sprintf(
        $this->outputs
          ? 'describe() was asked to reword the output "%s", which nothing has declared. An output is described by its name, as its owner declared it.'
          : 'describe() was asked to reword the input "%s", which nothing has declared. A key is described by its name when its owner declared it or this blueprint added it, and by its mounted path, third_party_settings.<module>.<key>, when another alter did; a module\'s mount is third_party_settings.<module>, once the module has added a key.',
        $key,
      ));
    }
    if (!$definition instanceof DataDefinition) {
      throw new \LogicException(sprintf('The "%s" definition is a %s, which takes no label or description.', $key, get_class($definition)));
    }
    if ($after !== NULL) {
      $this->assertPlaceable($key, $after);
    }
    if ($label !== NULL) {
      $definition->setLabel($label);
    }
    if ($description !== NULL) {
      $definition->setDescription($description);
    }
    if ($after !== NULL) {
      DefinitionMetadata::setPlacedAfter($definition, $after);
    }
    $arrangement = static::arrangement($group, $weight, $placeholder, $prefix, $suffix, $help);
    if ($arrangement !== NULL) {
      if ($this->outputs) {
        throw new \LogicException(sprintf('describe() was asked to arrange the output "%s": outputs are never drawn, so they have nothing to arrange.', $key));
      }
      // Decision: see docs/decisions.md#arrangement-is-declared-once.
      DefinitionMetadata::setArrangement($definition, $arrangement->over(DefinitionMetadata::arrangementOf($definition)));
    }
    return $this;
  }

  /**
   * Builds the arrangement describe() was given, or NULL for none.
   *
   * @param \Drupal\surface\Resource\ArrangementGroup|null $group
   *   The group the key is drawn in.
   * @param int|null $weight
   *   Its weight among its frame's keys.
   * @param string|\Stringable|null $placeholder
   *   What an empty input shows.
   * @param string|\Stringable|null $prefix
   *   A text drawn before the input.
   * @param string|\Stringable|null $suffix
   *   A text drawn after the input.
   * @param string|\Stringable|null $help
   *   A help text drawn after the key's description.
   *
   * @return \Drupal\surface\Resource\Arrangement|null
   *   The arrangement, or NULL when all six are NULL.
   */
  protected static function arrangement(?ArrangementGroup $group, ?int $weight, string|\Stringable|null $placeholder, string|\Stringable|null $prefix, string|\Stringable|null $suffix, string|\Stringable|null $help): ?Arrangement {
    if ($group === NULL && $weight === NULL && $placeholder === NULL && $prefix === NULL && $suffix === NULL && $help === NULL) {
      return NULL;
    }
    return new Arrangement($group, $weight, $placeholder, $prefix, $suffix, $help);
  }

  /**
   * Refuses a placement describe() cannot honor.
   *
   * A placement says where one module's fieldset is drawn, so it is the
   * module's own to make, of its own input mount, and it names a key the
   * fieldset can be drawn beside: a top-level key of the owner's blueprint.
   * Outputs are never drawn, so they have nowhere to be placed.
   *
   * @param string $key
   *   The key describe() was given.
   * @param string $after
   *   The key the mount is to be drawn after.
   *
   * @throws \LogicException
   *   When the key is not this blueprint's own input mount, or $after is not
   *   a top-level key of the owner's blueprint.
   */
  protected function assertPlaceable(string $key, string $after): void {
    $mount = $this->ownMount();
    if ($this->outputs || $mount === NULL || $key !== $mount) {
      throw new \LogicException(sprintf(
        'describe() was asked to place "%s" after "%s". Only an alter places, and only its own input mount, third_party_settings.<module>: %s',
        $key,
        $after,
        match (TRUE) {
          $this->outputs => 'outputs are never drawn, so they have nowhere to be placed.',
          $mount === NULL => 'an owner orders its own keys by declaring them in order.',
          default => sprintf('this alter\'s is %s.', $mount),
        },
      ));
    }
    if ($after === 'third_party_settings' || $this->builder->getDefinition($after) === NULL) {
      throw new \LogicException(sprintf(
        'describe() was asked to place %s after "%s", which is no top-level key of the owner\'s blueprint. A mount is drawn after a plain key or an attached part the owner declared.',
        $key,
        $after,
      ));
    }
  }

  /**
   * Gets the path of this blueprint's own input mount, if it has one.
   *
   * @return string|null
   *   `third_party_settings.<module>` for an alter's blueprint, NULL for the
   *   owner's, which mounts nothing.
   */
  protected function ownMount(): ?string {
    return NULL;
  }

  /**
   * Gets the plain keys added through this blueprint, in order.
   *
   * Keys holding a child are not among them: they are attachments(), and
   * the keys the owner's blueprint attaches variants at.
   *
   * @return string[]
   *   The keys.
   */
  public function keys(): array {
    return $this->keys;
  }

  /**
   * Gets the children attached through this blueprint.
   *
   * @return array<string, class-string>
   *   The child classes, keyed by key, in declaration order.
   */
  public function attachments(): array {
    return $this->attachments;
  }

  /**
   * Gets the collections attached through this blueprint.
   *
   * @return array<string, string>
   *   The child each item is, by name, keyed by key, in declaration
   *   order.
   */
  public function lists(): array {
    return $this->lists;
  }

  /**
   * Gets every key this blueprint declared, children included.
   *
   * @return string[]
   *   The keys.
   */
  public function declared(): array {
    return array_merge($this->keys, array_keys($this->attachments), array_keys($this->lists));
  }

  /**
   * Holds a key for a child, in declaration order.
   *
   * The child is built after the context is applied, so the key is
   * declared now as an empty map: that keeps it where defineInputs()
   * put it, labeled with what the verb was given, and is what attach()
   * and attachVariants() hand back for the owner to describe with the
   * core setters. The engine keeps that same map as the shell the child
   * is advertised in.
   *
   * @param string $verb
   *   attach or attachVariants, for the message.
   * @param string $key
   *   The key.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The map at the key.
   *
   * @throws \LogicException
   *   When the key is taken, or this blueprint cannot hold a child.
   */
  protected function reserveChild(string $verb, string $key): MapDataDefinition {
    if ($this->outputs) {
      throw new \LogicException(sprintf('%s() cannot place a child at the output "%s": outputs do not hold attached structures yet.', $verb, $key));
    }
    $this->assertNoPort($key);
    if (in_array($key, $this->declared(), TRUE) || $this->isDeclared($key)) {
      throw new \LogicException(sprintf(
        'The input "%s" was already added to this blueprint. Adding is never replacing: what one declaration said, another may not change.',
        $key,
      ));
    }
    $shell = MapDataDefinition::create();
    $this->declare($key, $shell, NULL);
    return $shell;
  }

  /**
   * Labels what a verb declared at a key, where the verb was given a label.
   *
   * A verb that declares a key takes its label with it; NULL leaves the
   * definition as declared, which for a child is the structure's own.
   *
   * @param \Drupal\Core\TypedData\DataDefinition $definition
   *   What the verb declared.
   * @param string|\Stringable|null $label
   *   The label, or NULL to leave it.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The definition, labeled where a label was given.
   *
   * @template T of \Drupal\Core\TypedData\DataDefinition
   * @phpstan-param T $definition
   * @phpstan-return T
   */
  protected static function labeled(DataDefinition $definition, string|\Stringable|null $label): DataDefinition {
    if ($label !== NULL) {
      $definition->setLabel($label);
    }
    return $definition;
  }

  /**
   * Finds a declared definition by the name describe() was given.
   *
   * @param string $key
   *   The key, a mounted path, or a module's mount.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The definition, or NULL when nothing answers to the name.
   */
  protected function find(string $key): ?DataDefinitionInterface {
    if ($this->outputs) {
      return $this->builder->getOutputDefinition($key);
    }
    $own = $this->builder->getDefinition($key);
    if ($own !== NULL) {
      return $own;
    }
    $parts = explode('.', $key, 3);
    if ($parts[0] !== 'third_party_settings') {
      return NULL;
    }
    return match (count($parts)) {
      3 => $this->builder->getThirdPartyDefinition($parts[1], $parts[2]),
      2 => $this->builder->getThirdPartyMount($parts[1]),
      default => NULL,
    };
  }

  /**
   * Puts one added definition where this blueprint's keys live.
   *
   * @param string $key
   *   The key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param mixed $default
   *   The default; NULL declares none.
   */
  abstract protected function declare(string $key, DataDefinitionInterface $definition, mixed $default): void;

  /**
   * Answers whether a key is already where this blueprint would put it.
   *
   * @param string $key
   *   The key.
   *
   * @return bool
   *   TRUE when the builder already holds it.
   */
  abstract protected function isDeclared(string $key): bool;

}
