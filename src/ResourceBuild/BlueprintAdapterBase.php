<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Reference\References;
use Drupal\data_surface\Refinement\Rule;
use Drupal\data_surface\Resource\Arrangement;
use Drupal\data_surface\Resource\BlueprintAdditionsInterface;
use Drupal\data_surface\Resource\DefinesInputsInterface;

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
   * @var \Drupal\data_surface\Refinement\Rule[]
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
   * @param \Drupal\data_surface\DataSurfaceBuilderInterface $builder
   *   The engine's builder the blueprint fills.
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The typed data manager, which turns a type name into the right
   *   definition class: a 'map' is a MapDataDefinition, not a plain one.
   * @param bool $outputs
   *   TRUE when this blueprint describes outputs rather than inputs.
   */
  public function __construct(
    protected readonly DataSurfaceBuilderInterface $builder,
    protected readonly TypedDataManagerInterface $typedDataManager,
    protected readonly bool $outputs = FALSE,
  ) {
  }

  /**
   * Hands the blueprint what resolves shapes and their alters.
   *
   * @param \Drupal\data_surface\ResourceBuild\Shapes|null $shapes
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
   * @param \Drupal\data_surface\Reference\References|null $references
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
   * The type may be a shape's class: the key then starts from the shape
   * as its alters left it, and may only narrow it. It may be a data type
   * core's config schema names and typed data does not, `machine_name`:
   * the key is then the string underneath, with the type's constraints,
   * and may only narrow them.
   * Decision: see docs/decisions.md#a-shape-is-a-named-definition.
   */
  public function add(string $key, string $type, string|\Stringable $label, mixed $default = NULL): DataDefinition {
    if (References::spells($type)) {
      // Decision: see
      // docs/decisions.md#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up.
      if ($type !== 'entity') {
        return $this->addReference($key, $type, $label, $default);
      }
      return $this->addEntity($key, $label, $default) ?? throw new \LogicException(sprintf('add("%s", "entity") declares a reference to an entity of any type, which is two keys, its entity type and its id, attached at the key; only a resource or a structure attaches it. An alter declares one entity type, "entity:<type>".', $key));
    }
    if (str_contains($type, '\\')) {
      $definition = $this->fromShape($key, $type);
      $definition->setLabel($label);
      $this->addDefinition($key, $definition, $default);
      $this->shaped[$key] = $definition;
      return $definition;
    }
    $named = $this->shapes?->configType($type, $this->typedDataManager);
    if ($named !== NULL) {
      // A config schema data type, `machine_name`: a string with the
      // type's constraints as its own, which the key may only narrow.
      // Decision: see
      // docs/decisions.md#a-machine-name-is-a-computed-identity.
      $named->setLabel($label);
      $this->addDefinition($key, $named, $default);
      $this->shaped[$key] = $named;
      return $named;
    }
    $definition = $this->typedDataManager->createDataDefinition($type);
    if (!$definition instanceof DataDefinition) {
      throw new \InvalidArgumentException(sprintf(
        'The "%s" type makes a %s, which add() cannot return: use addDefinition() for it.',
        $type,
        get_class($definition),
      ));
    }
    $definition->setLabel($label);
    $this->addDefinition($key, $definition, $default);
    return $definition;
  }

  /**
   * Adds a key that holds one entity's id, of a type the string names.
   *
   * @param string $key
   *   The key.
   * @param string $type
   *   The data type string, `entity:<type>` or `entity:<type>:<bundle>`.
   * @param string|\Stringable $label
   *   The label.
   * @param mixed $default
   *   The id it starts from, or NULL.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The key's definition: the id, typed as the entity type's id key,
   *   with the constraint naming the type and the bundle.
   *
   * @throws \LogicException
   *   When the blueprint cannot build one, or the string names nothing the
   *   site has.
   */
  protected function addReference(string $key, string $type, string|\Stringable $label, mixed $default): DataDefinition {
    if ($this->references === NULL) {
      throw new \LogicException(sprintf('add("%s", "%s") declares a reference key where nothing builds one: a reference is declared by a resource, a structure or an alter.', $key, $type));
    }
    $definition = $this->references->definition($key, $type);
    $definition->setLabel($label);
    $this->addDefinition($key, $definition, $default);
    return $definition;
  }

  /**
   * Adds a key that refers to an entity of any type, where one may.
   *
   * Only a resource's or a structure's own blueprint attaches the two
   * parts a bare `entity` key is, so this one adds none.
   *
   * @param string $key
   *   The key.
   * @param string|\Stringable $label
   *   The label.
   * @param mixed $default
   *   What it starts from.
   *
   * @return \Drupal\Core\TypedData\DataDefinition|null
   *   The map at the key, or NULL where this blueprint attaches none.
   */
  protected function addEntity(string $key, string|\Stringable $label, mixed $default): ?DataDefinition {
    return NULL;
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
      $item = $this->typedDataManager->createDataDefinition($item);
    }
    $list = (new ListDataDefinition(['type' => 'list'], $item))->setLabel($label);
    $this->addDefinition($key, $list, $default);
    if ($shaped !== NULL) {
      // Each item starts from the shape, held to it as a key declared with
      // it is: by the name of the key's items.
      $this->shaped[$key . '.*'] = $shaped;
    }
    return $list;
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
        : sprintf('%s("%s") was handed %s, which is no typed data type and no shape: a shape implements %s and carries #[Shape].', $verb, $key, $type, 'Drupal\data_surface\Resource\ShapeInterface'));
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
   */
  public function addDefinition(string $key, DataDefinitionInterface $definition, mixed $default = NULL): DataDefinitionInterface {
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
    return $definition;
  }

  /**
   * {@inheritdoc}
   */
  public function attach(string $key, string $child, array $reads = []): MapDataDefinition {
    static::assertNoShape('attach', $key, $child);
    static::assertReads($key, $reads);
    $shell = $this->reserveChild('attach', $key);
    $this->attachments[$key] = $child;
    $this->wirings[$key] = $reads;
    return $shell;
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
  public function attachList(string $key, string $child, ?int $min = NULL, ?int $max = NULL): ListDataDefinition {
    static::assertNoShape('attachList', $key, $child);
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
    $this->lists[$key] = $child;
    return $shell;
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
   * @return \Drupal\data_surface\Refinement\Rule[]
   *   The rules.
   */
  public function rules(): array {
    return $this->rules;
  }

  /**
   * {@inheritdoc}
   */
  public function describe(string $key, string|\Stringable|null $label = NULL, string|\Stringable|null $description = NULL, ?string $after = NULL, ?Arrangement $arrangement = NULL): static {
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
   * put it, and is what attach() and attachVariants() hand back for the owner
   * to label and describe with the core setters. The engine keeps that
   * same map as the shell the child is advertised in.
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
