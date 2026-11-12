<?php

declare(strict_types=1);

namespace Drupal\surface;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Refinement\ChoiceSet;
use Drupal\surface\Refinement\Narrowing;
use Drupal\surface\Refinement\WatchedValueCheckInterface;
use Drupal\surface\Storage\StorageLayoutInterface;

/**
 * The mutable stage a data surface passes through before it is sealed.
 *
 * The engine under the build step. Resources writes an owner's shape, its
 * alters' additions, the context and the bound #[RefinesInput] methods
 * into one of these, then seals it; nothing an author writes holds one.
 * BlueprintInterface and BlueprintAdditionsInterface are the author's view of
 * it, and each of their verbs lands on a method here.
 *
 * Internal to the build step: Resources fills one per resource or
 * structure it builds, through the blueprint adapters an author's
 * defineInputs() and an alter's alterInputs() are handed, and seals it.
 * Nothing else constructs one outside the engine's own tests.
 *
 * Every addition is a contribution recorded under the name of whoever
 * made it, which is what lets refinement be shared: a contributor's
 * refiners narrow the values that contributor added and nothing else,
 * and the refined data surface is the union of what each contribution
 * narrowed to. The owner is the first contributor.
 *
 * A builder is single use. seal() is idempotent, and every mutator
 * throws once it has run.
 *
 * The builder is a value object made with `new SurfaceBuilder()`, so
 * there is no constructor to inject the translation service through. The
 * titles of the third-party containers below are built with the global
 * t(), and translate at render time exactly as an injected `$this->t()`
 * would.
 *
 * @see \Drupal\surface\ResourceBuild\Resources
 * @see docs/refinement.md
 *
 * @internal
 */
final class SurfaceBuilder {

  /**
   * Third-party definitions keyed by provider, then key.
   *
   * @var array<string, array<string, \Drupal\Core\TypedData\DataDefinitionInterface>>
   */
  protected array $thirdParty = [];

  /**
   * Refiner chains keyed by target definition name, then by contributor.
   *
   * @var array<string, array<string, \Drupal\surface\SurfaceRefinerInterface[]>>
   */
  protected array $refiners = [];

  /**
   * The edges each target was given, by who declared them.
   *
   * Keyed by target, then dependency, mapped to TRUE when a static
   * refiner watches it and FALSE when only a runtime one does so far.
   * Only addRefinement() writes it; an edge it never saw, the variants'
   * on their deciding key or one handed to the constructor, is static.
   *
   * @var array<string, array<string, bool>>
   */
  protected array $declaredBy = [];

  /**
   * Which kinds of link watch each dependency, by target.
   *
   * A mask of SurfaceInterface::CODED, DECLARED and RUNTIME per
   * dependency: what a key's gate waits for is what a coded or runtime
   * link watches, never what only a declared rule reads.
   *
   * @var array<string, array<string, int>>
   */
  protected array $watchedBy = [];

  /**
   * Values contributed to a key's choices, keyed by key, then provider.
   *
   * @var array<string, array<string, array>>
   */
  protected array $contributions = [];

  /**
   * The map each provider's mounted keys are sealed into.
   *
   * Keyed by provider; made the first time it is asked for, so an alter
   * can label its own before the seal.
   *
   * @var array<string, \Drupal\Core\TypedData\MapDataDefinition>
   */
  protected array $thirdPartyMounts = [];

  /**
   * How each provider's mounted settings are written down, by provider.
   *
   * @var array<string, \Drupal\surface\Storage\StorageLayoutInterface>
   */
  protected array $thirdPartyLayouts = [];

  /**
   * Children fixed at a key, keyed by definition key.
   *
   * @var array<string, \Drupal\surface\Attachment>
   */
  protected array $attachments = [];

  /**
   * Variants, keyed by definition key: the deciding key and the variants.
   *
   * @var array<string, array{by: string, variants: array<string, \Drupal\surface\Attachment>}>
   */
  protected array $variants = [];

  /**
   * Collections, keyed by definition key: the child every item is.
   *
   * @var array<string, \Drupal\surface\Collection>
   */
  protected array $lists = [];

  /**
   * Locked definition keys.
   *
   * @var string[]
   */
  protected array $locked = [];

  /**
   * Why a locked key is locked, when an access answer said why.
   *
   * @var array<string, string|\Stringable>
   */
  protected array $lockReasons = [];

  /**
   * The ports the data surface reads from its container, by name.
   *
   * @var array<string, \Drupal\surface\Port>
   */
  protected array $ports = [];

  /**
   * The structures alters attached at their mounts, by provider.
   *
   * @var array<string, \Drupal\surface\Attachment>
   */
  protected array $mounted = [];

  /**
   * The locked keys that hold what is stored, not a declared value.
   *
   * @var string[]
   */
  protected array $held = [];

  /**
   * Why every key is held to what is stored, or NULL when they are not.
   */
  protected string|\Stringable|null $lockedWhole = NULL;

  /**
   * Whether every key is marked as decided once the subject is known.
   */
  protected bool $runtimeAccessWhole = FALSE;

  /**
   * Whether it was built for an operation that changes nothing.
   */
  protected bool $changesNothing = FALSE;

  /**
   * Which version of its stored values it describes.
   */
  protected int $version = 1;

  /**
   * The keys whose access is decided once the subject is known.
   *
   * @var string[]
   */
  protected array $runtimeAccess = [];

  /**
   * The structures mixed in, in order.
   *
   * @var \Drupal\surface\Mixin[]
   */
  protected array $mixins = [];

  /**
   * What the frame as a whole is held to beyond its keys, in order.
   *
   * @var array<int, array>
   */
  protected array $frameConstraints = [];

  /**
   * The wirings refinement edges came from, by target, then dependency.
   *
   * @var array<string, array<string, string>>
   */
  protected array $wirings = [];

  /**
   * What the data surface being built depends on.
   */
  protected CacheableMetadata $cacheability;

  /**
   * What a watched value passes before a refiner is handed it, if set.
   */
  protected ?WatchedValueCheckInterface $watchedValueCheck = NULL;

  /**
   * The sealed data surface, once seal() has produced it.
   *
   * Holding it is what makes seal() idempotent and every mutator's
   * refusal unambiguous: there is a data surface out in the world describing
   * what this builder held, and changing the builder now would mean two
   * different answers to the same question.
   */
  protected ?SurfaceInterface $sealed = NULL;

  /**
   * Constructs a SurfaceBuilder.
   *
   * @param array<string, \Drupal\Core\TypedData\DataDefinitionInterface> $definitions
   *   The owner's own definitions, keyed by definition key.
   * @param array<string, string[]> $refinements
   *   Refinement dependencies: target => dependency names.
   * @param array<string, \Drupal\Core\TypedData\DataDefinitionInterface> $outputs
   *   The owner's own output definitions, keyed by output key.
   */
  public function __construct(
    protected array $definitions = [],
    protected array $refinements = [],
    protected array $outputs = [],
  ) {
    $this->cacheability = new CacheableMetadata();
  }

  /**
   * Gets a definition, so alters can inspect or modify it.
   *
   * @param string $name
   *   The definition key.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The definition, or NULL when the builder holds no such key.
   */
  public function getDefinition(string $name): ?DataDefinitionInterface {
    return $this->definitions[$name] ?? NULL;
  }

  /**
   * Gets the name of every definition the builder holds so far.
   *
   * @return string[]
   *   The definition keys, in the order they were added.
   */
  public function getDefinitionNames(): array {
    return array_map('strval', array_keys($this->definitions));
  }

  /**
   * Adds or replaces a definition.
   *
   * The owner's verb: an alter adding keys mounts them under its
   * module with setThirdPartyDefinition() instead.
   *
   * @param string $name
   *   The definition key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition describing that key.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function setDefinition(string $name, DataDefinitionInterface $definition): static {
    $this->assertMutable();
    $this->definitions[$name] = $definition;
    return $this;
  }

  /**
   * Sets the default value for a key.
   *
   * Sugar over DefinitionMetadata: the default is written onto the named
   * definition, which is where it belongs and where core's own methods
   * will keep it once they land (see PLAN.md).
   *
   * @param string $name
   *   The definition key.
   * @param mixed $value
   *   The default value; NULL is a declared default, distinct from
   *   declaring none.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   When the key is unknown.
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function setDefault(string $name, mixed $value): static {
    $this->assertMutable();
    DefinitionMetadata::setDefaultValue($this->named($name), $value);
    return $this;
  }

  /**
   * Locks a key: its value is fixed to the declared default.
   *
   * Locking is build-time context (an update operation locking an
   * identifier, say): the degenerate refinement, narrowing the value
   * space to exactly one value. Locked keys stay advertised: consumers
   * see the key and its value, rendered disabled in generated forms,
   * and extraction ignores submitted input for them.
   *
   * @param string $name
   *   The definition key.
   * @param string|\Stringable|null $reason
   *   Why, when an access answer locked the key and said why: what a
   *   form prints beside the fixed element. NULL for a lock an operation
   *   makes, which a form says in words of its own.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed, or the name is an output: an
   *   output cannot be locked, because locking narrows what a caller
   *   may send and nothing sends an output.
   */
  public function lock(string $name, string|\Stringable|null $reason = NULL): static {
    $this->assertMutable();
    if (!isset($this->definitions[$name]) && isset($this->outputs[$name])) {
      throw new \LogicException(sprintf(
        'The "%s" output cannot be locked: locking narrows what a caller may send, and nothing sends an output. An output that is not always emitted says so by being optional and absent.',
        $name,
      ));
    }
    if (!in_array($name, $this->locked, TRUE)) {
      $this->locked[] = $name;
    }
    if ($reason !== NULL) {
      $this->lockReasons[$name] = $reason;
    }
    return $this;
  }

  /**
   * Leaves a child's key out of what the data surface advertises.
   *
   * What a child's access answers when the account may not see it: the
   * key is not in the sealed data surface at all, so nothing generated
   * from it draws, advertises or accepts it, and a value posted for it is
   * an unknown key. The one removal the build step makes, and only of a
   * key that holds a child or a collection of them, which no sibling may
   * watch.
   *
   * @param string $name
   *   The key reserved for the child.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed, or the key is not an attached
   *   child or a collection reserved by the blueprint.
   */
  public function omit(string $name): static {
    $this->assertMutable();
    $shell = $this->definitions[$name] ?? NULL;
    if (!static::isEmptyShell($shell)) {
      throw new \LogicException(sprintf('The "%s" key cannot be omitted: only a key reserved for an attached structure or a collection can be, and only before its child is attached.', $name));
    }
    // A collection's refiners are the parent's, bound before its child
    // was asked, and go with the key.
    unset($this->definitions[$name], $this->attachments[$name], $this->variants[$name], $this->lists[$name], $this->refinements[$name], $this->refiners[$name], $this->declaredBy[$name], $this->watchedBy[$name], $this->contributions[$name], $this->lockReasons[$name]);
    $this->locked = array_values(array_diff($this->locked, [$name]));
    return $this;
  }

  /**
   * Locks a key to what is stored: its access locked it, not a context.
   *
   * The lock lock() makes, except that the key holds whatever storage
   * holds rather than its declared default, which nothing ahead of time
   * can state: the form shows the stored value, and neither document
   * fixes it to a `const` it cannot know. A mixed-in structure's members
   * are held this way when its access answers locked.
   *
   * @param string $name
   *   The definition key.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function hold(string $name): static {
    $this->lock($name);
    if (!in_array($name, $this->held, TRUE)) {
      $this->held[] = $name;
    }
    return $this;
  }

  /**
   * Holds every key to what is stored: an access answer locked the whole.
   *
   * What a resource's own access class answers locked at the top, asked
   * on its own, or a variant's access class answers locked: every
   * key is shown and fixed, held as hold() holds one, and the sealed data
   * surface says why (SurfaceInterface::lockedWhole()). Applied at the
   * seal, after the variants, so a variant is chosen by what its deciding
   * key holds rather than preselected by its default.
   *
   * Decision: see docs/decisions.md#locked-at-the-top.
   *
   * @param string|\Stringable $reason
   *   Why, in a sentence a caller is shown.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function lockWhole(string|\Stringable $reason): static {
    $this->assertMutable();
    $this->lockedWhole = $reason;
    return $this;
  }

  /**
   * Marks a data surface built for an operation declared read only.
   *
   * Its pipeline accepts, validates and prepares, and writes nothing:
   * submit() stops where a dry run does, and commit() refuses what was
   * prepared for it (SurfaceInterface::changesNothing()).
   *
   * Decision: see docs/decisions.md#an-operation-states-how-it-behaves.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function markChangesNothing(): static {
    $this->assertMutable();
    $this->changesNothing = TRUE;
    return $this;
  }

  /**
   * Records which version of its stored values the data surface describes.
   *
   * Decision: see docs/decisions.md#a-resource-evolves-in-the-open.
   *
   * @param int $version
   *   The version its #[Resource] or #[Structure] declares, from 1.
   *
   * @return $this
   */
  public function setVersion(int $version): static {
    $this->assertMutable();
    $this->version = $version;
    return $this;
  }

  /**
   * Takes a mixed-in structure's member back out, before anything binds it.
   *
   * What a mixed-in structure's access answers when the account may not
   * see it: its members leave the owner's keys, as an omitted child's key
   * does, and storage keeps what it holds under them. Nothing of the
   * owner's may name a member of a structure with an access class, so
   * nothing is left reading it.
   *
   * @param string $name
   *   The member.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed, or the key holds a child.
   */
  public function withdraw(string $name): static {
    $this->assertMutable();
    if (isset($this->attachments[$name]) || isset($this->variants[$name]) || isset($this->lists[$name])) {
      throw new \LogicException(sprintf('The "%s" key holds a child: omit() leaves a child out.', $name));
    }
    unset($this->definitions[$name], $this->refinements[$name], $this->refiners[$name], $this->declaredBy[$name], $this->watchedBy[$name], $this->contributions[$name], $this->lockReasons[$name]);
    $this->locked = array_values(array_diff($this->locked, [$name]));
    $this->held = array_values(array_diff($this->held, [$name]));
    $this->runtimeAccess = array_values(array_diff($this->runtimeAccess, [$name]));
    return $this;
  }

  /**
   * Marks a key whose access is decided once the subject is known.
   *
   * In a build for the account alone (ResourcesInterface::buildStatic()),
   * an access answer that read more than the account (its cacheability
   * says so) is asked and set aside: the key stays as the static answers
   * leave it, and is marked, so the full schema says it may be locked or
   * left out once the subject is known.
   *
   * @param string $name
   *   The key: a child's, one with variants, a collection's, or a
   *   mixed-in structure's member.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function markRuntimeAccess(string $name): static {
    $this->assertMutable();
    if (!in_array($name, $this->runtimeAccess, TRUE)) {
      $this->runtimeAccess[] = $name;
    }
    return $this;
  }

  /**
   * Marks every key of the data surface as decided once the subject is known.
   *
   * For a resource asked for on its own whose access, built for the
   * account alone, gave a runtime answer that was set aside: any of its
   * keys may then be found locked, the whole of it, so each is marked, as
   * markRuntimeAccess() marks one. Applied at seal, over every key there
   * is then.
   *
   * Decision: see docs/decisions.md#locked-at-the-top.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function markRuntimeAccessWhole(): static {
    $this->assertMutable();
    $this->runtimeAccessWhole = TRUE;
    return $this;
  }

  /**
   * Records a structure mixed in: which of the keys it brought.
   *
   * Its members are declared as the owner's keys already, through the
   * blueprint; this is what the contract needs to state the structure
   * once, as a def the owner composes with its own keys.
   *
   * @param \Drupal\surface\Mixin $mixin
   *   The structure, built in its own frame, and the keys it brought.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function addMixin(Mixin $mixin): static {
    $this->assertMutable();
    $this->mixins[] = $mixin;
    $this->cacheability->addCacheableDependency($mixin->structure->child);
    return $this;
  }

  /**
   * Holds the frame as a whole to a JSON Schema its keys cannot say.
   *
   * What a schema document read into the frame says of the object rather
   * than of one key (a conditional outside the vocabulary of declared
   * rules, `dependentSchemas`, `not` over the object), checked by the
   * pipeline against the frame's accepted values, and named under the
   * frame's `portable-check` in both documents.
   *
   * Decision: see docs/decisions.md#a-schema-can-be-read-back-into-a-structure.
   *
   * @param array $schema
   *   The JSON Schema 2020-12, as decoded with objects as arrays.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function constrainFrame(array $schema): static {
    $this->assertMutable();
    $this->frameConstraints[] = $schema;
    return $this;
  }

  /**
   * Extends an existing definition's declared choices with more values.
   *
   * The contribution API for widening at build time (decision D2): the
   * added values become part of the advertised contract, and the module
   * adding them owns their behavior — in the host's consumption path,
   * and in refinement, where a refiner registered under the same name is
   * handed exactly these values and no others.
   *
   * Widening here is legal precisely because nothing has been advertised
   * yet: every consumer reads the extended data surface, never a variant of
   * it.
   *
   * Both choice constraints are understood, in every spelling, and the
   * list is written back in Choice's own form: `choices` as the list of
   * allowed values, `labels` as what each one is called. A plain Choice
   * has nowhere to keep labels, so contributing them to one drops them.
   * The added values go after the existing ones, and an existing value
   * keeps the label it already had.
   *
   * One value has one owner: contributing a value the key already
   * allows is refused, naming whoever already has it. A key that
   * declares no list of allowed values cannot be extended at all,
   * because giving it one would narrow what its owner left open.
   *
   * @param string $name
   *   The definition key.
   * @param array $choices
   *   The values to add: a list of bare values, or values mapped to
   *   their labels.
   * @param string $contributor
   *   The provider adding them, which is the name its refiners are
   *   registered under.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   When the key is unknown, or declares no list of allowed values.
   * @throws \LogicException
   *   When a value is already allowed, or the builder is already
   *   sealed.
   */
  public function extendChoices(string $name, array $choices, string $contributor): static {
    $this->assertMutable();
    $definition = $this->named($name);
    $set = ChoiceSet::of($definition);
    if ($set === NULL) {
      throw new \InvalidArgumentException(sprintf(
        'The "%s" definition declares no list of allowed values, so there is nothing to extend: giving it one would narrow what its owner deliberately left open.',
        $name,
      ));
    }
    [$values, $labels] = static::normalizeChoices($choices);
    foreach ($values as $value) {
      // Loosely, so that '1' and 1 are one value rather than two: a
      // choice list is compared the way the validator compares it.
      if (in_array($value, $set->values)) {
        throw new \LogicException(sprintf(
          'The "%s" value of "%s" was already contributed by %s, so %s cannot contribute it too: one value has one owner, and that owner answers for how it behaves.',
          static::describe($value),
          $name,
          $this->contributorOf($name, $value),
          $contributor,
        ));
      }
    }
    $this->contributions[$name][$contributor] = array_merge(
      $this->contributions[$name][$contributor] ?? [],
      $values,
    );
    $set->withValues(array_merge($set->values, $values), $labels)->applyTo($definition);
    return $this;
  }

  /**
   * Says how a provider's mounted settings are written down.
   *
   * A contributor may ask a caller for a value in one layout and store it
   * in another: a duration asked for as an amount and a unit and stored
   * as a number of seconds. The owner's storage cannot know that, and the
   * contributor does not build the storage, so the translation travels on
   * the data surface: storage that writes third-party settings applies the
   * provider's layout to that provider's namespace, toStorage() on the
   * way in and fromStorage() on the way out, and to nothing else.
   *
   * The layout sees the provider's settings only, keyed by the keys the
   * provider mounted, and must be pure (the StorageLayoutInterface
   * contract), because the data surface carrying it is serialized with every
   * form that renders it.
   *
   * @param string $provider
   *   The module whose mounted settings the layout translates. It must
   *   mount at least one definition before the data surface is sealed.
   * @param \Drupal\surface\Storage\StorageLayoutInterface $layout
   *   The translation.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   *
   * @see \Drupal\surface\Pipeline\Storage\ConfigEntityStorage
   */
  public function setThirdPartyLayout(string $provider, StorageLayoutInterface $layout): static {
    $this->assertMutable();
    $this->thirdPartyLayouts[$provider] = $layout;
    return $this;
  }

  /**
   * Gives the sealed data surface the check its watched values pass.
   *
   * Refinement hands a refiner a sibling's value only when that value
   * satisfies the sibling's own refined definition; a refused value is
   * passed as if the sibling held nothing. A data surface sealed without a
   * check (the engine's own tests build those) hands every configured
   * value over.
   *
   * @param \Drupal\surface\Refinement\WatchedValueCheckInterface $check
   *   The check, which rides inside the sealed data surface.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   *
   * @see docs/decisions.md#a-refiner-never-sees-an-invalid-sibling
   */
  public function setWatchedValueCheck(WatchedValueCheckInterface $check): static {
    $this->assertMutable();
    $this->watchedValueCheck = $check;
    return $this;
  }

  /**
   * Mounts a third-party definition under the provider's namespace.
   *
   * Mirrors core's third-party settings storage layout: the sealed
   * data surface gains a 'third_party_settings' map with one map per
   * provider, so values land at third_party_settings.<provider>.<key>:
   * advertised, validated, and machine-visible, unlike the form-alter
   * era.
   *
   * A default given here is written onto the mounted definition, so the
   * map's own default assembles itself from its properties.
   *
   * @param string $provider
   *   The module mounting the setting.
   * @param string $key
   *   The setting's key within that provider's namespace.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition describing the setting.
   * @param mixed $default
   *   The default value, written onto the mounted definition.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function setThirdPartyDefinition(string $provider, string $key, DataDefinitionInterface $definition, mixed $default = NULL): static {
    $this->assertMutable();
    $this->thirdParty[$provider][$key] = $definition;
    if ($default !== NULL) {
      DefinitionMetadata::setDefaultValue($definition, $default);
    }
    return $this;
  }

  /**
   * Fixes a child at a key: an attached structure (or resource).
   *
   * The key's value is a map whose properties are the child's keys, and
   * it is the child that accepts, validates and refines it, in its own
   * frame: nothing in the parent refines into the child, and the child
   * never sees the parent's values. The child's cacheability becomes the
   * parent's. The key may be described beforehand with an empty map
   * carrying its label and description; that map is kept as the shell
   * the child is advertised in.
   *
   * @param string $key
   *   The definition key.
   * @param \Drupal\surface\Attachment $attachment
   *   The sealed child, and the class it was built from.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   When the key already holds anything but an empty map.
   * @throws \LogicException
   *   When the key already holds an attached child.
   */
  public function attach(string $key, Attachment $attachment): static {
    $this->assertMutable();
    $this->assertNotNested($key);
    $this->definitions[$key] = $this->shellFor($key);
    $this->attachments[$key] = $attachment;
    $this->cacheability->addCacheableDependency($attachment->child);
    return $this;
  }

  /**
   * Attaches variants: the structures a sibling key chooses between.
   *
   * Advertised as an `any` placeholder marked as variants until the
   * deciding key holds a value, and as exactly the chosen variant's map once it
   * does, or from the start, when the deciding key is locked. A deciding
   * key that declares no list of values gains one over the variant ids,
   * a LabeledChoice when labels are given and a Choice otherwise; one
   * that declares a list keeps it, narrowed to the variants offered, and
   * a variant whose value it leaves out is not offered. It becomes a
   * refinement dependency of the key, so a form rebuilds on it and the
   * discard cascade resets a variant it orphaned.
   *
   * @param string $key
   *   The definition key.
   * @param string $by
   *   The sibling key whose value chooses the variant.
   * @param array<string, \Drupal\surface\Attachment> $variants
   *   One sealed child per value of the deciding key. May be empty:
   *   variants nothing declares offer nothing to choose.
   * @param array<string, string|\Stringable> $labels
   *   What each value is called, by value, for a deciding key that
   *   declares no list; a value with none is called by itself.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   When the deciding key is the key itself, is not declared, holds a
   *   list or a map, or the key already holds anything but an empty map.
   * @throws \LogicException
   *   When the key already holds an attached child.
   */
  public function attachVariants(string $key, string $by, array $variants, array $labels = []): static {
    $this->assertMutable();
    $this->assertNotNested($key);
    if ($by === $key) {
      throw new \InvalidArgumentException(sprintf('The variants at "%s" cannot choose their own variant: the deciding key is a sibling.', $key));
    }
    $kept = $this->offerVariants($by, array_map('strval', array_keys($variants)), $labels, sprintf('The variants at "%s"', $key), sprintf('the variants at "%s"', $key));
    $variants = array_intersect_key($variants, array_flip($kept));
    $this->definitions[$key] = $this->shellFor($key);
    $this->variants[$key] = ['by' => $by, 'variants' => $variants];
    $this->refinements[$key] = array_values(array_unique(array_merge($this->refinements[$key] ?? [], [$by])));
    // A variant held unsettled adds what it read once it is built: the
    // data surface asks its settled children for theirs.
    // Decision: see
    // docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
    foreach ($variants as $variant) {
      $settled = $variant->settledChild();
      if ($settled !== NULL) {
        $this->cacheability->addCacheableDependency($settled);
      }
    }
    return $this;
  }

  /**
   * Offers the values variants are for on their deciding key.
   *
   * The complete set of values the variants can be chosen by is part of
   * what the deciding key advertises, so it says them in its own
   * vocabulary, in the variants' words when they give any; a list the key
   * declares itself narrows them instead, and keeps its own words.
   *
   * Decision: see docs/decisions.md#variants-not-slots.
   *
   * @param string $by
   *   The deciding key.
   * @param string[] $ids
   *   The values a variant is offered for, in order.
   * @param array<string, string|\Stringable> $labels
   *   What each value is offered as, by value.
   * @param string $refused
   *   The variants, in words, at the head of a refusal.
   * @param string $narrowing
   *   The variants, in words, for the narrowing check.
   *
   * @return string[]
   *   The values still offered: all of them, or those a declared list
   *   keeps.
   *
   * @throws \InvalidArgumentException
   *   When the deciding key holds a list or a map.
   */
  protected function offerVariants(string $by, array $ids, array $labels, string $refused, string $narrowing): array {
    $deciding = $this->named($by);
    if ($deciding instanceof ListDataDefinitionInterface || $deciding instanceof ComplexDataDefinitionInterface) {
      throw new \InvalidArgumentException(sprintf('%s are chosen by "%s", which is a %s: a deciding key holds one value.', $refused, $by, $deciding->getDataType()));
    }
    $before = Surface::deepClone($deciding);
    $set = ChoiceSet::of($deciding);
    if ($set === NULL) {
      if ($labels === []) {
        $deciding->addConstraint('Choice', ['choices' => $ids]);
      }
      else {
        $named = [];
        foreach ($ids as $id) {
          $named[$id] = $labels[$id] ?? $id;
        }
        (new ChoiceSet('LabeledChoice', $ids, $named))->applyTo($deciding);
      }
      if (count($ids) === 1) {
        // One value to choose is none: the key is that value, fixed.
        // Decision: see
        // docs/decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter.
        DefinitionMetadata::setOneVariant($deciding);
      }
    }
    else {
      // A list the key declares narrows the variants: one whose value it
      // leaves out is not offered, and a value no variant is offered for
      // here (one withheld from this account) leaves the list.
      $allowed = array_map('strval', $set->values);
      $ids = array_values(array_intersect($ids, $allowed));
      $set->withValues(array_values(array_filter(
        $set->values,
        static fn (mixed $value): bool => in_array((string) $value, $ids, TRUE),
      )))->applyTo($deciding);
    }
    Narrowing::assertNarrows($by, $narrowing, $before, $deciding);
    return $ids;
  }

  /**
   * Declares a collection: a key whose value is a list of child instances.
   *
   * Every item is the list's child, accepted, validated and refined by it
   * in its own frame, under its own key names; nothing in the parent
   * names a key inside an item. The key itself is the parent's: its
   * definition is a list whose item is the child's map, and the list's
   * own constraints (its Count) are the parent's to declare and narrow.
   * The key may be described beforehand with a list whose item is an
   * empty map; that list is kept as the shell the items are advertised
   * in. The child's cacheability becomes the parent's.
   *
   * @param string $key
   *   The definition key.
   * @param \Drupal\surface\Collection $list
   *   The child every item is, sealed once.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   When the key already holds anything but such a list.
   * @throws \LogicException
   *   When the key already holds an attached child or a collection.
   */
  public function attachList(string $key, Collection $list): static {
    $this->assertMutable();
    $this->assertNotNested($key);
    $existing = $this->definitions[$key] ?? NULL;
    if ($existing === NULL) {
      $existing = new ListDataDefinition([], MapDataDefinition::create());
    }
    if (!$existing instanceof ListDataDefinition || !static::isEmptyShell($existing)) {
      throw new \InvalidArgumentException(sprintf(
        'The "%s" key already holds a %s definition. A collection is a list of its child\'s items; describe the key beforehand with a list of empty maps, if at all.',
        $key,
        $existing->getDataType(),
      ));
    }
    $this->definitions[$key] = $existing;
    $this->lists[$key] = $list;
    $this->cacheability->addCacheableDependency($list->item->child);
    return $this;
  }

  /**
   * Gets an output definition, so alters can inspect or modify it.
   *
   * @param string $name
   *   The output key.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The definition, or NULL when the builder holds no such output.
   */
  public function getOutputDefinition(string $name): ?DataDefinitionInterface {
    return $this->outputs[$name] ?? NULL;
  }

  /**
   * Adds or replaces an output definition.
   *
   * What the host's execution emits, declared in the same vocabulary as
   * what it accepts. The owner's verb alone: what a call answers is the
   * operation's, and no alter adds to it (a reaction to a save belongs to
   * ECA), so a consumer reading the owner's output schema keeps reading
   * true.
   *
   * Two differences from an input definition, both enforced:
   * - An output carries no default. A default is what a value starts
   *   from when nobody sent one, and nobody sends an output; a key the
   *   producer has nothing to say about is absent, which is what the
   *   NotEmitted sentinel spells. A definition carrying 'default_value',
   *   at any depth, is refused.
   * - An output cannot be locked. Locking narrows what a caller may
   *   send, and nothing sends an output.
   *
   * @param string $name
   *   The output key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition describing what is emitted under that key.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   When the definition declares a default value.
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function setOutputDefinition(string $name, DataDefinitionInterface $definition): static {
    $this->assertMutable();
    static::assertOutputDeclarable($name, $definition);
    $this->outputs[$name] = $definition;
    return $this;
  }

  /**
   * Gets a definition a provider mounted, by provider and key.
   *
   * @param string $provider
   *   The module that mounted it.
   * @param string $key
   *   The key within that provider's namespace.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The definition, or NULL when the provider mounted no such key.
   */
  public function getThirdPartyDefinition(string $provider, string $key): ?DataDefinitionInterface {
    return $this->thirdParty[$provider][$key] ?? NULL;
  }

  /**
   * Holds one provider's input mount, before it has a key in it.
   *
   * For an alter that attaches a structure at its mount: the mount exists,
   * to be described and placed, before the structure is built.
   *
   * @param string $provider
   *   The module the mount belongs to.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The map its keys are sealed into.
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function reserveThirdPartyMount(string $provider): MapDataDefinition {
    $this->assertMutable();
    $this->thirdParty[$provider] ??= [];
    $mount = $this->getThirdPartyMount($provider);
    assert($mount !== NULL);
    return $mount;
  }

  /**
   * Fixes a structure at one provider's input mount.
   *
   * The structure's keys are the mount's: they are sealed into the map at
   * `third_party_settings.<provider>`, where its values are accepted,
   * stored and described as any mounted key's, and the structure refines
   * them there in its own frame, its ports wired as the attachment says
   * (Entry::$mounted). Nothing else of the parent narrows them.
   *
   * @param string $provider
   *   The module whose mount holds it.
   * @param \Drupal\surface\Attachment $attachment
   *   The sealed structure, with its ports wired.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed, or the provider has mounted
   *   anything already.
   */
  public function mountStructure(string $provider, Attachment $attachment): static {
    $this->assertMutable();
    if (($this->thirdParty[$provider] ?? []) !== [] || isset($this->mounted[$provider])) {
      throw new \LogicException(sprintf('The %s mount holds keys already, so a structure cannot be attached there: a mount that holds a structure holds its keys alone.', $provider));
    }
    $this->reserveThirdPartyMount($provider);
    foreach ($attachment->child->getDefinitions()->entries() as $name => $entry) {
      $this->thirdParty[$provider][(string) $name] = Surface::deepClone($entry->definition);
    }
    $this->mounted[$provider] = $attachment;
    $this->cacheability->addCacheableDependency($attachment->child);
    return $this;
  }

  /**
   * Declares a port the data surface reads from its container.
   *
   * @param \Drupal\surface\Port $port
   *   The port.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed, or the name is a key.
   */
  public function addPort(Port $port): static {
    $this->assertMutable();
    if (isset($this->definitions[$port->name])) {
      throw new \LogicException(sprintf('"%s" is a key of this data surface, so it cannot also be read as a port.', $port->name));
    }
    $this->ports[$port->name] = $port;
    return $this;
  }

  /**
   * Gets the map one provider's mounted keys are sealed into.
   *
   * The map is the provider's own fieldset on a form: titled with the
   * module's human name until someone labels it, which is what an
   * alter's describe('third_party_settings.<module>') does. Its keys are
   * put into it when the data surface is sealed.
   *
   * @param string $provider
   *   The module that mounted the keys.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition|null
   *   The map, or NULL when the provider mounted nothing.
   */
  public function getThirdPartyMount(string $provider): ?MapDataDefinition {
    if (!isset($this->thirdParty[$provider])) {
      return NULL;
    }
    return $this->thirdPartyMounts[$provider] ??= static::providerMap($provider);
  }

  /**
   * Makes the map one provider's mounted keys are sealed into.
   *
   * Titled with the module's human name: on a form it is that module's
   * fieldset, and "settings" after the name says nothing a person needs.
   *
   * @param string $provider
   *   The module that mounted the keys.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The map, with no properties yet.
   */
  protected static function providerMap(string $provider): MapDataDefinition {
    return MapDataDefinition::create()->setLabel(static::providerLabel($provider));
  }

  /**
   * Declares that a target refines against sibling values.
   *
   * @param string $target
   *   The key whose definition is refined, or the dotted path of
   *   a mounted key, `third_party_settings.<module>.<key>`, refined
   *   inside the mount.
   * @param array $dependencies
   *   The sibling keys it is refined against; a mounted key by its
   *   dotted path.
   * @param bool $runtime
   *   TRUE when only a runtime refiner watches them: refinement still
   *   waits for them, and nothing that states the target ahead of time
   *   reads them (Entry::staticDependencies()).
   * @param bool $declared
   *   TRUE when a declared rule reads them: refinement does not wait for
   *   them, and the rule reads them as they stand
   *   (Entry::declaredDependencies()).
   * @param string|null $wiring
   *   When the edges come from a port a container wired, the wiring in
   *   words, which a cycle through them is refused naming.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function addRefinement(string $target, array $dependencies, bool $runtime = FALSE, bool $declared = FALSE, ?string $wiring = NULL): static {
    $this->assertMutable();
    if ($wiring !== NULL) {
      foreach ($dependencies as $dependency) {
        $this->wirings[$target][$dependency] = $wiring;
      }
    }
    $this->refinements[$target] = array_values(array_unique(array_merge($this->refinements[$target] ?? [], $dependencies)));
    $kind = match (TRUE) {
      $runtime => SurfaceInterface::RUNTIME,
      $declared => SurfaceInterface::DECLARED,
      default => SurfaceInterface::CODED,
    };
    foreach ($dependencies as $dependency) {
      $this->declaredBy[$target][$dependency] = !$runtime || ($this->declaredBy[$target][$dependency] ?? FALSE);
      $this->watchedBy[$target][$dependency] = ($this->watchedBy[$target][$dependency] ?? 0) | $kind;
    }
    return $this;
  }

  /**
   * Reads the refinement edges a static refiner declared.
   *
   * @return array<string, string[]>|null
   *   The edges less those only a runtime refiner declared, in the form
   *   of $refinements; NULL when there are none of those, so every edge
   *   is static.
   */
  protected function staticRefinements(): ?array {
    if (!in_array(FALSE, array_merge([], ...array_values($this->declaredBy)), TRUE)) {
      return NULL;
    }
    $static = [];
    foreach ($this->refinements as $target => $dependencies) {
      $static[$target] = array_values(array_filter(
        $dependencies,
        fn (string $dependency): bool => $this->declaredBy[$target][$dependency] ?? TRUE,
      ));
    }
    return $static;
  }

  /**
   * Appends a refiner to one contribution's chain for a target.
   *
   * A chain narrows one contribution's own slice of the storage: the
   * owner's refiners are handed the values the owner declared, and a
   * contributor's are handed the values that contributor added with
   * extendChoices(). Links run in registration order, each receiving the
   * previous link's output, and every link is held to the narrowing
   * contract against what it was handed — so a contributor can neither
   * narrow away somebody else's value nor hand back one of its own that
   * it was not given.
   *
   * Refiners must be serializable (services or named classes, no
   * closures or anonymous classes): data surfaces ride along in cached
   * forms.
   *
   * @param string $target
   *   The key whose definition is refined.
   * @param \Drupal\surface\SurfaceRefinerInterface $refiner
   *   The refiner to append.
   * @param string|null $contributor
   *   The provider whose contribution this refiner speaks for, or NULL
   *   for the owner.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function addRefiner(string $target, SurfaceRefinerInterface $refiner, ?string $contributor = NULL): static {
    $this->assertMutable();
    $this->refiners[$target][$contributor ?? SurfaceInterface::OWNER][] = $refiner;
    return $this;
  }

  /**
   * Declares what the data surface being built depends on.
   *
   * A data surface is computed from live site state, so it can only be
   * reused for as long as that state holds. Whatever is declared here is
   * carried by the sealed data surface, merged with what every refiner
   * that runs declares, and read by anything that renders or stores the
   * data surface. The split between metadata that travels into a shared
   * cache and metadata that only holds for one request is in
   * docs/refinement.md.
   *
   * @param \Drupal\Core\Cache\CacheableDependencyInterface $dependency
   *   The dependency, which may be a CacheableMetadata built by hand.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function addCacheableDependency(CacheableDependencyInterface $dependency): static {
    $this->assertMutable();
    $this->cacheability->addCacheableDependency($dependency);
    return $this;
  }

  /**
   * Seals the builder into an immutable data surface.
   *
   * The only construction path for a data surface. Idempotent: the same
   * data surface comes back however often this is called, so a caller
   * holding a builder and a caller holding its data surface are looking
   * at one object.
   *
   * This is also where the refinement map is checked for cycles. It is
   * asked here and not earlier because the map is only whole once every
   * contributor has added to it, and a key that refines against itself
   * would otherwise produce an answer that depends on the order the keys
   * happened to be read in.
   *
   * What comes out is a DefinitionMap: the builder's own bookkeeping
   * (definitions, refinement edges, locked keys, contributed values and
   * refiner chains) folded into one entry per key, in declaration
   * order, and checked once for the rest of its shape.
   *
   * The outputs become a second map of the same type, holding whatever
   * the owner and its alters declared it emits. Its entries carry no
   * locked flag, no contributed values and no refiners: outputs are
   * never refined.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The advertised data surface.
   *
   * @throws \LogicException
   *   When a key refines, directly or indirectly, against itself.
   */
  public function seal(): SurfaceInterface {
    if ($this->sealed !== NULL) {
      return $this->sealed;
    }
    $this->assertNoRefinementCycle();
    $unmounted = array_diff_key($this->thirdPartyLayouts, $this->thirdParty);
    if ($unmounted !== []) {
      throw new \LogicException(sprintf(
        'A storage layout was given for the third-party settings of %s, which mount nothing on this data surface.',
        implode(', ', array_keys($unmounted)),
      ));
    }
    $definitions = $this->definitions;
    foreach ($this->attachments as $key => $attachment) {
      $definitions[$key] = Attachment::mapOf($this->assertShell((string) $key), $attachment->child);
    }
    $sealed = [];
    foreach ($this->variants as $key => ['by' => $by, 'variants' => $members]) {
      $variants = new Variants($by, $this->assertShell((string) $key), $members);
      $sealed[$key] = $variants;
      // A locked deciding key has its one value already, so the key is
      // that variant from the start: what the data surface advertises is the
      // shape the caller will actually be held to.
      $chosen = in_array($by, $this->locked, TRUE)
        ? $variants->chosen(DefinitionMetadata::defaultOf($this->named($by)))
        : NULL;
      $definitions[$key] = $chosen === NULL ? $variants->placeholder() : $variants->definitionFor($chosen);
    }
    foreach ($this->lists as $key => $list) {
      $definitions[$key] = Collection::listOf($this->assertListShell((string) $key), $list->item->child);
    }
    if ($this->thirdParty !== []) {
      $definitions['third_party_settings'] = $this->mountedMap($this->thirdParty);
    }
    // A message written with t() is handed to Symfony as its source, which
    // core's validator translator translates when a violation is made.
    foreach ($definitions as $definition) {
      ConstraintMessages::sources($definition);
    }
    static::assertGroupsAgree($definitions);
    // Locked whole: every key not fixed already holds what is stored. After
    // the variants, so a variant is chosen by what its deciding key holds,
    // not preselected by its default.
    $locked = $this->locked;
    $held = $this->held;
    if ($this->lockedWhole !== NULL) {
      foreach (array_keys($definitions) as $name) {
        if (!in_array($name, $locked, TRUE)) {
          $locked[] = $held[] = (string) $name;
        }
      }
    }
    $outputs = $this->outputs;
    // Asked again over everything, because outputs also arrive whole
    // through the constructor, and a declaration that cannot be honored
    // has to be refused however it was written down.
    foreach ($outputs as $name => $definition) {
      static::assertOutputDeclarable((string) $name, $definition);
    }
    return $this->sealed = new Surface(
      DefinitionMap::fromArrays(
        definitions: $definitions,
        refinements: $this->refinements,
        locked: $locked,
        refiners: $this->refiners,
        contributions: $this->contributions,
        attachments: $this->attachments,
        variants: $sealed,
        staticRefinements: $this->staticRefinements(),
        lists: $this->lists,
        watchedBy: $this->watchedBy,
        ports: array_values($this->ports),
        mounted: $this->mounted === [] ? [] : ['third_party_settings' => $this->mounted],
        mixins: $this->mixins,
        held: $held,
        runtimeAccess: $this->runtimeAccessWhole ? array_map('strval', array_keys($definitions)) : array_values(array_intersect($this->runtimeAccess, array_keys($definitions))),
        lockReasons: array_intersect_key($this->lockReasons, $definitions),
        frameConstraints: $this->frameConstraints,
      ),
      $this->cacheability,
      DefinitionMap::fromArrays(definitions: $outputs),
      $this->thirdPartyLayouts,
      $this->watchedValueCheck,
      lockedWhole: $this->lockedWhole,
      changesNothing: $this->changesNothing,
      version: $this->version,
      runtimeAccessOwn: $this->runtimeAccessWhole ? array_values(array_intersect($this->runtimeAccess, array_keys($definitions))) : NULL,
    );
  }

  /**
   * Builds the map one provider namespace per property is mounted into.
   *
   * A map of provider maps, so a contributed key is addressed under the
   * name of whoever answers for it and can collide with nobody.
   *
   * The settings' map only groups: it is marked so, and a form or a
   * served contract draws each provider's map and nothing around them.
   * Its own label and description are kept for a reader with the
   * definition alone, such as the Tool API.
   *
   * @param array<string, array<string, \Drupal\Core\TypedData\DataDefinitionInterface>> $mounted
   *   The mounted definitions, keyed by provider, then by key.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The assembled map.
   */
  protected function mountedMap(array $mounted): MapDataDefinition {
    $providers = static::groupingMap();
    foreach ($mounted as $provider => $keys) {
      $provider_map = $this->getThirdPartyMount((string) $provider);
      assert($provider_map instanceof MapDataDefinition);
      foreach ($keys as $key => $definition) {
        $provider_map->setPropertyDefinition((string) $key, $definition);
      }
      $providers->setPropertyDefinition((string) $provider, $provider_map);
    }
    return $providers;
  }

  /**
   * Makes the map the providers' maps are mounted in, with no providers.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The map, marked as only grouping.
   */
  protected static function groupingMap(): MapDataDefinition {
    $providers = MapDataDefinition::create()
      ->setLabel(t('Third party settings'))
      ->setDescription(t('Settings added by other modules.'));
    DefinitionMetadata::setGrouping($providers);
    return $providers;
  }

  /**
   * Gets the map describing an attached structure's key, before filling.
   *
   * A key may be described before it is attached, with an empty map
   * carrying its label and description, and that map is kept; otherwise
   * a bare one is made.
   *
   * @param string $key
   *   The definition key.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The shell.
   *
   * @throws \InvalidArgumentException
   *   When the key already holds anything but an empty map.
   */
  protected function shellFor(string $key): MapDataDefinition {
    $existing = $this->definitions[$key] ?? NULL;
    if ($existing === NULL) {
      return MapDataDefinition::create();
    }
    if (!$existing instanceof MapDataDefinition || $existing->getPropertyDefinitions() !== []) {
      throw new \InvalidArgumentException(sprintf(
        'The "%s" key already holds a %s definition. An attached child is the whole of what the key holds; describe the key beforehand with an empty map, if at all.',
        $key,
        $existing->getDataType(),
      ));
    }
    return $existing;
  }

  /**
   * Checks that an attached structure's key still holds an empty map.
   *
   * @param string $key
   *   The definition key.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The shell.
   *
   * @throws \LogicException
   *   When something replaced the shell or gave it properties.
   */
  protected function assertShell(string $key): MapDataDefinition {
    $shell = $this->definitions[$key] ?? NULL;
    if (!$shell instanceof MapDataDefinition || $shell->getPropertyDefinitions() !== []) {
      throw new \LogicException(sprintf(
        'The "%s" key holds an attached structure, so what it holds is its child\'s to say: its definition was replaced or given properties of its own after it was attached.',
        $key,
      ));
    }
    return $shell;
  }

  /**
   * Checks that a collection key still holds its shell at seal.
   *
   * The shell may have been narrowed since it was reserved, by a
   * refiner of the parent's that watches nothing, and is read from where
   * the key's definition is now; its item must still be the empty map
   * the child fills.
   *
   * @param string $key
   *   The definition key.
   *
   * @return \Drupal\Core\TypedData\ListDataDefinition
   *   The shell.
   *
   * @throws \LogicException
   *   When something replaced the shell or filled its item.
   */
  protected function assertListShell(string $key): ListDataDefinition {
    $shell = $this->definitions[$key] ?? NULL;
    if (!$shell instanceof ListDataDefinition || !static::isEmptyShell($shell)) {
      throw new \LogicException(sprintf(
        'The "%s" key is a collection, so what each item holds is its child\'s to say: its definition was replaced, or its item given properties, after it was attached.',
        $key,
      ));
    }
    return $shell;
  }

  /**
   * Answers whether a definition is a shell a child has yet to fill.
   *
   * An empty map, for an attached child or variants; a list whose item is
   * an empty map, for a collection.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $definition
   *   The definition at the key.
   *
   * @return bool
   *   TRUE for an empty shell.
   */
  protected static function isEmptyShell(?DataDefinitionInterface $definition): bool {
    if ($definition instanceof ListDataDefinition) {
      $item = $definition->getItemDefinition();
      return $item instanceof MapDataDefinition && $item->getPropertyDefinitions() === [];
    }
    return $definition instanceof MapDataDefinition && $definition->getPropertyDefinitions() === [];
  }

  /**
   * Refuses to attach at a key twice.
   *
   * @param string $key
   *   The definition key.
   *
   * @throws \LogicException
   *   When the key is already attached, has variants or is a collection.
   */
  protected function assertNotNested(string $key): void {
    if (isset($this->attachments[$key]) || isset($this->variants[$key]) || isset($this->lists[$key])) {
      throw new \LogicException(sprintf('The "%s" key already holds an attached structure: one key holds one child, one set of variants, or one collection.', $key));
    }
  }

  /**
   * Refuses an output the data surface cannot honor as declared.
   *
   * Two refusals, and both are about a promise nothing could keep: the
   * reserved name, which belongs to whoever mounts under it rather than
   * to the owner, and a declared default, which is a value nobody can
   * ever receive.
   *
   * @param string $name
   *   The output key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to check.
   *
   * @throws \InvalidArgumentException
   *   When the definition declares a default.
   */
  protected static function assertOutputDeclarable(string $name, DataDefinitionInterface $definition): void {
    static::assertNoDefault($name, $definition);
  }

  /**
   * Refuses an output definition that declares a value to start from.
   *
   * Checked at every depth, because a map output's properties are
   * outputs too and a default hidden on one of them would be just as
   * false a promise: nothing sends an output, so nothing can leave one
   * unsent and get the default instead. A key the producer has nothing
   * to say about is absent.
   *
   * @param string $name
   *   The output key, dotted for a mounted one, for the message.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to check.
   *
   * @throws \InvalidArgumentException
   *   When the definition, or anything inside it, declares a default.
   */
  protected static function assertNoDefault(string $name, DataDefinitionInterface $definition): void {
    if (DefinitionMetadata::hasDefaultValue($definition)) {
      throw new \InvalidArgumentException(sprintf(
        'The "%s" output declares a default value. An output carries none: a default is what a value starts from when nobody sent one, and nobody sends an output. An output that is not always emitted is optional and absent, which is what NotEmitted spells.',
        $name,
      ));
    }
    if ($definition instanceof ComplexDataDefinitionInterface) {
      foreach ($definition->getPropertyDefinitions() as $property => $property_definition) {
        static::assertNoDefault($name . '.' . $property, $property_definition);
      }
    }
    if ($definition instanceof ListDataDefinitionInterface) {
      static::assertNoDefault($name . '.*', $definition->getItemDefinition());
    }
  }

  /**
   * Names a contributing provider the way a person knows it.
   *
   * A provider id is a module machine name, and the module list already
   * holds that module's human name, so the mounted container is titled
   * with it rather than with the machine name. A provider no module
   * answers for (a test fixture, a module being uninstalled) keeps its
   * id, which is still better than no title at all.
   *
   * @param string $provider
   *   The provider id the contribution was recorded under.
   *
   * @return string
   *   The module's human readable name, or the provider id.
   */
  protected static function providerLabel(string $provider): string {
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    $modules = \Drupal::service('extension.list.module');
    return $modules->exists($provider) ? $modules->getName($provider) : $provider;
  }

  /**
   * Refuses every mutation once the data surface has been advertised.
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  protected function assertMutable(): void {
    if ($this->sealed !== NULL) {
      throw new \LogicException('The data surface has been sealed and can no longer be changed: widening is a build-time act, and the build is over.');
    }
  }

  /**
   * Refuses two keys of one frame naming one group and drawing it apart.
   *
   * Keys that name the same group id are drawn in one group, so they
   * agree on its label, its kind, whether it starts collapsed and its
   * weight. A frame is this data surface's own keys, and each module's
   * mount, whose keys are drawn in that module's fieldset; an attached
   * child is a frame of its own, checked where it is sealed.
   *
   * Decision: see docs/decisions.md#arrangement-is-declared-once.
   *
   * @param array<string, \Drupal\Core\TypedData\DataDefinitionInterface> $definitions
   *   The frame's keys.
   * @param string $frame
   *   Where the frame is, for the message: empty for the top.
   *
   * @throws \LogicException
   *   When two keys name one group differently.
   */
  protected static function assertGroupsAgree(array $definitions, string $frame = ''): void {
    $groups = [];
    foreach ($definitions as $name => $definition) {
      $name = (string) $name;
      $group = DefinitionMetadata::arrangementOf($definition)?->group;
      if ($group !== NULL) {
        if (isset($groups[$group->id]) && !$groups[$group->id][1]->sameAs($group)) {
          throw new \LogicException(sprintf(
            'The keys %s and %s%s name the group "%s" and draw it differently (%s against %s). Keys of one frame that name a group are drawn in one group: give each the same group, its label, kind, collapsed and weight alike.',
            $groups[$group->id][0],
            $name,
            $frame === '' ? '' : ' in ' . $frame,
            $group->id,
            json_encode($groups[$group->id][1]->toArray()),
            json_encode($group->toArray()),
          ));
        }
        $groups[$group->id] ??= [$name, $group];
      }
      if ($name === 'third_party_settings' && $definition instanceof ComplexDataDefinitionInterface) {
        foreach ($definition->getPropertyDefinitions() as $module => $mount) {
          if ($mount instanceof ComplexDataDefinitionInterface) {
            static::assertGroupsAgree($mount->getPropertyDefinitions(), 'third_party_settings.' . $module);
          }
        }
      }
    }
  }

  /**
   * Refuses a refinement map in which a key depends on itself.
   *
   * Refinement walks the map one target at a time, so a cycle is not an
   * infinite loop: it is a data surface whose answer depends on the order the
   * keys happen to be read in, which is worse, because it looks like it
   * works. The map is whole only once every contributor has spoken, so
   * this is asked at seal and not before.
   *
   * @throws \LogicException
   *   When any key refines, directly or indirectly, against itself.
   */
  protected function assertNoRefinementCycle(): void {
    $settled = [];
    foreach (array_keys($this->refinements) as $target) {
      $this->walkRefinements((string) $target, [], $settled);
    }
  }

  /**
   * Walks one target's dependencies, depth first, looking for a cycle.
   *
   * @param string $target
   *   The key being walked.
   * @param string[] $path
   *   The keys walked through to reach it.
   * @param array<string, true> $settled
   *   Keys already proven free of cycles, so a diamond is walked once.
   *
   * @throws \LogicException
   *   When the target is already on the path.
   */
  protected function walkRefinements(string $target, array $path, array &$settled): void {
    if (isset($settled[$target])) {
      return;
    }
    $seen = array_search($target, $path, TRUE);
    if ($seen !== FALSE) {
      $cycle = array_slice($path, $seen);
      $cycle[] = $target;
      throw new \LogicException(sprintf(
        'The refinement map has a cycle: %s. A definition cannot be refined against itself, directly or through its dependencies.%s',
        implode(' -> ', $cycle),
        $this->wiringsOn($cycle),
      ));
    }
    $path[] = $target;
    foreach ($this->edgesOf($target) as $dependency) {
      $this->walkRefinements((string) $dependency, $path, $settled);
    }
    $settled[$target] = TRUE;
  }

  /**
   * Names the port wirings a cycle runs through, for its refusal.
   *
   * @param string[] $cycle
   *   The cycle, each step refined against the next.
   *
   * @return string
   *   A sentence naming each wiring, or '' when it runs through none.
   */
  protected function wiringsOn(array $cycle): string {
    $named = [];
    for ($i = 0; $i < count($cycle) - 1; $i++) {
      [$from, $to] = [$cycle[$i], $cycle[$i + 1]];
      foreach ($this->wirings as $target => $dependencies) {
        $target = (string) $target;
        if (isset($dependencies[$to]) && ($target === $from || str_starts_with($from, $target . '.') || str_starts_with($target, $from . '.'))) {
          $named[$dependencies[$to]] = $dependencies[$to];
        }
      }
    }
    return $named === [] ? '' : sprintf(' It runs through %s: a port may not read a key that reads, through the structure, the port itself.', implode(', and ', $named));
  }

  /**
   * Gets what one key or dotted path is refined against, for the walk.
   *
   * A mounted key is refined on its own, by its dotted path, so the walk
   * follows each path's own edges: a capacity watching a license, and a
   * stewards count beside that license watching the capacity, is no
   * cycle. A path also moves with anything refining a key it sits in,
   * and a key with whatever refines a path inside it.
   *
   * @param string $target
   *   A definition key, or a dotted path into one.
   *
   * @return string[]
   *   The keys and dotted paths it refines against.
   */
  protected function edgesOf(string $target): array {
    $edges = [];
    foreach ($this->refinements as $refined => $dependencies) {
      $refined = (string) $refined;
      if ($refined === $target || str_starts_with($target, $refined . '.') || str_starts_with($refined, $target . '.')) {
        array_push($edges, ...$dependencies);
      }
    }
    return array_values(array_unique($edges));
  }

  /**
   * Gets a definition by key, or fails naming the key.
   *
   * @param string $name
   *   The definition key.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The definition.
   *
   * @throws \InvalidArgumentException
   *   When the builder holds no such key.
   */
  protected function named(string $name): DataDefinitionInterface {
    return $this->definitions[$name]
      ?? throw new \InvalidArgumentException(sprintf('Unknown data surface definition "%s".', $name));
  }

  /**
   * Says who a key's existing value belongs to.
   *
   * @param string $name
   *   The definition key.
   * @param mixed $value
   *   The value somebody is trying to contribute a second time.
   *
   * @return string
   *   The provider that contributed it, or the owner, in words.
   */
  protected function contributorOf(string $name, mixed $value): string {
    foreach ($this->contributions[$name] ?? [] as $contributor => $values) {
      if (in_array($value, $values)) {
        return (string) $contributor;
      }
    }
    return 'the declaring class';
  }

  /**
   * Splits contributed choices into values and their labels.
   *
   * @param array $choices
   *   A list of bare values, or values mapped to their labels.
   *
   * @return array{0: array, 1: array}
   *   The values, and the labels keyed by value. A bare value has no
   *   label of its own and is named by its value, which is what core's
   *   own Choice consumers do.
   */
  protected static function normalizeChoices(array $choices): array {
    return array_is_list($choices)
      ? [$choices, []]
      : [array_keys($choices), $choices];
  }

  /**
   * Writes one value out for a message.
   *
   * @param mixed $value
   *   The value.
   *
   * @return string
   *   Something readable, whatever the value turned out to be.
   */
  protected static function describe(mixed $value): string {
    return is_scalar($value) ? (string) $value : get_debug_type($value);
  }

}
