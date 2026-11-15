<?php

declare(strict_types=1);

namespace Drupal\surface;

use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\NodeDefinition;
use Drupal\surface\Refinement\ApplicabilityInterface;
use Drupal\surface\Refinement\DeclaredRefiner;
use Drupal\surface\Refinement\RuntimeRefiner;
use PortableSchema\Builder\Schema;

/**
 * One key of a data surface, and everything known about it.
 *
 * Before this existed the data surface held five arrays keyed by the
 * same names (the definitions, the refinement map, the refiner chains,
 * the locked keys and the contributed values), and every one of them
 * could be out of step with the others. An entry is the single answer to
 * "what does this data surface say about this key", validated once when the
 * map around it is built.
 *
 * The refiner chains and the contributed values live here rather than
 * beside the map because refinement needs all three of them for one key
 * at a time: the advertised definition to clone, the contributions to
 * divide its option space by, and the chains to run over each slice.
 * The data surface's own cacheability is not per key and stays on the
 * data surface.
 *
 * An entry holds one form of its key as the source of truth: the
 * definition its source gave (the constructor, withDefinition()), or the
 * built node its source gave (ofSchema(), withSchema()). Both answers
 * are always given: schema() and definition(). The rules:
 * - Definition-first: schema() reads the node from the definition on the
 *   first ask (DefinitionSchema::of()) and keeps it, so every consumer
 *   that has the entry reads one node, with no service.
 * - Node-first: definition() derives the definition from the node on the
 *   first ask (NodeDefinition::of()) and keeps it. The derive refuses a
 *   node of a class other than the three it derives, of a data type
 *   core's typed data manager does not define, or whose derived
 *   constraints differ from its own; ofSchema() does not check, so the
 *   refusal is thrown at the first definition() call, with the key's name.
 * - The other form is lazy: nothing reads or derives it when an entry is
 *   made, so a key refined and never described reads nothing. The read's
 *   one refusal, a definition that holds itself, is thrown at the first
 *   schema() call, never at build.
 * - An entry made with the same source object keeps the other form with
 *   it (readingFrom(), withDefinition() handed the definition it holds,
 *   withSchema() handed the node it holds); one made with another source
 *   starts with that form alone.
 * - Only the form given is serialized: a build cache row is the same
 *   whether or not the other form was read, and an entry read back from
 *   one reads or derives it again on the first ask.
 * - Neither form is written after the entry is made: the builder seals
 *   the definitions, and a refiner writes a deep copy
 *   (Surface::deepClone()). With assertions on, every later ask of the
 *   form that was not given checks it still says what the source says.
 *   EntryNodeIsNeverStaleTest proves it for every example surface and
 *   value set.
 * Every source gives a definition today. DefinitionMap::$nodeFirst, a
 * switch for tests and probes, makes every entry a built data surface
 * is sealed with node-first, which proves the derived definition serves
 * every reader that still asks for one.
 *
 * Decisions: see
 * docs/decisions.md#an-entry-holds-its-node-the-memo-is-gone and
 * docs/decisions.md#an-entry-holds-the-form-its-source-gave.
 *
 * @see \Drupal\surface\DefinitionMap
 *   The ordered collection of entries a data surface is sealed with.
 */
final class Entry {

  /**
   * The key's Typed Data definition: given, or derived from the node.
   *
   * NULL on a node-first entry until definition() derives it. Serialized
   * only on a definition-first entry (__sleep()).
   */
  private ?DataDefinitionInterface $definition = NULL;

  /**
   * The key's built node: given, or read from the definition.
   *
   * NULL on a definition-first entry until schema() reads it. Serialized
   * only on a node-first entry (__sleep()).
   */
  private ?Schema $node = NULL;

  /**
   * Whether the node is the source of truth, the definition derived.
   */
  private bool $nodeFirst = FALSE;

  /**
   * The children a refinement built the definition from, by path.
   *
   * NULL while the definition is the one the key was sealed with; the
   * refined children otherwise, by the path below the key ('' for the
   * key's own child or a collection's item, `<module>` at a mount), none
   * where the refinement gave none. What a shell's node is composed of
   * (schema()). Serialized only when not NULL (__sleep()): a sealed entry
   * serializes as it did before, and a refined one wakes with the
   * children its definition was built from, the same objects its
   * definition holds, since one serialized graph keeps object identity.
   *
   * @var array<string, \Drupal\surface\SurfaceInterface>|null
   */
  private ?array $frames = NULL;

  /**
   * What a node-first entry is constructed with, then drops.
   *
   * The constructor takes a definition, as every caller hands one;
   * ofSchema() and the copies of a node-first entry hand it this, and
   * nothing reads it.
   */
  private static ?DataDefinitionInterface $unread = NULL;

  /**
   * Constructs an Entry.
   *
   * @param string $name
   *   The definition key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   What the key accepts.
   * @param string $contributor
   *   Who introduced the key: the owner for everything the resource or
   *   structure declares itself, and a module name for a mounted key.
   * @param bool $locked
   *   Whether the key's value is fixed to what storage holds, or to the
   *   declared default when storage holds nothing.
   * @param string[] $dependencies
   *   The sibling keys this key's refinement reads, in declaration
   *   order. Empty for a key that never refines. A key an alter mounted
   *   is named by its dotted path, `third_party_settings.<module>.<key>`.
   * @param array<string, array<\Drupal\surface\SurfaceRefinerInterface>> $refiners
   *   Refiner chains keyed by contributor, the owner's own under
   *   SurfaceInterface::OWNER. Always empty on an output's entry:
   *   outputs are never refined.
   * @param array<string, array> $contributions
   *   The values contributed to this key at build time, keyed by the
   *   provider that added them. What is not here is the owner's.
   * @param \Drupal\surface\Attachment|null $attachment
   *   The child fixed at this key, when the key holds an attached
   *   structure: its definition is then the child's definitions as a map.
   * @param \Drupal\surface\Variants|null $variants
   *   The variant table, when the key has variants a sibling chooses
   *   between: its definition is then the `any` placeholder until the
   *   sibling holds a value, and that variant's map afterwards.
   * @param array<string, string[]> $paths
   *   For a key refined one property at a time (the mount, one property
   *   per key an alter refines there), what each property refines
   *   against, keyed by its dotted path below this key. $dependencies is
   *   their union, which is what gates the key; these are what a form
   *   rebuilds by.
   * @param string[] $runtimeOnly
   *   The keys in $dependencies that only a runtime refiner of this key
   *   watches: what refinement waits for, and what nothing stating the
   *   key ahead of time reads (staticDependencies()).
   * @param \Drupal\surface\Collection|null $list
   *   The child every item is, when the key is a collection: its
   *   definition is then a list whose item is the child's map. The key
   *   is the parent's own, refined by the parent's refiners (its Count)
   *   and locked by the parent; each item is the child's.
   * @param array<string, int> $watchedBy
   *   For each key in $dependencies, which kinds of link watch it, as a
   *   mask of SurfaceInterface::CODED, DECLARED and RUNTIME: a key
   *   only a declared rule reads gates nothing. Empty for an entry built
   *   by hand, whose every dependency is read as a coded refiner's, a
   *   runtime one's in $runtimeOnly.
   * @param array<string, \Drupal\surface\Attachment> $mounted
   *   The structures alters attached at their mounts inside this key, by
   *   the path below it (`<module>`), each with its ports wired: the
   *   `third_party_settings` key's alone. The key stays a plain map the
   *   parent refines, accepts and stores; each structure refines its own
   *   keys inside it, in its own frame, and its answer is written there.
   * @param bool $held
   *   Whether the key is locked by access rather than by what an
   *   operation knows: it holds what is stored, which nothing ahead of
   *   time can state, rather than its declared default. A mixed-in
   *   structure's members are, when its access answers locked.
   * @param bool $runtimeAccess
   *   Whether an access answer for the key is decided once the subject is
   *   known: in a data surface built for the account alone
   *   (ResourcesInterface::buildStatic()), such an answer was asked and
   *   left out, the key kept open, and the full schema marks it.
   * @param string|\Stringable|null $lockedBecause
   *   Why the key is locked, when an access answer locked it and said
   *   why: what a form prints beside the fixed element. NULL for a key
   *   locked by what an operation knows, or by no answer with a reason.
   */
  public function __construct(
    public readonly string $name,
    DataDefinitionInterface $definition,
    public readonly string $contributor = SurfaceInterface::OWNER,
    public readonly bool $locked = FALSE,
    public readonly array $dependencies = [],
    public readonly array $refiners = [],
    public readonly array $contributions = [],
    public readonly ?Attachment $attachment = NULL,
    public readonly ?Variants $variants = NULL,
    public readonly array $paths = [],
    public readonly array $runtimeOnly = [],
    public readonly ?Collection $list = NULL,
    public readonly array $watchedBy = [],
    public readonly array $mounted = [],
    public readonly bool $held = FALSE,
    public readonly bool $runtimeAccess = FALSE,
    public readonly string|\Stringable|null $lockedBecause = NULL,
  ) {
    $this->definition = $definition;
  }

  /**
   * Makes an entry whose source gave its built node: node-first.
   *
   * Its definition is derived from the node on the first definition()
   * call (NodeDefinition::of()) and kept. Nothing is checked here: a node
   * no definition derives from is refused at that call.
   *
   * @param string $name
   *   The definition key.
   * @param \PortableSchema\Builder\Schema $node
   *   What the key accepts, as a built node (DefinitionSchema::of()).
   * @param string $contributor
   *   Who introduced the key; see the constructor for this and the rest.
   * @param bool $locked
   *   Whether the key's value is fixed.
   * @param string[] $dependencies
   *   The sibling keys this key's refinement reads.
   * @param array<string, array<\Drupal\surface\SurfaceRefinerInterface>> $refiners
   *   Refiner chains keyed by contributor.
   * @param array<string, array> $contributions
   *   The values contributed to this key, keyed by provider.
   * @param \Drupal\surface\Attachment|null $attachment
   *   The child fixed at this key, if any.
   * @param \Drupal\surface\Variants|null $variants
   *   The variant table, if any.
   * @param array<string, string[]> $paths
   *   What each property of a key refined one property at a time refines
   *   against.
   * @param string[] $runtimeOnly
   *   The dependencies only a runtime refiner watches.
   * @param \Drupal\surface\Collection|null $list
   *   The child every item is, if the key is a collection.
   * @param array<string, int> $watchedBy
   *   Which kinds of link watch each dependency.
   * @param array<string, \Drupal\surface\Attachment> $mounted
   *   The structures alters attached at their mounts inside this key.
   * @param bool $held
   *   Whether the key is locked by access.
   * @param bool $runtimeAccess
   *   Whether an access answer for the key is decided once the subject is
   *   known.
   * @param string|\Stringable|null $lockedBecause
   *   Why the key is locked, if an access answer said why.
   *
   * @return static
   *   The entry.
   */
  public static function ofSchema(
    string $name,
    Schema $node,
    string $contributor = SurfaceInterface::OWNER,
    bool $locked = FALSE,
    array $dependencies = [],
    array $refiners = [],
    array $contributions = [],
    ?Attachment $attachment = NULL,
    ?Variants $variants = NULL,
    array $paths = [],
    array $runtimeOnly = [],
    ?Collection $list = NULL,
    array $watchedBy = [],
    array $mounted = [],
    bool $held = FALSE,
    bool $runtimeAccess = FALSE,
    string|\Stringable|null $lockedBecause = NULL,
  ): static {
    $entry = new static($name, self::$unread ??= new DataDefinition(), $contributor, $locked, $dependencies, $refiners, $contributions, $attachment, $variants, $paths, $runtimeOnly, $list, $watchedBy, $mounted, $held, $runtimeAccess, $lockedBecause);
    $entry->definition = NULL;
    $entry->node = $node;
    $entry->nodeFirst = TRUE;
    return $entry;
  }

  /**
   * Returns the same entry with another definition: definition-first.
   *
   * The one mutation refinement makes: everything else about a key (who
   * introduced it, whether it is locked, what it refines against, whose
   * values it carries) is settled at build time and survives
   * narrowing untouched. Handed the definition it holds, the new entry
   * is this one again, its source and its other form kept; handed
   * another, it is definition-first and starts unread.
   *
   * A key that holds a child frame, refined by its child, is handed the
   * refined children its definition was built from: its node is composed
   * of their entries' nodes (schema()).
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The narrowed definition.
   * @param array<string, \Drupal\surface\SurfaceInterface> $frames
   *   The refined children the definition holds, by the path below the
   *   key ('' for the key's own child, `<module>` at a mount).
   *
   * @return static
   *   The new entry.
   */
  public function withDefinition(DataDefinitionInterface $definition, array $frames = []): static {
    return $definition === $this->definition
      ? $this->remade($this->definition, $this->node, $this->nodeFirst, frames: $this->frames)
      : $this->remade($definition, NULL, FALSE, frames: $frames);
  }

  /**
   * Returns the same entry with another built node: node-first.
   *
   * The twin of withDefinition(). Handed the node it holds, the new entry
   * is node-first and keeps the definition it holds, which reads as that
   * node; handed another, it starts with the node alone.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The node.
   *
   * @return static
   *   The new entry.
   */
  public function withSchema(Schema $node): static {
    return $node === $this->node
      ? $this->remade($this->definition, $this->node, TRUE, frames: $this->frames)
      : $this->remade(NULL, $node, TRUE);
  }

  /**
   * Returns the same entry node-first from the node it reads.
   *
   * The node schema() gives (a shell's composed of its children's), as
   * the source; the definition is dropped and derived from the node on
   * the first ask. What DefinitionMap::$nodeFirst makes of each entry.
   *
   * @return static
   *   The new entry.
   */
  public function nodeFirst(): static {
    return $this->remade(NULL, $this->schema(), TRUE);
  }

  /**
   * Makes a copy of this entry with the forms and the source given.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $definition
   *   The definition, or NULL for a node-first entry not derived yet.
   * @param \PortableSchema\Builder\Schema|null $node
   *   The node, or NULL for a definition-first entry not read yet.
   * @param bool $nodeFirst
   *   Whether the node is the source.
   * @param \Drupal\surface\Attachment|null $attachment
   *   The attachment, NULL for this entry's.
   * @param array<string, \Drupal\surface\Attachment> $mounted
   *   The mounted structures that replace this entry's, by path.
   * @param array<string, \Drupal\surface\SurfaceInterface>|null $frames
   *   The refined children the definition was built from, NULL for a
   *   sealed one.
   *
   * @return static
   *   The copy.
   */
  private function remade(?DataDefinitionInterface $definition, ?Schema $node, bool $nodeFirst, ?Attachment $attachment = NULL, array $mounted = [], ?array $frames = NULL): static {
    $entry = new static(
      $this->name,
      $definition ?? (self::$unread ??= new DataDefinition()),
      $this->contributor,
      $this->locked,
      $this->dependencies,
      $this->refiners,
      $this->contributions,
      $attachment ?? $this->attachment,
      $this->variants,
      $this->paths,
      $this->runtimeOnly,
      $this->list,
      $this->watchedBy,
      $mounted + $this->mounted,
      $this->held,
      $this->runtimeAccess,
      $this->lockedBecause,
    );
    $entry->definition = $definition;
    $entry->node = $node;
    $entry->nodeFirst = $nodeFirst;
    $entry->frames = $frames;
    return $entry;
  }

  /**
   * Answers which form the entry holds as its source.
   *
   * For a reader that needs one fact and reads it from the form the entry
   * holds, so it reads no node off a definition-first entry and derives
   * no definition off a node-first one.
   *
   * Decision: see
   * docs/decisions.md#nothing-asks-for-a-definition-it-does-not-need.
   *
   * @return bool
   *   TRUE when its source gave the node (node-first), FALSE when its
   *   source gave the definition.
   */
  public function isNodeFirst(): bool {
    return $this->nodeFirst;
  }

  /**
   * Gets the key's Typed Data definition.
   *
   * Asked for only where an edge needs a definition: the options
   * service, a refiner, a widget's claim, ConstraintRunner. A key's facts
   * are read from its node (schema()), a key that holds a child frame's
   * too; a reader that needs one fact reads it from the form the entry
   * holds (isNodeFirst()).
   *
   * On a node-first entry it is derived from the node on the first call
   * (NodeDefinition::of(), which asks core's typed data manager whether
   * the type is defined) and kept: every later call returns the same
   * object.
   *
   * Decision: see docs/decisions.md#the-definition-of-an-entry-is-asked-for.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The definition.
   *
   * @throws \LogicException
   *   On a node-first entry, when no definition derives from its node.
   * @throws \AssertionError
   *   With assertions on, on a node-first entry, when the derived
   *   definition was written after it was derived.
   */
  public function definition(): DataDefinitionInterface {
    if ($this->definition === NULL) {
      assert($this->node !== NULL);
      try {
        return $this->definition = NodeDefinition::of($this->node);
      }
      catch (\LogicException $e) {
        throw new \LogicException(sprintf('The "%s" key\'s source gave a node, and no definition derives from it: %s', $this->name, $e->getMessage()), 0, $e);
      }
    }
    // With assertions on, each later ask of a node-first entry reads the
    // derived definition again: the two differ only where an edge wrote
    // the definition it was handed, which no code may do.
    assert(!$this->nodeFirst || DefinitionSchema::same($this->node, DefinitionSchema::of($this->definition)), sprintf('The definition of the "%s" key, derived from the node its source gave, was written after it was derived. A definition an entry gives is never written: write a copy and make an entry of it (withDefinition()).', $this->name));
    return $this->definition;
  }

  /**
   * Gets the key's built node.
   *
   * On a definition-first entry, read from the definition on the first
   * call (DefinitionSchema::of()) and kept: every later call returns the
   * same object. A key that holds a child frame (isShell()) composes it
   * instead (composed()): its own facts read once, around the nodes its
   * child's entries hold, so no node below it is read twice. On a
   * node-first entry, the node its source gave. It asks no service.
   *
   * @return \PortableSchema\Builder\Schema
   *   The built node.
   *
   * @throws \LogicException
   *   When the definition holds itself, which no node can be read from.
   * @throws \AssertionError
   *   With assertions on, on a definition-first entry, when the
   *   definition was written after its node was read, or when a shell's
   *   node was composed of other children than its definition holds.
   */
  public function schema(): Schema {
    if ($this->nodeFirst) {
      assert($this->node !== NULL);
      return $this->node;
    }
    // With assertions on, each later ask reads the definition again: the
    // two differ only where the definition was written after the entry
    // was made, which no code may do (a refiner writes a copy).
    assert($this->definition !== NULL);
    assert($this->node === NULL || DefinitionSchema::same($this->node, DefinitionSchema::of($this->definition)), sprintf('The definition of the "%s" key, its source, was written after its node was read. An entry\'s definition is never written: write a copy and make an entry of it (withDefinition()).', $this->name));
    if ($this->node === NULL && $this->isShell()) {
      $this->node = $this->composed($this->definition);
      // With assertions on, the first ask of a shell reads the definition
      // too: the two differ where the node was composed of children that
      // are not the ones the definition holds.
      assert(DefinitionSchema::same($this->node, DefinitionSchema::of($this->definition)), sprintf('The node of the "%s" key was composed of children that are not the ones its definition holds: a refinement that builds the definition from a child hands that child on (withDefinition()).', $this->name));
      return $this->node;
    }
    return $this->node ??= DefinitionSchema::of($this->definition);
  }

  /**
   * Composes a shell's node: its own facts around its children's nodes.
   *
   * The node DefinitionSchema::of() reads from the definition, each
   * property that is a child's key taken from that key's entry
   * (DefinitionSchema::around()):
   * - an attachment: the child's keys, the map's properties;
   * - variants: the placeholder holds none; a chosen variant's map holds
   *   that variant's keys;
   * - a collection: its item, a map of the item child's keys;
   * - a mount: each module's map, a structure's keys in it.
   * A property is taken from a child's entry where it is that entry's
   * definition, the very object: the sealed child's, or the refined
   * child a refinement built the definition from ($frames). At a sealed
   * mount a structure's keys are taken by name: the builder mounts a
   * deep copy of each of the structure's definitions
   * (SurfaceBuilder::mountStructure()), which reads as the original, and
   * nothing writes either. Any other property is read.
   *
   * Decision: see
   * docs/decisions.md#a-key-that-holds-a-child-frame-has-a-node-of-its-own.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition.
   *
   * @return \PortableSchema\Builder\Schema
   *   Its node.
   */
  private function composed(DataDefinitionInterface $definition): Schema {
    $frames = $this->frames ?? [];
    if ($this->list !== NULL) {
      if (!$definition instanceof ListDataDefinitionInterface) {
        return DefinitionSchema::around($definition);
      }
      $children = array_filter([$frames[''] ?? NULL, $this->list->item->child]);
      return DefinitionSchema::around($definition, items: static::framed($definition->getItemDefinition(), $children));
    }
    if ($this->mounted !== []) {
      $parts = [];
      if ($definition instanceof ComplexDataDefinitionInterface && DefinitionSchema::isMap($definition)) {
        foreach ($definition->getPropertyDefinitions() as $path => $part) {
          $path = (string) $path;
          $mounted = $this->mounted[$path] ?? NULL;
          if ($mounted !== NULL || isset($frames[$path])) {
            $parts[$path] = static::framed($part, array_filter([$frames[$path] ?? NULL]), $this->frames === NULL ? $mounted?->child : NULL);
          }
        }
      }
      return DefinitionSchema::around($definition, $parts);
    }
    $children = $this->attachment !== NULL ? [$this->attachment->child] : [];
    if ($this->variants !== NULL && DefinitionSchema::isMap($definition)) {
      // A chosen variant's map holds that variant's keys, and its child was
      // read to build the map: a variant the site lists and nothing read is
      // not built here.
      foreach ($this->variants->variants as $variant) {
        if ($variant->isSettled()) {
          $children[] = $variant->child;
        }
      }
    }
    return static::framed($definition, array_filter([$frames[''] ?? NULL, ...$children]));
  }

  /**
   * Reads a map around the nodes of the children's keys it holds.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $map
   *   The map.
   * @param \Drupal\surface\SurfaceInterface[] $children
   *   The children whose entries' definitions may be its properties.
   * @param \Drupal\surface\SurfaceInterface|null $byName
   *   A child whose entries' nodes are taken by name: a structure at a
   *   sealed mount.
   *
   * @return \PortableSchema\Builder\Schema
   *   The map's node.
   */
  private static function framed(DataDefinitionInterface $map, array $children, ?SurfaceInterface $byName = NULL): Schema {
    $nodes = [];
    if ($map instanceof ComplexDataDefinitionInterface && DefinitionSchema::isMap($map)) {
      foreach ($map->getPropertyDefinitions() as $name => $property) {
        $name = (string) $name;
        foreach ($children as $child) {
          $entry = $child->getDefinitions()->entry($name);
          if ($entry !== NULL && $entry->definition() === $property) {
            $nodes[$name] = $entry->schema();
            continue 2;
          }
        }
        $entry = $byName?->getDefinitions()->entry($name);
        if ($entry !== NULL) {
          $nodes[$name] = $entry->schema();
        }
      }
    }
    return DefinitionSchema::around($map, $nodes);
  }

  /**
   * Names what an entry serializes: every member but the form not given.
   *
   * A definition-first entry serializes its definition, never its node; a
   * node-first one its node and the mark, never the derived definition.
   * So the serialized entry is the same whether or not the other form was
   * read, and a build cache row does not depend on what a consumer asked
   * before it was written. A definition-first entry serializes the same
   * members in the same order as before node-first entries existed. A
   * private member serializes under the class's name: a row written
   * while the definition was a public member does not match the class,
   * and the cache is rebuilt, as after any change to a serialized class.
   * A refined entry serializes the children its definition was built
   * from as well ($frames), last; a sealed one, which holds none, the
   * same members as before. Without them a refined entry would wake as a
   * sealed one, and its node would be composed of the sealed children.
   *
   * @return string[]
   *   The constructor's members, in their order, the given form second,
   *   and the refined children last where there are any.
   */
  public function __sleep(): array {
    $forms = ['definition' => TRUE, 'node' => TRUE, 'nodeFirst' => TRUE, 'frames' => TRUE];
    $members = array_keys(array_diff_key(get_object_vars($this), $forms));
    array_splice($members, 1, 0, $this->nodeFirst ? ['node', 'nodeFirst'] : ['definition']);
    if ($this->frames !== NULL) {
      $members[] = 'frames';
    }
    return $members;
  }

  /**
   * Gets the sibling keys this key's static refiners watch.
   *
   * What the served contract may enumerate the key over: a runtime
   * refiner's answer is never computed ahead of time, so a key only it
   * watches is no reason to list anything.
   *
   * @return string[]
   *   The keys, in the order of $dependencies; empty for a key no static
   *   refiner narrows against a sibling.
   */
  public function staticDependencies(): array {
    return $this->runtimeOnly === [] ? $this->dependencies : array_values(array_diff($this->dependencies, $this->runtimeOnly));
  }

  /**
   * Gets the sibling keys this key's coded refiners watch.
   *
   * What the served contract enumerates the key over: a declared rule is
   * stated as written and a runtime refiner never ahead of time.
   *
   * @return string[]
   *   The keys, in the order of $dependencies.
   */
  public function codedDependencies(): array {
    return $this->watchedBy === [] ? $this->staticDependencies() : $this->watching(SurfaceInterface::CODED);
  }

  /**
   * Gets the sibling keys a declared rule of this key reads.
   *
   * @return string[]
   *   The keys, a mounted one by its dotted path, in the order of
   *   $dependencies.
   */
  public function declaredDependencies(): array {
    return $this->watching(SurfaceInterface::DECLARED);
  }

  /**
   * Gets the sibling keys a declared rule of this key reads for presence.
   *
   * A key only declared rules watch, and each of their tests of it asks
   * only whether it holds a value (`present()` or `absent()`): a refusal
   * says such a key is set, never what it holds. A key a coded or a
   * runtime refiner watches as well is not one, since what that refiner
   * read cannot be told.
   *
   * Decision: see docs/decisions.md#a-refusal-names-what-it-depends-on.
   *
   * @return string[]
   *   The keys, a mounted one by its dotted path, in no particular order.
   */
  public function presenceDependencies(): array {
    $presence = [];
    foreach ($this->refiners as $chain) {
      foreach ($chain as $link) {
        if (!$link instanceof DeclaredRefiner) {
          continue;
        }
        foreach ($link->rules[$this->name] ?? [] as $rule) {
          foreach ($rule->condition()?->tests() ?? [] as $test) {
            $at = implode('.', $link->pathOf((string) $test->key));
            $presence[$at] = ($presence[$at] ?? TRUE) && $test->isPresence();
          }
        }
      }
    }
    return array_map('strval', array_keys(array_filter(
      $presence,
      fn (bool $only, int|string $key): bool => $only && ($this->watchedBy[(string) $key] ?? 0) === SurfaceInterface::DECLARED,
      ARRAY_FILTER_USE_BOTH,
    )));
  }

  /**
   * Gets what the key's gate waits for, for the kinds of link asked for.
   *
   * A coded refiner, or a runtime one, runs once every key it watches
   * holds a value; a declared rule runs whatever its keys hold, so the
   * keys only a rule reads hold nothing back.
   *
   * Decision: see docs/decisions.md#a-rule-declared-as-data.
   *
   * @param int $kinds
   *   The kinds of link that run, a mask of SurfaceInterface::CODED,
   *   DECLARED and RUNTIME.
   *
   * @return string[]
   *   The keys, in the order of $dependencies.
   */
  public function gate(int $kinds): array {
    if ($this->watchedBy === []) {
      return $kinds & SurfaceInterface::RUNTIME ? $this->dependencies : $this->staticDependencies();
    }
    return $this->watching($kinds & (SurfaceInterface::CODED | SurfaceInterface::RUNTIME));
  }

  /**
   * Lists the dependencies a kind of link watches.
   *
   * @param int $kinds
   *   A mask of kinds.
   *
   * @return string[]
   *   The keys watched by any of them, in the order of $dependencies.
   */
  protected function watching(int $kinds): array {
    return array_values(array_filter(
      $this->dependencies,
      fn (string $key): bool => (($this->watchedBy[$key] ?? 0) & $kinds) !== 0,
    ));
  }

  /**
   * Tells which kind of link a refiner is.
   *
   * @param \Drupal\surface\SurfaceRefinerInterface $link
   *   The link.
   *
   * @return int
   *   SurfaceInterface::RUNTIME, DECLARED or CODED.
   */
  public static function kindOf(SurfaceRefinerInterface $link): int {
    return match (TRUE) {
      $link instanceof RuntimeRefiner => SurfaceInterface::RUNTIME,
      $link instanceof DeclaredRefiner => SurfaceInterface::DECLARED,
      default => SurfaceInterface::CODED,
    };
  }

  /**
   * Returns whether a declared rule narrows this key.
   *
   * @return bool
   *   TRUE when a link of any of its chains is a declared rule's.
   */
  public function isDeclared(): bool {
    foreach ($this->refiners as $chain) {
      foreach ($chain as $link) {
        if ($link instanceof DeclaredRefiner) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Returns whether a runtime refiner narrows this key.
   *
   * Decision: see docs/decisions.md#static-by-default-runtime-by-declaration.
   *
   * @return bool
   *   TRUE when a link of any of its chains is a runtime refiner's.
   */
  public function isRefinedAtRuntime(): bool {
    foreach ($this->refiners as $chain) {
      foreach ($chain as $link) {
        if ($link instanceof RuntimeRefiner) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Lists where a refiner of this key may answer that it does not apply.
   *
   * Read from the refiners' declarations, not from running them, so it
   * holds for any values: what is stated ahead of time (the full schema,
   * the tool's listing) leaves each of them out of `required`.
   *
   * Decision: see docs/decisions.md#a-key-that-does-not-apply.
   *
   * @param bool|int|null $runtime
   *   TRUE for its runtime refiners only, FALSE for its static ones only
   *   (coded and declared), NULL for every one; or the kinds of link, a
   *   mask of SurfaceInterface::CODED, DECLARED and RUNTIME.
   *
   * @return string[]
   *   '' for the key itself, and the dotted path below it of each mounted
   *   property that may be ruled out; empty when nothing may be.
   */
  public function mayNotApply(bool|int|null $runtime = NULL): array {
    $kinds = match ($runtime) {
      NULL => SurfaceInterface::EVERY,
      TRUE => SurfaceInterface::RUNTIME,
      FALSE => SurfaceInterface::STATIC,
      default => $runtime,
    };
    $paths = [];
    foreach ($this->refiners as $chain) {
      foreach ($chain as $link) {
        if ((static::kindOf($link) & $kinds) === 0) {
          continue;
        }
        if ($link instanceof ApplicabilityInterface) {
          array_push($paths, ...$link->mayNotApply($this->name));
        }
      }
    }
    return array_values(array_unique($paths));
  }

  /**
   * Gets the child answering for this key's value, if any.
   *
   * An attached child always, reading its ports from the given values;
   * the chosen variant only once the deciding key, read from the given
   * values, names a variant. Everything that hands a nested value to the data
   * surface describing it (accept, validate, refine, the form) asks this
   * one question.
   *
   * @param array $values
   *   The values of the data surface this key belongs to, which is where a
   *   variants' deciding key and an attached child's wired ports are read.
   *
   * @return \Drupal\surface\SurfaceInterface|null
   *   The child, or NULL for a plain key and for variants nothing has
   *   chosen yet.
   */
  public function childFor(array $values): ?SurfaceInterface {
    if ($this->attachment !== NULL) {
      return $this->attachment->bound($values);
    }
    if ($this->variants === NULL) {
      return NULL;
    }
    $chosen = $this->variants->chosen($values[$this->variants->by] ?? NULL);
    return $chosen === NULL ? NULL : $this->variants->variant($chosen)->child;
  }

  /**
   * Returns the same entry, its children's ports read from these values.
   *
   * @param array $values
   *   The values of the data surface this key belongs to, as admitted.
   *
   * @return static|null
   *   The entry, every attached or mounted child that reads ports bound
   *   to them, with its source and the other form as this one holds
   *   them; NULL when none reads any.
   */
  public function readingFrom(array $values): ?static {
    $attachment = $this->attachment !== NULL && $this->attachment->reads !== [] ? $this->attachment->readFrom($values) : NULL;
    $mounted = [];
    foreach ($this->mounted as $path => $child) {
      if ($child->reads !== []) {
        $mounted[$path] = $child->readFrom($values);
      }
    }
    if ($attachment === NULL && $mounted === []) {
      return NULL;
    }
    // The same source: the other form read or derived already is its own.
    return $this->remade($this->definition, $this->node, $this->nodeFirst, $attachment, $mounted, $this->frames);
  }

  /**
   * Returns whether this key holds a child: attached or variants.
   *
   * A collection is not nested in this sense: its value is a list, not a
   * map one child answers for, and the key itself is the parent's to
   * refine and lock. Its items are; see $list.
   *
   * @return bool
   *   TRUE for an attached child or variants.
   */
  public function isNested(): bool {
    return $this->attachment !== NULL || $this->variants !== NULL;
  }

  /**
   * Answers whether this key holds a child frame, so its node is composed.
   *
   * An attached child, variants, a collection or a mount: the key's
   * definition holds the child's keys, which the child's own entries hold
   * the nodes of. schema() composes the key's node of its own facts and
   * those nodes (composed()), so nothing below the key is read twice, and
   * every reader reads the key's facts from its node as any key's.
   *
   * Decision: see
   * docs/decisions.md#a-key-that-holds-a-child-frame-has-a-node-of-its-own.
   *
   * @return bool
   *   TRUE for a key that holds a child frame.
   */
  public function isShell(): bool {
    return $this->isNested() || $this->list !== NULL || $this->mounted !== [];
  }

  /**
   * Gets the child every item of a collection is, if this key is one.
   *
   * @return \Drupal\surface\SurfaceInterface|null
   *   The child, as advertised, or NULL for any other key.
   */
  public function itemChild(): ?SurfaceInterface {
    return $this->list?->item->child;
  }

  /**
   * Returns whether a provider had a hand in this key.
   *
   * Introducing the key, contributing values to it, or registering a
   * refiner for it all count: each of them is a reason the key would
   * mean something different if that provider went away.
   *
   * @param string $contributor
   *   The provider id, or SurfaceInterface::OWNER.
   *
   * @return bool
   *   TRUE when the provider touched this key.
   */
  public function touchedBy(string $contributor): bool {
    return $this->contributor === $contributor
      || isset($this->contributions[$contributor])
      || isset($this->refiners[$contributor]);
  }

}
