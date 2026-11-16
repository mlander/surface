<?php

declare(strict_types=1);

namespace Drupal\surface;

use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
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
 *   value set. A definition a node-first entry hands out is for reading:
 *   written, it is refused at the next ask, and with assertions off the
 *   write changes that derived copy alone, never the node.
 * The seal makes every entry node-first (sealed()): every source still
 * gives a definition, and the seal reads it into the node, or composes
 * the node of a key that holds a child frame, and drops it. An entry the
 * seal keeps for a class no node derives a definition of is
 * definition-first (whole()). A refinement makes:
 * - for a key that holds a child frame refined by its child (an
 *   attachment, chosen variants, a structure at a mount), a node-first
 *   entry whose node is composed of the shell's own node and the refined
 *   child's entries' nodes (withSchema(), DefinitionSchema::withProperties());
 *   it keeps the definition built as before, handed with that node
 *   (withDefinition($definition, $node)), where the node holds a class no
 *   node derives a definition of, where a deciding key is locked, where a
 *   mount's own refiners ran, and where a key a refinement made is refined
 *   again;
 * - for a key whose refiner chain ran, a definition-first entry: the
 *   refiner's answer is its source, read whole on the first ask
 *   (withDefinition()).
 * A copy made for ports (readingFrom()) has its entry's source
 * (sameSource()).
 *
 * Decisions: see
 * docs/decisions.md#an-entry-holds-its-node-the-memo-is-gone,
 * docs/decisions.md#an-entry-holds-the-form-its-source-gave and
 * docs/decisions.md#a-sealed-entry-holds-its-node.
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
   * Whether a refinement made this entry's source.
   *
   * TRUE for an entry a refinement made of another definition
   * (withDefinition()) and for a refined shell it composed
   * (withSchema() handed $refined), and for every copy of such an entry
   * (readingFrom(), the same source handed back). Two readers:
   * - a definition-first entry with it reads its node from its definition
   *   whole (DefinitionSchema::of()) on the first ask: a refiner's answer,
   *   whose properties are its own copies and none of them a child's key,
   *   where a sealed definition-first shell composes its node of its
   *   child's entries' nodes (composed());
   * - a key that holds a child frame with it, refined again, is held to
   *   the narrowing check of the whole shell on the definition built as
   *   before (Surface::refineNested(), refineMounted()): its child is the
   *   sealed one, so its own keys were checked against the sealed keys,
   *   not against what the first refinement made of them.
   * Serialized only when TRUE (__sleep()), so a sealed entry serializes as
   * before and a woken refined surface still refuses.
   */
  private bool $madeByRefinement = FALSE;

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
   * another, it is definition-first, and its node is read from it whole
   * on the first ask ($madeByRefinement), or is the node handed with it.
   *
   * A key that holds a child frame whose refinement built its definition
   * from the refined child (a node of a class no node derives a
   * definition of, a chosen variant the narrowing check is kept for, a
   * mount its own refiners refined too) is handed the node composed of
   * the refined child's entries' nodes with it (Surface::refineNested(),
   * refineMounted()), so nothing below the key is read again.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The narrowed definition.
   * @param \PortableSchema\Builder\Schema|null $node
   *   The node composed for it, or NULL to read it on the first ask.
   *
   * @return static
   *   The new entry.
   */
  public function withDefinition(DataDefinitionInterface $definition, ?Schema $node = NULL): static {
    return $definition === $this->definition
      ? $this->remade($this->definition, $this->node, $this->nodeFirst, madeByRefinement: $this->madeByRefinement)
      : $this->remade($definition, $node, FALSE, madeByRefinement: TRUE);
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
   * @param bool $refined
   *   Whether a refinement composed the node (Surface::refineNested(),
   *   refineMounted()): the new entry is then madeByRefinement().
   *
   * @return static
   *   The new entry.
   */
  public function withSchema(Schema $node, bool $refined = FALSE): static {
    return $node === $this->node
      ? $this->remade($this->definition, $this->node, TRUE, madeByRefinement: $this->madeByRefinement || $refined)
      : $this->remade(NULL, $node, TRUE, madeByRefinement: $refined);
  }

  /**
   * Answers whether a refinement made this entry's source.
   *
   * Decision: see docs/decisions.md#a-refined-shell-is-composed-of-nodes.
   *
   * @return bool
   *   TRUE for an entry a refinement made (withDefinition() handed another
   *   definition, withSchema() handed a node it composed) and its copies;
   *   FALSE for a sealed entry and one a refinement left as it was.
   */
  public function madeByRefinement(): bool {
    return $this->madeByRefinement;
  }

  /**
   * Returns the same entry node-first, as the seal makes it.
   *
   * The node is the source; the definition is dropped and derived from
   * the node on the first ask. A key that holds a child frame composes
   * its node of its own facts around the nodes its child's entries hold
   * (composed()): the seal hands such a key its own facts alone (an
   * attachment's or chosen variants' empty map, a collection's list of an
   * empty map; SurfaceBuilder::seal()), and the child's keys are placed in
   * it by name. By name is exact here, since at the seal nothing is
   * refined: the child's entries are the very keys the key holds. A
   * definition that holds the child's keys already has each taken from
   * the child's entry where it is that entry's definition, as schema()
   * does. Any other key's node is read from its definition.
   *
   * Decision: see docs/decisions.md#a-sealed-entry-holds-its-node.
   *
   * @param string|null $chosen
   *   For a key with variants whose deciding key is locked, the variant
   *   chosen: the one whose keys the key holds. NULL for any other key.
   *
   * @return static
   *   The new entry.
   *
   * @internal
   *   The seal's alone, through DefinitionMap::fromArrays() with
   *   nodeFirst.
   */
  public function sealed(?string $chosen = NULL): static {
    assert(!$this->nodeFirst && $this->definition !== NULL);
    if (!$this->isShell()) {
      return $this->remade(NULL, $this->schema(), TRUE);
    }
    $child = match (TRUE) {
      $this->attachment !== NULL => $this->attachment->child,
      $this->list !== NULL => $this->list->item->child,
      $chosen !== NULL && $this->variants !== NULL => $this->variants->variant($chosen)->child,
      default => NULL,
    };
    return $this->remade(NULL, $this->composed($this->definition, $child), TRUE);
  }

  /**
   * Returns the same entry definition-first, its definition made whole.
   *
   * For an entry the seal keeps definition-first (a key of a class no
   * node derives a definition of): a key that holds a child frame was
   * handed its own facts alone (sealed()), and its definition is made of
   * them and its child's definitions as refinement makes it
   * (Attachment::mapOf(), Collection::listOf(), Variants::definitionFor()).
   *
   * @param string|null $chosen
   *   The variant chosen, as sealed() takes it.
   *
   * @return static
   *   The new entry; this one for a key that holds no child frame or a
   *   mount, whose definition is whole already.
   *
   * @internal
   *   The seal's alone, through DefinitionMap::fromArrays() with
   *   nodeFirst.
   */
  public function whole(?string $chosen = NULL): static {
    $definition = $this->definition;
    $whole = match (TRUE) {
      $this->attachment !== NULL && $definition instanceof MapDataDefinition && $definition->getPropertyDefinitions() === [] => Attachment::mapOf($definition, $this->attachment->child),
      $this->list !== NULL && $definition instanceof ListDataDefinition => Collection::listOf($definition, $this->list->item->child),
      $chosen !== NULL && $this->variants !== NULL && $definition instanceof MapDataDefinition && $definition->getPropertyDefinitions() === [] => Attachment::mapOf($definition, $this->variants->variant($chosen)->child),
      default => NULL,
    };
    return $whole === NULL ? $this : $this->remade($whole, NULL, FALSE);
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
   * @param bool $madeByRefinement
   *   Whether a refinement made the copy's source.
   *
   * @return static
   *   The copy.
   */
  private function remade(?DataDefinitionInterface $definition, ?Schema $node, bool $nodeFirst, ?Attachment $attachment = NULL, array $mounted = [], bool $madeByRefinement = FALSE): static {
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
    $entry->madeByRefinement = $madeByRefinement;
    return $entry;
  }

  /**
   * Answers which form the entry holds as its source.
   *
   * For the build's widget walk, which walks a definition-first entry by
   * its definition and a node-first one by its node; for a place in the
   * values (Location), which walks a definition its source gave beside
   * the node and derives none; and for the watched-value walks
   * (Surface::ruledOutWatchedValues(), refusedWatchedValues(), through
   * watchedPlaces()), which walk a definition its source gave and a node
   * otherwise. Every other reader reads a fact from the key's node.
   *
   * Decision: see
   * docs/decisions.md#nothing-asks-for-a-definition-it-does-not-need and
   * docs/decisions.md#a-fact-is-read-from-the-node-and-the-options-service-takes-a-node.
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
   * Asked for only where an edge needs a definition: a refiner,
   * ConstraintRunner, a caller of the options service that holds no
   * node. A key's facts are read from its node (schema()),
   * a key that holds a child frame's too, and the options service is
   * handed the node.
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
   * same object. A key that holds a child frame (isShell()) the seal kept
   * definition-first composes it instead (composed()): its own facts read
   * once, around the nodes its child's entries hold, so no node below it
   * is read twice; a refinement's definition is read whole ($madeByRefinement).
   * On a node-first entry, the node its source gave; on an entry a
   * refinement handed a node with its definition, that node. It asks no
   * service.
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
    if ($this->node === NULL && $this->isShell() && !$this->madeByRefinement) {
      $this->node = $this->composed($this->definition);
      // With assertions on, the first ask of a shell reads the definition
      // too: the two differ where the node was composed of children that
      // are not the ones the definition holds.
      assert(DefinitionSchema::same($this->node, DefinitionSchema::of($this->definition)), sprintf('The node of the "%s" key was composed of children that are not the ones its definition holds.', $this->name));
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
   * A property is taken from a sealed child's entry where it is that
   * entry's definition, the very object (a definition the seal made whole,
   * whole()). At a mount a structure's keys are taken by name: the
   * builder mounts a deep copy of each of the structure's definitions
   * (SurfaceBuilder::mountStructure()), which reads as the original, and
   * nothing writes either. Any other property is read. At the seal the
   * key holds its own facts alone, and its child's keys are placed in it
   * by name from the child given (sealed()). Only the seal's entries and
   * those it keeps definition-first compose; a refinement composes its
   * shells' nodes itself (Surface::refineNested(), refineMounted()) and
   * hands them on, and reads a refiner's answer whole ($madeByRefinement).
   *
   * Decision: see
   * docs/decisions.md#a-key-that-holds-a-child-frame-has-a-node-of-its-own
   * and docs/decisions.md#a-sealed-entry-holds-its-node.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition.
   * @param \Drupal\surface\SurfaceInterface|null $sealed
   *   At the seal, the child whose keys the key holds (sealed()); NULL
   *   otherwise.
   *
   * @return \PortableSchema\Builder\Schema
   *   Its node.
   */
  private function composed(DataDefinitionInterface $definition, ?SurfaceInterface $sealed = NULL): Schema {
    if ($this->list !== NULL) {
      if (!$definition instanceof ListDataDefinitionInterface) {
        return DefinitionSchema::around($definition);
      }
      return DefinitionSchema::around($definition, items: static::framed($definition->getItemDefinition(), [$this->list->item->child], sealed: $sealed));
    }
    if ($this->mounted !== []) {
      $parts = [];
      if ($definition instanceof ComplexDataDefinitionInterface && DefinitionSchema::isMap($definition)) {
        foreach ($definition->getPropertyDefinitions() as $path => $part) {
          $path = (string) $path;
          $mounted = $this->mounted[$path] ?? NULL;
          if ($mounted !== NULL) {
            $parts[$path] = static::framed($part, [], $mounted->child);
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
    return static::framed($definition, $children, sealed: $sealed);
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
   * @param \Drupal\surface\SurfaceInterface|null $sealed
   *   At the seal, the child whose keys the map holds: each of its
   *   entries the map does not hold as a property is placed after the
   *   map's own properties, by name, its entry's node
   *   (DefinitionSchema::around()).
   *
   * @return \PortableSchema\Builder\Schema
   *   The map's node.
   */
  private static function framed(DataDefinitionInterface $map, array $children, ?SurfaceInterface $byName = NULL, ?SurfaceInterface $sealed = NULL): Schema {
    $nodes = [];
    // The seal's shell holds no property: it is a map all the same once
    // its child's keys are placed in it.
    if ($map instanceof ComplexDataDefinitionInterface && !$map instanceof ListDataDefinitionInterface && (DefinitionSchema::isMap($map) || $sealed !== NULL)) {
      $held = $map->getPropertyDefinitions();
      foreach ($sealed?->getDefinitions()->entries() ?? [] as $name => $entry) {
        if (!isset($held[$name])) {
          $nodes[(string) $name] = $entry->schema();
        }
      }
      foreach ($held as $name => $property) {
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
   * An entry a refinement made serializes that mark as well
   * ($madeByRefinement), last; any other entry the same members as
   * before. Without it, a definition-first one that holds a child frame
   * would wake composing its node of its sealed child's keys, and a
   * woken refined shell refined again would skip the narrowing check of
   * the whole shell. A refined shell's node-first entry serializes its node,
   * which holds its refined child's entries' nodes: one serialized graph
   * keeps object identity, so they wake as one, read once.
   *
   * @return string[]
   *   The constructor's members, in their order, the given form second,
   *   and the mark last where it is set.
   */
  public function __sleep(): array {
    $forms = ['definition' => TRUE, 'node' => TRUE, 'nodeFirst' => TRUE, 'madeByRefinement' => TRUE];
    $members = array_keys(array_diff_key(get_object_vars($this), $forms));
    array_splice($members, 1, 0, $this->nodeFirst ? ['node', 'nodeFirst'] : ['definition']);
    if ($this->madeByRefinement) {
      $members[] = 'madeByRefinement';
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
    // The same source, so the copy has the same source as this entry
    // (sameSource()): the emitter tells a key a refinement moved from one
    // whose ports it only rebound by that. The other form read or derived
    // already is its own; one not read or derived yet is read or derived
    // by the copy on its own first ask.
    return $this->remade($this->definition, $this->node, $this->nodeFirst, $attachment, $mounted, $this->madeByRefinement);
  }

  /**
   * Answers whether another entry holds this entry's source.
   *
   * The same form, and the very object that form is: the node of a
   * node-first entry, the definition of a definition-first one. A copy of
   * an entry (readingFrom(), withDefinition() handed the definition it
   * holds, withSchema() handed the node it holds) has its source; an
   * entry a refinement made has a source of its own, even where it says
   * what the entry says. What the served contract tells a key a
   * refinement moved by (ContractEmitter::frame(), collection()). Nothing
   * is read or derived to answer.
   *
   * Decision: see docs/decisions.md#a-refined-shell-is-composed-of-nodes.
   *
   * @param \Drupal\surface\Entry $other
   *   The other entry.
   *
   * @return bool
   *   TRUE when the two hold one source.
   */
  public function sameSource(Entry $other): bool {
    return $this->nodeFirst === $other->nodeFirst
      && ($this->nodeFirst ? $this->node === $other->node : $this->definition === $other->definition);
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
