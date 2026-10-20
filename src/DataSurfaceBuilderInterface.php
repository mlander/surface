<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\Refinement\WatchedValueCheckInterface;
use Drupal\data_surface\Storage\SettingsLayoutInterface;

/**
 * The mutable stage a data surface passes through before it is sealed.
 *
 * The engine under the build step. Resources writes an owner's shape, its
 * alters' additions, the context and the bound #[RefinesInput] methods
 * into one of these, then seals it; nothing an author writes holds one.
 * BlueprintInterface and BlueprintAdditionsInterface are the author's view of
 * it, and each of their verbs lands on a method here.
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
 * @see \Drupal\data_surface\ResourceBuild\Resources
 * @see docs/refinement.md
 *
 * @internal
 */
interface DataSurfaceBuilderInterface {

  /**
   * The output key the namespaced third-party outputs are mounted under.
   *
   * The output side's mirror of the input side's 'third_party_settings'
   * map, and spelled differently on purpose: an output is not a setting,
   * and a host that emits both would otherwise have one key meaning two
   * things. The owner may not declare an output of this name, because
   * the key belongs to everybody who mounts under it.
   */
  const THIRD_PARTY_OUTPUTS = 'third_party_outputs';

  /**
   * Gets a definition, so alters can inspect or modify it.
   *
   * @param string $name
   *   The definition key.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The definition, or NULL when the builder holds no such key.
   */
  public function getDefinition(string $name): ?DataDefinitionInterface;

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
  public function setDefinition(string $name, DataDefinitionInterface $definition): static;

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
  public function setDefault(string $name, mixed $value): static;

  /**
   * Locks a key: its value is fixed to the declared default.
   *
   * Locking is build-time context (an edit operation locking an
   * identifier, say): the degenerate refinement, narrowing the value
   * space to exactly one value. Locked keys stay advertised: consumers
   * see the key and its value, rendered disabled in generated forms,
   * and extraction ignores submitted input for them.
   *
   * @param string $name
   *   The definition key.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed, or the name is an output: an
   *   output cannot be locked, because locking narrows what a caller
   *   may send and nothing sends an output.
   */
  public function lock(string $name): static;

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
  public function omit(string $name): static;

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
  public function hold(string $name): static;

  /**
   * Holds every key to what is stored: an access answer locked the whole.
   *
   * What a resource's own access class answers locked at the top, asked
   * on its own, or a slot's variant's access class answers locked: every
   * key is shown and fixed, held as hold() holds one, and the sealed data
   * surface says why (DataSurfaceInterface::lockedWhole()). Applied at the
   * seal, after the slots, so a slot is chosen by what its deciding key
   * holds rather than preselected by its default.
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
  public function lockWhole(string|\Stringable $reason): static;

  /**
   * Marks a data surface built for an operation declared read only.
   *
   * Its pipeline accepts, validates and prepares, and writes nothing:
   * submit() stops where a dry run does, and commit() refuses what was
   * prepared for it (DataSurfaceInterface::changesNothing()).
   *
   * Decision: see docs/decisions.md#an-operation-states-how-it-behaves.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function markChangesNothing(): static;

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
  public function setVersion(int $version): static;

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
  public function withdraw(string $name): static;

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
   *   The key: a child's, a slot's, a collection's, or a mixed-in
   *   structure's member.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function markRuntimeAccess(string $name): static;

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
  public function markRuntimeAccessWhole(): static;

  /**
   * Records a structure mixed in: which of the keys it brought.
   *
   * Its members are declared as the owner's keys already, through the
   * blueprint; this is what the contract needs to state the structure
   * once, as a def the owner composes with its own keys.
   *
   * @param \Drupal\data_surface\Mixin $mixin
   *   The structure, built in its own frame, and the keys it brought.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function addMixin(Mixin $mixin): static;

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
   * Both choice constraints are understood, and the list is written back
   * in the canonical spelling: `choices` as the list of allowed values,
   * `labels` as what each one is called. A plain Choice has nowhere to
   * keep labels, so contributing them to one drops them. The added
   * values go after the existing ones, and an existing value keeps the
   * label it already had.
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
  public function extendChoices(string $name, array $choices, string $contributor): static;

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
  public function setThirdPartyDefinition(string $provider, string $key, DataDefinitionInterface $definition, mixed $default = NULL): static;

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
   * provider mounted, and must be pure (the SettingsLayoutInterface
   * contract), because the data surface carrying it is serialized with every
   * form that renders it.
   *
   * @param string $provider
   *   The module whose mounted settings the layout translates. It must
   *   mount at least one definition before the data surface is sealed.
   * @param \Drupal\data_surface\Storage\SettingsLayoutInterface $layout
   *   The translation.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   *
   * @see \Drupal\data_surface\Storage\ConfigEntityStorage
   */
  public function setThirdPartyLayout(string $provider, SettingsLayoutInterface $layout): static;

  /**
   * Gives the sealed data surface the check its watched values pass.
   *
   * Refinement hands a refiner a sibling's value only when that value
   * satisfies the sibling's own refined definition; a refused value is
   * passed as if the sibling held nothing. A data surface sealed without a
   * check (the engine's own tests build those) hands every configured
   * value over.
   *
   * @param \Drupal\data_surface\Refinement\WatchedValueCheckInterface $check
   *   The check, which rides inside the sealed data surface.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   *
   * @see docs/decisions.md#a-refiner-never-sees-an-invalid-sibling
   */
  public function setWatchedValueCheck(WatchedValueCheckInterface $check): static;

  /**
   * Gets an output definition, so alters can inspect or modify it.
   *
   * @param string $name
   *   The output key.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The definition, or NULL when the builder holds no such output.
   */
  public function getOutputDefinition(string $name): ?DataDefinitionInterface;

  /**
   * Adds or replaces an output definition.
   *
   * What the host's execution emits, declared in the same vocabulary as
   * what it accepts. The owner's verb, exactly as setDefinition() is: an
   * alter adding an output of its own mounts it under its
   * namespace with setThirdPartyOutputDefinition() instead, and never
   * replaces one the owner declared — a consumer reading the owner's
   * output schema has to keep reading true.
   *
   * Two differences from an input definition, both enforced:
   * - An output carries no default. A default is what a value starts
   *   from when nobody sent one, and nobody sends an output; a key the
   *   producer has nothing to say about is absent, which is what the
   *   Omitted sentinel spells. A definition carrying 'default_value',
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
   *   When the definition declares a default value, or when the name is
   *   the reserved third-party outputs key.
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function setOutputDefinition(string $name, DataDefinitionInterface $definition): static;

  /**
   * Mounts a third-party output under the provider's namespace.
   *
   * The output side's mirror of setThirdPartyDefinition(): a contributor
   * may *add* to what a host emits, under its own provider namespace, so
   * the emitted value lands at
   * third_party_outputs.<provider>.<key> — advertised, conformance
   * checked, and machine-visible. What it may never do is alter the
   * owner's outputs, which is why there is a namespace at all.
   *
   * @param string $provider
   *   The module mounting the output.
   * @param string $key
   *   The output's key within that provider's namespace.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition describing what is emitted.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   When the provider id is empty or claims the owner's name, or the
   *   definition declares a default value.
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function setThirdPartyOutputDefinition(string $provider, string $key, DataDefinitionInterface $definition): static;

  /**
   * Gets a definition a provider mounted, by provider and key.
   *
   * @param string $provider
   *   The module that mounted it.
   * @param string $key
   *   The key within that provider's namespace.
   * @param bool $output
   *   TRUE for a mounted output, FALSE for a mounted setting.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The definition, or NULL when the provider mounted no such key.
   */
  public function getThirdPartyDefinition(string $provider, string $key, bool $output = FALSE): ?DataDefinitionInterface;

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
   * @param bool $output
   *   TRUE for the map of its mounted outputs, FALSE for its settings.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition|null
   *   The map, or NULL when the provider mounted nothing on that side.
   */
  public function getThirdPartyMount(string $provider, bool $output = FALSE): ?MapDataDefinition;

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
  public function reserveThirdPartyMount(string $provider): MapDataDefinition;

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
   * @param \Drupal\data_surface\Attachment $attachment
   *   The sealed structure, with its ports wired.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed, or the provider has mounted
   *   anything already.
   */
  public function mountStructure(string $provider, Attachment $attachment): static;

  /**
   * Declares a port the data surface reads from its container.
   *
   * @param \Drupal\data_surface\Port $port
   *   The port.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed, or the name is a key.
   */
  public function addPort(Port $port): static;

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
  public function addRefinement(string $target, array $dependencies, bool $runtime = FALSE, bool $declared = FALSE, ?string $wiring = NULL): static;

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
   * @param \Drupal\data_surface\DataSurfaceRefinerInterface $refiner
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
  public function addRefiner(string $target, DataSurfaceRefinerInterface $refiner, ?string $contributor = NULL): static;

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
  public function addCacheableDependency(CacheableDependencyInterface $dependency): static;

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
   * @param \Drupal\data_surface\Attachment $attachment
   *   The sealed child, and the class it was built from.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   When the key already holds anything but an empty map.
   * @throws \LogicException
   *   When the key already holds an attached child.
   */
  public function attach(string $key, Attachment $attachment): static;

  /**
   * Declares a slot: an attached structure a sibling key chooses.
   *
   * Advertised as an `any` placeholder marked as a slot until the deciding
   * key holds a value, and as exactly the chosen variant's map once it
   * does, or from the start, when the deciding key is locked. The
   * deciding key gains a Choice over the variant ids, checked narrower
   * against any list of values it already declares, and becomes a
   * refinement dependency of the slot, so a form rebuilds on it and the
   * discard cascade resets a variant it orphaned.
   *
   * @param string $key
   *   The definition key.
   * @param string $by
   *   The sibling key whose value chooses the variant.
   * @param array<string, \Drupal\data_surface\Attachment> $variants
   *   One sealed child per value of the deciding key. May be empty: an
   *   open slot nothing has filled offers nothing to choose.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   When the deciding key is the slot itself, is not declared, holds a
   *   list or a map, or the key already holds anything but an empty map.
   * @throws \LogicException
   *   When the key already holds an attached child, or a variant names a
   *   value the deciding key does not allow.
   */
  public function attachBy(string $key, string $by, array $variants): static;

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
   * @param \Drupal\data_surface\Collection $list
   *   The child every item is, sealed once.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   When the key already holds anything but such a list.
   * @throws \LogicException
   *   When the key already holds an attached child or a collection.
   */
  public function attachList(string $key, Collection $list): static;

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
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The advertised data surface.
   *
   * @throws \LogicException
   *   When a key refines, directly or indirectly, against itself.
   */
  public function seal(): DataSurfaceInterface;

}
