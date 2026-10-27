<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\data_surface\Computed;
use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Port;
use Drupal\data_surface\Reference\EntityReferenceStructure;
use Drupal\data_surface\Resource\ArrangementGroup;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\DefinesInputsInterface;
use Drupal\data_surface\Resource\Compute;
use Drupal\data_surface\Resource\ComputesValueInterface;
use Drupal\data_surface\Resource\DefinesOutputsInterface;
use Drupal\data_surface\Resource\ResourceCallable;
use Drupal\data_surface\Resource\SiteListInterface;
use Drupal\data_surface\Contract\SchemaReader;

/**
 * The owner's blueprint: keys land at the top of the data surface.
 *
 * An input goes through setDefinition() and its default through
 * setDefault(); an output goes through setOutputDefinition(), and a
 * default given for one is written onto it so that the engine refuses it
 * in its own words rather than this class guessing at them.
 *
 * A structure mixed in with mixIn() is declared through a blueprint of
 * its own over the same builder, so its members land among the owner's
 * keys where the call stands, in order. That blueprint keeps what is the
 * structure's apart (its ports, which only its own refiners read, and its
 * rules, which the build step binds as the structure's), and refuses a
 * child: a mixed-in structure holds keys and maps.
 *
 * @internal
 */
final class ResourceBlueprint extends BlueprintAdapterBase implements BlueprintInterface {

  /**
   * The deciding keys of the variants attached through this blueprint.
   *
   * Keyed by the key the variants are attached at.
   *
   * @var array<string, string>
   */
  protected array $variants = [];

  /**
   * The provider of variants from the site at each key that names one.
   *
   * @var array<string, class-string<\Drupal\data_surface\Resource\SiteListInterface>>
   */
  protected array $variantsFrom = [];

  /**
   * The variants a schema read into this blueprint states, by key.
   *
   * Each value's structure, by the name it was registered under, and the
   * label its value is offered as, if the document gives one.
   *
   * @var array<string, array<string, array{name: string, label: string|null}>>
   */
  protected array $readVariants = [];

  /**
   * The maps of keys from the site declared through this blueprint, by key.
   *
   * @var array<string, array{each: string, from: class-string<\Drupal\data_surface\Resource\SiteListInterface>, default: mixed, child: bool, reads: array<string, string>}>
   */
  protected array $fromSite = [];

  /**
   * The ports declared through this blueprint, by name.
   *
   * @var array<string, \Drupal\data_surface\Port>
   */
  protected array $ports = [];

  /**
   * The structures mixed in through this blueprint, in order.
   *
   * @var array<int, array{class: class-string, reads: array<string, string>, blueprint: \Drupal\data_surface\ResourceBuild\ResourceBlueprint}>
   */
  protected array $mixins = [];

  /**
   * The structure that brought each key mixed in, by key, at the owner's.
   *
   * @var array<string, string>
   */
  protected array $origins = [];

  /**
   * The keys declared as a bare `entity`, each with the map at the key.
   *
   * Its two parts are registered once its author is done narrowing it,
   * when the build step first asks for the children (attachments()).
   *
   * @var array<string, \Drupal\Core\TypedData\MapDataDefinition>
   */
  protected array $entities = [];

  /**
   * Constructs a blueprint.
   *
   * @param \Drupal\data_surface\DataSurfaceBuilderInterface $builder
   *   The engine's builder the blueprint fills.
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The typed data manager.
   * @param bool $outputs
   *   TRUE when this blueprint describes outputs rather than inputs.
   * @param \Drupal\data_surface\ResourceBuild\ResourceRegistry|null $registry
   *   What discovery found, which names a structure mixed in and says
   *   whether it may be; NULL to name it by its class and check nothing.
   * @param string|null $owner
   *   The resource or structure declaring through this blueprint, by
   *   name, for messages and the cycle check.
   * @param \Drupal\data_surface\ResourceBuild\ResourceBlueprint|null $into
   *   For a structure being mixed in, the blueprint it is mixed into;
   *   NULL for the owner's own.
   */
  public function __construct(
    DataSurfaceBuilderInterface $builder,
    TypedDataManagerInterface $typedDataManager,
    bool $outputs = FALSE,
    protected readonly ?ResourceRegistry $registry = NULL,
    protected readonly ?string $owner = NULL,
    protected readonly ?ResourceBlueprint $into = NULL,
  ) {
    parent::__construct($builder, $typedDataManager, $outputs);
  }

  /**
   * {@inheritdoc}
   *
   * Decision: see docs/decisions.md#a-mixin-is-merged-flat.
   */
  public function mixIn(string $structure, array $reads = []): static {
    $who = $this->name($this->owner);
    if ($this->outputs) {
      throw new \LogicException(sprintf('%s mixes %s into its outputs. A structure is mixed into what is sent; outputs hold no structures yet.', $who, $structure));
    }
    static::assertMixinReads($structure, $who, $reads);
    if (Shapes::isShape($structure)) {
      throw new \LogicException(sprintf('%s mixes %s in, a shape: a shape is one value, declared at a key with add(\'<key>\', %s::class, ...); mixIn() merges a structure\'s members.', $who, $structure, substr($structure, (int) strrpos($structure, '\\') + 1)));
    }
    if (!is_subclass_of($structure, DefinesInputsInterface::class)) {
      throw new \LogicException(sprintf('%s mixes %s in, which is no resource or structure class: a structure is mixed in by its class.', $who, $structure));
    }
    $chain = $this->chain();
    if (in_array($structure, $chain, TRUE)) {
      throw new \LogicException(sprintf(
        '%s mixes %s in, through %s: a structure cannot be mixed into itself, directly or through another.',
        $who,
        $this->name($structure),
        implode(' -> ', array_map($this->name(...), [...$chain, $structure])),
      ));
    }
    $definition = $this->registry?->getDefinition($structure);
    if ($definition !== NULL && ($definition->storage !== NULL || $definition->identity !== [])) {
      throw new \LogicException(sprintf(
        '%s mixes %s in, which names %s. A mixed-in structure\'s members are the owner\'s keys, stored by the owner: mix in a structure, or attach a resource under a key of its own.',
        $who,
        $definition->id,
        $definition->storage !== NULL ? 'storage of its own' : 'identity keys',
      ));
    }
    if (is_subclass_of($structure, DefinesOutputsInterface::class)) {
      throw new \LogicException(sprintf('%s mixes %s in, which declares outputs. A structure\'s inputs are mixed in; its outputs are not, yet.', $who, $this->name($structure)));
    }
    $mixin = (new static($this->builder, $this->typedDataManager, FALSE, $this->registry, $structure, $this))->useShapes($this->shapes)->useReferences($this->references);
    $structure::defineInputs($mixin);
    $this->mixins[] = ['class' => $structure, 'reads' => $reads, 'blueprint' => $mixin];
    return $this;
  }

  /**
   * Refuses a wiring that is not a port name mapped to a key.
   *
   * @param string $structure
   *   The structure being mixed in.
   * @param string $who
   *   The owner, for the message.
   * @param array $reads
   *   The wiring, as handed over.
   *
   * @throws \InvalidArgumentException
   *   When an entry is not one.
   */
  protected static function assertMixinReads(string $structure, string $who, array $reads): void {
    foreach ($reads as $port => $wired) {
      if (!is_string($port) || $port === '' || !is_string($wired) || $wired === '') {
        throw new \InvalidArgumentException(sprintf('mixIn() was handed reads for %s that do not map a port, by its name, to a key of %s, by its name: [\'port\' => \'key\'].', $structure, $who));
      }
    }
  }

  /**
   * Gets the structures mixed in through this blueprint.
   *
   * @return array<int, array{class: class-string, reads: array<string, string>, blueprint: \Drupal\data_surface\ResourceBuild\ResourceBlueprint}>
   *   Each structure's class, its ports' wiring, and the blueprint it was
   *   declared through, which holds its keys (those of a structure it
   *   mixes in itself among them), its ports and its rules; in order.
   */
  public function mixins(): array {
    return $this->mixins;
  }

  /**
   * {@inheritdoc}
   *
   * A structure's members mixed in are the owner's keys, so are its keys
   * declared with a shape.
   */
  public function shapedKeys(): array {
    $shaped = parent::shapedKeys();
    foreach ($this->mixins as $mixin) {
      $shaped += $mixin['blueprint']->shapedKeys();
    }
    return $shaped;
  }

  /**
   * Answers whether this blueprint is a structure's being mixed in.
   *
   * @return bool
   *   TRUE inside mixIn().
   */
  public function isMixedIn(): bool {
    return $this->into !== NULL;
  }

  /**
   * Names a resource or structure for a message.
   *
   * @param string|null $name
   *   Its class, or its id.
   *
   * @return string
   *   Its id where discovery knows it, otherwise what was given.
   */
  protected function name(?string $name): string {
    if ($name === NULL) {
      return 'This blueprint';
    }
    try {
      return $this->registry?->getDefinition($name)->id ?? $name;
    }
    catch (\InvalidArgumentException) {
      return $name;
    }
  }

  /**
   * Lists the owner and every structure being mixed in on the way here.
   *
   * @return string[]
   *   The outermost first.
   */
  protected function chain(): array {
    $chain = $this->into?->chain() ?? [];
    if ($this->owner !== NULL) {
      $chain[] = $this->owner;
    }
    return $chain;
  }

  /**
   * Gets the owner's blueprint, which every structure mixed in fills.
   *
   * @return \Drupal\data_surface\ResourceBuild\ResourceBlueprint
   *   The outermost blueprint.
   */
  protected function root(): ResourceBlueprint {
    return $this->into?->root() ?? $this;
  }

  /**
   * Records a key a structure mixed in brought, at every level above it.
   *
   * @param string $key
   *   The key.
   * @param string $structure
   *   The structure that declared it.
   */
  protected function adopt(string $key, string $structure): void {
    $this->keys[] = $key;
    if ($this->into === NULL) {
      $this->origins[$key] = $structure;
      return;
    }
    $this->into->adopt($key, $structure);
  }

  /**
   * {@inheritdoc}
   *
   * A key is declared once among the owner's and every structure mixed
   * in, since a structure's members are the owner's keys: a clash is
   * refused naming both who declared it.
   */
  public function addDefinition(string $key, DataDefinitionInterface $definition, mixed $default = NULL): DataDefinitionInterface {
    $root = $this->root();
    if (!$this->outputs && ($this->into !== NULL || isset($root->origins[$key])) && $root->isDeclared($key)) {
      $before = $root->origins[$key] ?? NULL;
      $owner = $this->name($root->owner);
      throw new \LogicException(match (TRUE) {
        $this->into === NULL => sprintf('%s declares "%s", which %s, mixed in, declares already. A mixed-in structure\'s members are the owner\'s keys, so a name is declared once: rename one, or attach the structure under a key of its own.', $owner, $key, $this->name($before)),
        $before === NULL => sprintf('%s, mixed into %s, declares "%s", which %s declares too. A mixed-in structure\'s members are the owner\'s keys, so a name is declared once: rename one, or attach the structure under a key of its own.', $this->name($this->owner), $owner, $key, $owner),
        default => sprintf('%s, mixed into %s, declares "%s", which %s, also mixed in, declares too. A mixed-in structure\'s members are the owner\'s keys, so a name is declared once.', $this->name($this->owner), $owner, $key, $this->name($before)),
      });
    }
    parent::addDefinition($key, $definition, $default);
    if ($this->into !== NULL) {
      $this->into->adopt($key, (string) $this->owner);
    }
    return $definition;
  }

  /**
   * {@inheritdoc}
   */
  public function attach(string $key, string $child, array $reads = []): MapDataDefinition {
    $this->assertHoldsNoChild('attaches ' . $child, $key);
    return parent::attach($key, $child, $reads);
  }

  /**
   * {@inheritdoc}
   *
   * A key declared as a bare `entity` holds its two parts, registered for
   * what its author narrowed it by.
   */
  public function attachments(): array {
    foreach ($this->entities as $key => $shell) {
      assert($this->registry !== NULL);
      $owner = $this->owner === NULL ? NULL : $this->registry->getDefinition($this->owner);
      $this->attachments[$key] = $this->registry->defineReference(
        ($owner->id ?? $this->name($this->owner)) . '.' . $key,
        $owner->module ?? 'data_surface',
        EntityReferenceStructure::filter($shell),
      );
    }
    $this->entities = [];
    return parent::attachments();
  }

  /**
   * {@inheritdoc}
   *
   * Decision: see
   * docs/decisions.md#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up.
   */
  protected function addEntity(string $key, string|\Stringable $label, mixed $default): DataDefinition {
    if ($default !== NULL) {
      throw new \LogicException(sprintf('add("%s", "entity") was given a default: a reference to an entity of any type is two keys, its entity type and its id, which start from nothing.', $key));
    }
    if ($this->registry === NULL || $this->references === NULL || $this->outputs) {
      throw new \LogicException(sprintf('add("%s", "entity") declares a reference to an entity of any type where nothing can attach its two parts: a resource or a structure declares it among its inputs.', $key));
    }
    // Held for its two parts, registered once its author is done with it
    // (attachments()).
    $this->assertHoldsNoChild('attaches a reference to an entity of any type', $key);
    $shell = $this->reserveChild('add', $key);
    $this->attachments[$key] = EntityReferenceStructure::class;
    $this->wirings[$key] = [];
    $shell->setLabel($label);
    $this->entities[$key] = $shell;
    return $shell;
  }

  /**
   * {@inheritdoc}
   */
  public function attachList(string $key, string $child, ?int $min = NULL, ?int $max = NULL): ListDataDefinition {
    $this->assertHoldsNoChild('attaches a collection of ' . $child, $key);
    return parent::attachList($key, $child, $min, $max);
  }

  /**
   * {@inheritdoc}
   */
  public function attachVariants(string $key, string $by, ?string $from = NULL): MapDataDefinition {
    $this->assertHoldsNoChild('attaches variants', $key);
    if ($from !== NULL && !is_subclass_of($from, SiteListInterface::class)) {
      throw new \LogicException(sprintf('attachVariants("%s") names %s as where its variants come from, which does not implement %s: a provider lists the site\'s variants, each with its schema or structure, with list().', $key, $from, SiteListInterface::class));
    }
    $shell = $this->reserveChild('attachVariants', $key);
    $this->variants[$key] = $by;
    if ($from !== NULL) {
      $this->variantsFrom[$key] = $from;
      DefinitionMetadata::setVariantsFrom($shell, $from);
    }
    return $shell;
  }

  /**
   * Gets the providers of variants from the site, by the key they fill.
   *
   * Decision: see docs/decisions.md#a-schema-can-be-read-back-into-a-structure.
   *
   * @return array<string, class-string<\Drupal\data_surface\Resource\SiteListInterface>>
   *   The provider of each key that names one.
   */
  public function variantsFrom(): array {
    return $this->variantsFrom;
  }

  /**
   * Registers a structure a schema read into this blueprint describes.
   *
   * @param string $id
   *   Its id in the document.
   * @param array $root
   *   The document, as SchemaReader prepared it.
   * @param string $pointer
   *   The JSON pointer of the structure's object in it.
   * @param string|null $hash
   *   The document's hash (documentHash()), when the caller took it
   *   already; NULL to take it here.
   *
   * @return string
   *   The name it is attached, listed, mixed in or chosen by.
   *
   * @internal
   *   For SchemaReader alone.
   */
  public function readStructure(string $id, array $root, string $pointer, ?string $hash = NULL): string {
    if ($this->registry === NULL) {
      throw new \LogicException(sprintf('The document read into %s holds a structure at %s, and this blueprint has no registry to build one by.', $this->name($this->owner), $pointer === '' ? 'its root' : $pointer));
    }
    $owner = $this->owner === NULL ? NULL : $this->registry->getDefinition($this->owner);
    // A document is read a frame at a time, each naming the structures it
    // holds: its hash is taken once and handed on with it.
    $hash ??= static::documentHash($root);
    return $this->registry->defineRead(
      $id,
      $owner->module ?? 'data_surface',
      new ResourceCallable(SchemaReader::class, 'fill', [$root, $pointer, $hash]),
      hash('xxh128', serialize([$id, $hash, $pointer])),
    );
  }

  /**
   * Hashes a document a structure is read from.
   *
   * @param array $root
   *   The document, as SchemaReader prepared it.
   *
   * @return string
   *   Its hash: the same for two documents that say the same.
   *
   * @internal
   *   For SchemaReader alone.
   */
  public static function documentHash(array $root): string {
    return hash('xxh128', serialize($root));
  }

  /**
   * Gets the id of what declares through this blueprint.
   *
   * @return string
   *   Its #[Resource] or #[Structure] id, a derived or read one's id, or
   *   its name where the registry does not know it.
   *
   * @internal
   *   For SchemaReader alone.
   */
  public function ownerId(): string {
    return $this->name($this->owner);
  }

  /**
   * Gets a key declared through this blueprint, or its owner's.
   *
   * @param string $key
   *   The key.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   Its definition, or NULL.
   *
   * @internal
   *   For SchemaReader alone.
   */
  public function definitionOf(string $key): ?DataDefinitionInterface {
    return $this->builder->getDefinition($key);
  }

  /**
   * Finds the shape of an id, for a schema read into this blueprint.
   *
   * @param string $id
   *   A def's key.
   *
   * @return class-string|null
   *   The class of the shape of that id, or NULL when discovery found none.
   *
   * @internal
   *   For SchemaReader alone.
   */
  public function shapeOf(string $id): ?string {
    return $this->registry !== NULL && $this->registry->hasShape($id) ? $this->registry->getShape($id)->class : NULL;
  }

  /**
   * Attaches variants a schema read into this blueprint states.
   *
   * @param string $key
   *   The key the variants sit at.
   * @param string $by
   *   The deciding key.
   * @param array<string, array{name: string, label: string|null}> $variants
   *   Each value's structure, by its read name, and its label, if any.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The map at the key.
   *
   * @internal
   *   For SchemaReader alone.
   */
  public function attachReadVariants(string $key, string $by, array $variants): MapDataDefinition {
    $shell = $this->attachVariants($key, $by);
    $this->readVariants[$key] = $variants;
    DefinitionMetadata::setReadVariants($shell);
    return $shell;
  }

  /**
   * Gets the variants a schema read into this blueprint states, by key.
   *
   * @return array<string, array<string, array{name: string, label: string|null}>>
   *   Each key's variants: each value's read structure and label.
   */
  public function readVariants(): array {
    return $this->readVariants;
  }

  /**
   * Mixes in a structure a schema read into this blueprint refers to.
   *
   * @param string $name
   *   The read structure's name (readStructure()).
   *
   * @return $this
   *
   * @internal
   *   For SchemaReader alone.
   */
  public function mixInRead(string $name): static {
    $mixin = (new static($this->builder, $this->typedDataManager, FALSE, $this->registry, $name, $this))->useShapes($this->shapes)->useReferences($this->references);
    $inputs = $this->registry?->getDefinition($name)->inputs;
    if ($inputs !== NULL) {
      // A read structure's inputs are SchemaReader::fill(), a static
      // method, handed the blueprint first.
      ($inputs->class . '::' . $inputs->method)($mixin, ...$inputs->arguments);
    }
    $this->mixins[] = ['class' => $name, 'reads' => [], 'blueprint' => $mixin];
    return $this;
  }

  /**
   * Holds the frame as a whole to what a schema read into it says of it.
   *
   * @param array $schema
   *   The JSON Schema left over.
   *
   * @return $this
   *
   * @internal
   *   For SchemaReader alone.
   */
  public function constrainFrame(array $schema): static {
    $this->builder->constrainFrame($schema);
    return $this;
  }

  /**
   * Locks a key a schema read into this blueprint says is read only.
   *
   * @param string $key
   *   The key.
   *
   * @return $this
   *
   * @internal
   *   For SchemaReader alone.
   */
  public function lockRead(string $key): static {
    $this->builder->lock($key);
    return $this;
  }

  /**
   * {@inheritdoc}
   *
   * Decision: see docs/decisions.md#keys-that-come-from-the-site.
   */
  public function addEach(string $key, string $type, string $from, mixed $default = NULL): MapDataDefinition {
    $this->assertHoldsNoChild('declares keys from the site', $key);
    if (str_contains($type, '\\') && !Shapes::isShape($type)) {
      throw new \LogicException(is_subclass_of($type, DefinesInputsInterface::class)
        ? sprintf('addEach("%s") was handed %s, a resource or structure: an entry that is a group of keys is a child, attachEach(\'%s\', ...).', $key, $type, $key)
        : sprintf('addEach("%s") was handed %s, which is no typed data type and no shape: each entry is declared as add() declares a key.', $key, $type));
    }
    return $this->reserveEach('addEach', $key, $type, $from, $default, FALSE);
  }

  /**
   * {@inheritdoc}
   *
   * Decision: see docs/decisions.md#keys-that-come-from-the-site.
   */
  public function attachEach(string $key, string $structure, string $from, array $reads = []): MapDataDefinition {
    $this->assertHoldsNoChild('attaches a child per thing the site has', $key);
    static::assertNoShape('attachEach', $key, $structure);
    return $this->reserveEach('attachEach', $key, $structure, $from, NULL, TRUE, $reads);
  }

  /**
   * Holds a key for a map of keys from the site.
   *
   * @param string $verb
   *   addEach or attachEach, for the messages.
   * @param string $key
   *   The key.
   * @param string $each
   *   What each entry is: a type, a shape, or a structure.
   * @param string $from
   *   The provider's class.
   * @param mixed $default
   *   What every entry starts from.
   * @param bool $child
   *   TRUE when each entry is a child.
   * @param array<string, string> $reads
   *   For a child, each port mapped to the sibling map it reads per entry.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The map at the key.
   *
   * @throws \LogicException
   *   When the provider is none.
   */
  protected function reserveEach(string $verb, string $key, string $each, string $from, mixed $default, bool $child, array $reads = []): MapDataDefinition {
    if (!is_subclass_of($from, SiteListInterface::class)) {
      throw new \LogicException(sprintf('%s("%s") names %s as where its keys come from, which does not implement %s: a provider lists the site\'s things with list().', $verb, $key, $from, SiteListInterface::class));
    }
    $shell = $this->reserveChild($verb, $key);
    $this->fromSite[$key] = [
      'each' => $each,
      'from' => $from,
      'default' => $default,
      'child' => $child,
      'reads' => $reads,
    ];
    return $shell;
  }

  /**
   * Gets the maps of keys from the site declared through this blueprint.
   *
   * @return array<string, array{each: string, from: class-string<\Drupal\data_surface\Resource\SiteListInterface>, default: mixed, child: bool, reads: array<string, string>}>
   *   What each entry is, the provider, the default for every entry,
   *   whether each entry is a child, and the sibling map each of its ports
   *   reads per entry, by key, in declaration order.
   */
  public function fromSite(): array {
    return $this->fromSite;
  }

  /**
   * Refuses a child in a structure being mixed in.
   *
   * @param string $verb
   *   What it did, for the message.
   * @param string $key
   *   The key.
   *
   * @throws \LogicException
   *   Inside mixIn().
   */
  protected function assertHoldsNoChild(string $verb, string $key): void {
    if ($this->into === NULL) {
      return;
    }
    throw new \LogicException(sprintf(
      '%s, mixed into %s, %s at "%s". A mixed-in structure holds keys and maps, not children, for now: attach %s under a key of its own instead.',
      $this->name($this->owner),
      $this->name($this->root()->owner),
      $verb,
      $key,
      $this->name($this->owner),
    ));
  }

  /**
   * {@inheritdoc}
   *
   * A structure being mixed in rewords its own members only.
   */
  public function describe(string $key, string|\Stringable|null $label = NULL, string|\Stringable|null $description = NULL, ?string $after = NULL, ?ArrangementGroup $group = NULL, ?int $weight = NULL, string|\Stringable|null $placeholder = NULL, string|\Stringable|null $prefix = NULL, string|\Stringable|null $suffix = NULL, string|\Stringable|null $help = NULL): static {
    if ($this->into !== NULL && !in_array($key, $this->keys, TRUE)) {
      throw new \LogicException(sprintf('%s, mixed into %s, rewords "%s", which it does not declare: a mixed-in structure names its own members.', $this->name($this->owner), $this->name($this->root()->owner), $key));
    }
    return parent::describe($key, $label, $description, $after, $group, $weight, $placeholder, $prefix, $suffix, $help);
  }

  /**
   * {@inheritdoc}
   *
   * Decision: see docs/decisions.md#a-port-is-a-live-sibling-value.
   */
  public function reads(string $port, string $type, bool $required = FALSE): static {
    if ($this->outputs) {
      throw new \LogicException(sprintf('reads() was asked to read "%s" into outputs. An output is emitted, never refined, so it has nothing to read.', $port));
    }
    // A structure being mixed in reads its ports under names of its own,
    // which may well be the owner's keys it is wired to.
    if (isset($this->ports[$port]) || in_array($port, $this->declared(), TRUE) || ($this->into === NULL && $this->isDeclared($port))) {
      throw new \LogicException(sprintf('"%s" is already declared in this blueprint, as %s, so it cannot be read as a port too: a port has a name of its own.', $port, isset($this->ports[$port]) ? 'a port' : 'a key'));
    }
    $shape = NULL;
    if (Shapes::isShape($type)) {
      // A port may read a shape: the key wired to it is then of that shape.
      // Decision: see docs/decisions.md#a-shape-is-a-named-definition.
      $shape = Shapes::idOf($type);
      $type = $type::define()->getDataType();
    }
    try {
      $this->typedDataManager->getDefinition($type);
    }
    catch (PluginNotFoundException $e) {
      throw new \InvalidArgumentException(sprintf('The "%s" port reads a "%s", which is no typed data type and no shape.', $port, $type), 0, $e);
    }
    $this->ports[$port] = new Port($port, $type, $required, $shape);
    if ($this->into === NULL) {
      // A mixed-in structure's ports are no ports of its owner's: the owner
      // wires them to its own keys, and only the structure's refiners read
      // them, under these names.
      $this->builder->addPort($this->ports[$port]);
    }
    return $this;
  }

  /**
   * Gets the ports declared through this blueprint.
   *
   * @return array<string, \Drupal\data_surface\Port>
   *   The ports, by name, in declaration order.
   */
  public function ports(): array {
    return $this->ports;
  }

  /**
   * {@inheritdoc}
   */
  protected function assertNoPort(string $key): void {
    if (isset($this->ports[$key])) {
      throw new \LogicException(sprintf('"%s" is read as a port by this blueprint, so it cannot also be a key: a port is the container\'s key, read under a name of the structure\'s own.', $key));
    }
  }

  /**
   * {@inheritdoc}
   *
   * Decision: see
   * docs/decisions.md#a-computed-key-is-a-pure-function-of-its-sources.
   */
  public function compute(string $key, string $type, string|\Stringable $label, array $from, string $using, string $when = Compute::ON_CREATE): DataDefinition {
    $refuse = fn (string $why): \LogicException => new \LogicException(sprintf('compute("%s") on %s %s', $key, $this->name($this->owner), $why));
    if ($this->outputs) {
      throw $refuse('declares a computed output: an output is emitted, never stored, so it has nothing to compute; a computed key is an input the caller does not type.');
    }
    if ($this->into !== NULL) {
      throw $refuse(sprintf('is inside %s, mixed in: a computed key is a resource\'s own key, for now.', $this->name($this->root()->owner)));
    }
    if ($this->owner !== NULL && $this->registry?->hasDefinition($this->owner) && !$this->registry->getDefinition($this->owner)->isResource()) {
      throw $refuse('is in a structure: a computed key is a resource\'s own key, at its top, for now. Declare it on the resource that holds the structure.');
    }
    if (!is_subclass_of($using, ComputesValueInterface::class)) {
      throw $refuse(sprintf('names %s, which implements no %s: the class it names has a static compute().', $using, ComputesValueInterface::class));
    }
    if (!in_array($when, Compute::ALL, TRUE)) {
      throw $refuse(sprintf('says when: "%s"; it is Compute::ON_CREATE, Compute::ON_CHANGE or Compute::UNLESS_SENT.', $when));
    }
    if ($from === [] || !array_is_list($from)) {
      throw $refuse('names no key it is computed from: from: lists one or more keys.');
    }
    foreach ($from as $source) {
      if ($source === $key || !in_array($source, $this->keys, TRUE)) {
        throw $refuse(sprintf('is computed from "%s", which is no key declared before it in this blueprint.', $source));
      }
    }
    $definition = $this->add($key, $type, $label);
    if ($definition instanceof ComplexDataDefinitionInterface) {
      throw $refuse('is a map: a computed key holds one value.');
    }
    DefinitionMetadata::setComputed($definition, new Computed($from, $using, $when));
    return $definition;
  }

  /**
   * {@inheritdoc}
   */
  public function addCacheableDependency(CacheableDependencyInterface $dependency): static {
    $this->builder->addCacheableDependency($dependency);
    return $this;
  }

  /**
   * Gets the variants attached through this blueprint.
   *
   * @return array<string, string>
   *   The deciding key of the variants at each key, keyed by key, in
   *   declaration order.
   *   Discovery's #[Variant] classes fill them.
   */
  public function variants(): array {
    return $this->variants;
  }

  /**
   * {@inheritdoc}
   */
  public function declared(): array {
    return array_merge(parent::declared(), array_keys($this->variants), array_keys($this->fromSite));
  }

  /**
   * {@inheritdoc}
   */
  protected function declare(string $key, DataDefinitionInterface $definition, mixed $default): void {
    if ($this->outputs) {
      if ($default !== NULL) {
        DefinitionMetadata::setDefaultValue($definition, $default);
      }
      $this->builder->setOutputDefinition($key, $definition);
      return;
    }
    $this->builder->setDefinition($key, $definition);
    if ($default !== NULL) {
      $this->builder->setDefault($key, $default);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function isDeclared(string $key): bool {
    return $this->outputs
      ? $this->builder->getOutputDefinition($key) !== NULL
      : $this->builder->getDefinition($key) !== NULL;
  }

}
