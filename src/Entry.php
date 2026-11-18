<?php

declare(strict_types=1);

namespace Drupal\surface;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Contract\DefinitionSchema;
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
 * at a time: the advertised schema to narrow, the contributions to
 * divide its option space by, and the chains to run over each slice.
 * The data surface's own cacheability is not per key and stays on the
 * data surface.
 *
 * An entry holds its key's schema (schema()). The one exception is a key
 * whose source is a Typed Data definition no schema derives a definition
 * of: a class other than the three DefinitionFromSchema derives, or one
 * holding such a class at any depth. Such an entry keeps that definition
 * (host()), the one field only edges read, and reads its schema from it
 * on the first ask (DefinitionSchema::of()), so a key refined and never
 * described reads nothing, and the read's one refusal, a definition that
 * holds itself, is thrown at that ask, never at build. Nothing writes
 * either after the entry is made: a refiner's answer is a new schema, and
 * a key that holds a child frame is composed of its own schema and its
 * child's entries' schemas (DefinitionSchema::withProperties()). An edge
 * that needs a definition derives it (DefinitionFromSchema::forEntry()).
 *
 * Decisions: see
 * docs/decisions.md#an-entry-holds-its-schema-the-memo-is-gone,
 * docs/decisions.md#a-sealed-entry-holds-its-schema and
 * docs/decisions.md#an-entry-holds-a-schema-alone.
 *
 * @see \Drupal\surface\Entries
 *   The ordered collection of entries a data surface is sealed with.
 */
final class Entry {

  /**
   * The key's built schema.
   *
   * NULL on a host entry until schema() reads it from the host
   * definition. Serialized only on an entry with no host (__sleep()).
   */
  private ?Schema $schema = NULL;

  /**
   * The definition no schema derives, for a key whose source gave one.
   *
   * NULL on every other entry. Read by edges alone (host()).
   */
  private ?DataDefinitionInterface $host = NULL;

  /**
   * What a host entry is constructed with, then drops; nothing reads it.
   */
  private static ?Schema $unread = NULL;

  /**
   * Constructs an Entry.
   *
   * @param string $name
   *   The key.
   * @param \PortableSchema\Builder\Schema $schema
   *   What the key accepts, as a built schema.
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
   *   structure: its schema then holds the child's keys as its properties.
   * @param \Drupal\surface\Variants|null $variants
   *   The variant table, when the key has variants a sibling chooses
   *   between: its schema is then the `any` placeholder until the
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
   *   The child every item is, when the key is a collection: its schema
   *   is then a list whose item is a map of the child's keys. The key
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
    Schema $schema,
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
    $this->schema = $schema;
  }

  /**
   * Makes the entry of a key whose source is a definition no schema derives.
   *
   * The definition is kept (host()) and its schema read from it on the
   * first schema() call. For a definition of a class other than the three
   * DefinitionFromSchema derives, or one holding such a class at any
   * depth; any other key is constructed with its schema.
   *
   * @param string $name
   *   The key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $host
   *   What the key accepts, as the definition its source gave.
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
  public static function ofHost(
    string $name,
    DataDefinitionInterface $host,
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
    $entry = new static($name, self::unread(), $contributor, $locked, $dependencies, $refiners, $contributions, $attachment, $variants, $paths, $runtimeOnly, $list, $watchedBy, $mounted, $held, $runtimeAccess, $lockedBecause);
    $entry->schema = NULL;
    $entry->host = $host;
    return $entry;
  }

  /**
   * Returns the same entry with another schema.
   *
   * The one change refinement makes: everything else about a key (who
   * introduced it, whether it is locked, what it refines against, whose
   * values it carries) is settled at build time and survives narrowing
   * untouched. Handed the schema it holds, the new entry is a copy of this
   * one; handed another, it holds that schema alone, its host dropped.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   *
   * @return static
   *   The new entry.
   */
  public function withSchema(Schema $schema): static {
    return $schema === $this->schema
      ? $this->remade($this->schema, $this->host)
      : $this->remade($schema, NULL);
  }

  /**
   * Returns the same entry with another host definition and its schema.
   *
   * For a refined key whose source is a definition no schema derives
   * (Surface::refinedTo()): what the answers wrote is written on a copy of
   * that definition, handed with the schema the answers gave.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $host
   *   The refined definition.
   * @param \PortableSchema\Builder\Schema $schema
   *   Its schema.
   *
   * @return static
   *   The new entry.
   *
   * @internal
   *   An edge's: the refinement of a host key alone.
   */
  public function withHost(DataDefinitionInterface $host, Schema $schema): static {
    return $this->remade($schema, $host);
  }

  /**
   * Gets the definition this key's source gave, where no schema derives one.
   *
   * Read by edges alone: one that hands Typed Data a definition reads it
   * here before deriving one from the schema
   * (DefinitionFromSchema::forEntry()).
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The definition; NULL for every key whose schema is its source.
   */
  public function host(): ?DataDefinitionInterface {
    return $this->host;
  }

  /**
   * Gets the key's built schema.
   *
   * On a host entry, read from the host definition on the first call
   * (DefinitionSchema::of()) and kept: every later call returns the same
   * object, unless the refinement handed the schema with it. It asks no
   * service.
   *
   * @return \PortableSchema\Builder\Schema
   *   The built schema.
   *
   * @throws \LogicException
   *   When the host definition holds itself, which no schema can be read
   *   from.
   */
  public function schema(): Schema {
    if ($this->schema === NULL) {
      assert($this->host !== NULL);
      $this->schema = DefinitionSchema::of($this->host);
    }
    return $this->schema;
  }

  /**
   * Makes a copy of this entry with the forms given.
   *
   * @param \PortableSchema\Builder\Schema|null $schema
   *   The schema, or NULL for a host entry not read yet.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $host
   *   The host definition, or NULL.
   * @param \Drupal\surface\Attachment|null $attachment
   *   The attachment, NULL for this entry's.
   * @param array<string, \Drupal\surface\Attachment> $mounted
   *   The mounted structures that replace this entry's, by path.
   *
   * @return static
   *   The copy.
   */
  private function remade(?Schema $schema, ?DataDefinitionInterface $host, ?Attachment $attachment = NULL, array $mounted = []): static {
    $entry = new static(
      $this->name,
      $schema ?? self::unread(),
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
    $entry->schema = $schema;
    $entry->host = $host;
    return $entry;
  }

  /**
   * Gets the schema a host entry is constructed with, then drops.
   *
   * @return \PortableSchema\Builder\Schema
   *   One schema, made once; nothing reads it.
   */
  private static function unread(): Schema {
    return self::$unread ??= Schema::any();
  }

  /**
   * Names what an entry serializes: every member but the form not given.
   *
   * A host entry serializes its host definition, never the schema read
   * from it; any other entry its schema. So a serialized entry is the same
   * whether or not the schema was read, and a build cache row does not
   * depend on what a consumer asked before it was written; a woken host
   * entry reads its schema again. A refined shell's entry
   * serializes its schema, which holds its refined child's entries'
   * schemas: one serialized graph keeps object identity, so they wake as
   * one, read once.
   *
   * @return string[]
   *   The constructor's members, in their order, the given form second.
   */
  public function __sleep(): array {
    $members = array_keys(array_diff_key(get_object_vars($this), ['schema' => TRUE, 'host' => TRUE]));
    array_splice($members, 1, 0, $this->host === NULL ? ['schema'] : ['host']);
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
   *   to them, with the schema and the host definition this one holds;
   *   NULL when none reads any.
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
    // whose ports it only rebound by that. A host entry's schema is read
    // first, so the two share it.
    return $this->remade($this->schema(), $this->host, $attachment, $mounted);
  }

  /**
   * Answers whether another entry holds this entry's source.
   *
   * The very object its source is: the schema, or the host definition of a
   * host entry. A copy of an entry (readingFrom(), withSchema() handed the
   * schema it holds) has its source; an entry a refinement made has a
   * source of its own, even where it says what the entry says. What the
   * served contract tells a key a refinement moved by
   * (ContractEmitter::frame(), collection()). Nothing is read or derived
   * to answer.
   *
   * Decision: see docs/decisions.md#a-refined-shell-is-composed-of-schemas.
   *
   * @param \Drupal\surface\Entry $other
   *   The other entry.
   *
   * @return bool
   *   TRUE when the two hold one source.
   */
  public function sameSource(Entry $other): bool {
    return $this->host === NULL
      ? $other->host === NULL && $this->schema === $other->schema
      : $this->host === $other->host;
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
   * Answers whether this key holds a child frame, so its schema is composed.
   *
   * An attached child, variants, a collection or a mount: the key holds
   * the child's keys, whose schemas the child's own entries hold. The
   * seal and a refinement compose the key's schema of its own facts and
   * those schemas, so nothing below the key is read twice, and every
   * reader reads the key's facts from its schema as any key's.
   *
   * Decision: see
   * docs/decisions.md#a-key-that-holds-a-child-frame-has-a-schema-of-its-own.
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
