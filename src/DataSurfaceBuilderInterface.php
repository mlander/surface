<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Refinement\WatchedValueCheckInterface;
use Drupal\data_surface\Target\SettingsShapeInterface;

/**
 * The mutable stage a surface passes through before it is sealed.
 *
 * The engine under the build step. Surfaces writes an owner's shape, its
 * alters' additions, the context and the bound #[RefinesInput] methods
 * into one of these, then seals it; nothing an author writes holds one.
 * ShapeInterface and ShapeAdditionsInterface are the author's view of
 * it, and each of their verbs lands on a method here.
 *
 * Every addition is a contribution recorded under the name of whoever
 * made it, which is what lets refinement be shared: a contributor's
 * refiners narrow the values that contributor added and nothing else,
 * and the refined surface is the union of what each contribution
 * narrowed to. The owner is the first contributor.
 *
 * A builder is single use. seal() is idempotent, and every mutator
 * throws once it has run.
 *
 * @see \Drupal\data_surface\SurfaceBuild\Surfaces
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
   *   The surface key.
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
   *   The surface key.
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
   *   The surface key.
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
   * identifier, say) — the degenerate refinement, narrowing the value
   * space to exactly one value. Locked keys stay advertised: consumers
   * see the key and its value, rendered disabled in generated forms,
   * and extraction ignores submitted input for them.
   *
   * @param string $name
   *   The surface key.
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
   * Extends an existing definition's declared choices with more values.
   *
   * The contribution API for widening at build time (decision D2): the
   * added values become part of the advertised contract, and the module
   * adding them owns their behavior — in the host's consumption path,
   * and in refinement, where a refiner registered under the same name is
   * handed exactly these values and no others.
   *
   * Widening here is legal precisely because nothing has been advertised
   * yet: every consumer reads the extended surface, never a variant of
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
   *   The surface key.
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
   * Mirrors core's third-party settings storage shape: the sealed
   * surface gains a 'third_party_settings' map with one map per
   * provider, so values land at third_party_settings.<provider>.<key> —
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
   * A contributor may ask a caller for a value in one shape and store it
   * in another — a duration asked for as an amount and a unit and stored
   * as a number of seconds. The owner's target cannot know that, and the
   * contributor does not build the target, so the translation travels on
   * the surface: a target that writes third-party settings applies the
   * provider's shape to that provider's namespace, toStorage() on the
   * way in and fromStorage() on the way out, and to nothing else.
   *
   * The shape sees the provider's settings only, keyed by the keys the
   * provider mounted, and must be pure — the SettingsShapeInterface
   * contract — because the surface carrying it is serialized with every
   * form that renders it.
   *
   * @param string $provider
   *   The module whose mounted settings the shape translates. It must
   *   mount at least one definition before the surface is sealed.
   * @param \Drupal\data_surface\Target\SettingsShapeInterface $shape
   *   The translation.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   *
   * @see \Drupal\data_surface\Target\ConfigEntityTarget
   */
  public function setThirdPartyShape(string $provider, SettingsShapeInterface $shape): static;

  /**
   * Gives the sealed surface the check its watched values pass.
   *
   * Refinement hands a refiner a sibling's value only when that value
   * satisfies the sibling's own refined definition; a refused value is
   * passed as if the sibling held nothing. A surface sealed without a
   * check — the engine's own tests build those — hands every configured
   * value over.
   *
   * @param \Drupal\data_surface\Refinement\WatchedValueCheckInterface $check
   *   The check, which rides inside the sealed surface.
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
   * Declares that a target refines against sibling values.
   *
   * @param string $target
   *   The surface key whose definition is refined, or the dotted path of
   *   a mounted key, `third_party_settings.<module>.<key>`, refined
   *   inside the mount.
   * @param array $dependencies
   *   The sibling keys it is refined against; a mounted key by its
   *   dotted path.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function addRefinement(string $target, array $dependencies): static;

  /**
   * Appends a refiner to one contribution's chain for a target.
   *
   * A chain narrows one contribution's own slice of the target: the
   * owner's refiners are handed the values the owner declared, and a
   * contributor's are handed the values that contributor added with
   * extendChoices(). Links run in registration order, each receiving the
   * previous link's output, and every link is held to the narrowing
   * contract against what it was handed — so a contributor can neither
   * narrow away somebody else's value nor hand back one of its own that
   * it was not given.
   *
   * Refiners must be serializable (services or named classes, no
   * closures or anonymous classes): surfaces ride along in cached forms.
   *
   * @param string $target
   *   The surface key whose definition is refined.
   * @param \Drupal\data_surface\DataSurfaceRefinerInterface $refiner
   *   The refiner to append.
   * @param string|null $contributor
   *   The provider whose contribution this refiner speaks for, or NULL
   *   for the surface's owner.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function addRefiner(string $target, DataSurfaceRefinerInterface $refiner, ?string $contributor = NULL): static;

  /**
   * Declares what the surface being built depends on.
   *
   * A surface is computed from live site state, so it can only be reused
   * for as long as that state holds. Whatever is declared here is
   * carried by the sealed surface, merged with what every refiner that
   * runs declares, and read by anything that renders or stores the
   * surface. The split between metadata that travels into a shared
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
   * Fixes a child surface at a key: a subsurface.
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
   *   The surface key.
   * @param \Drupal\data_surface\SurfaceAttachment $attachment
   *   The sealed child, and the surface class it was built from.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   When the key already holds anything but an empty map.
   * @throws \LogicException
   *   When the key is already a subsurface.
   */
  public function attach(string $key, SurfaceAttachment $attachment): static;

  /**
   * Declares a slot: a subsurface a sibling key chooses.
   *
   * Advertised as an `any` placeholder marked as a slot until the deciding
   * key holds a value, and as exactly the chosen variant's map once it
   * does — or from the start, when the deciding key is locked. The
   * deciding key gains a Choice over the variant ids, checked narrower
   * against any list of values it already declares, and becomes a
   * refinement dependency of the slot, so a form rebuilds on it and the
   * discard cascade resets a variant it orphaned.
   *
   * @param string $key
   *   The surface key.
   * @param string $by
   *   The sibling key whose value chooses the variant.
   * @param array<string, \Drupal\data_surface\SurfaceAttachment> $variants
   *   One sealed child per value of the deciding key. May be empty: an
   *   open slot nothing has filled offers nothing to choose.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   When the deciding key is the slot itself, is not declared, holds a
   *   list or a map, or the key already holds anything but an empty map.
   * @throws \LogicException
   *   When the key is already a subsurface, or a variant names a value
   *   the deciding key does not allow.
   */
  public function attachBy(string $key, string $by, array $variants): static;

  /**
   * Seals the builder into an immutable surface.
   *
   * The only construction path for a surface. Idempotent: the same
   * surface comes back however often this is called, so a caller holding
   * a builder and a caller holding its surface are looking at one
   * object.
   *
   * This is also where the refinement map is checked for cycles. It is
   * asked here and not earlier because the map is only whole once every
   * contributor has added to it, and a key that refines against itself
   * would otherwise produce an answer that depends on the order the keys
   * happened to be read in.
   *
   * What comes out is a DefinitionMap: the builder's own bookkeeping —
   * definitions, refinement edges, locked keys, contributed values and
   * refiner chains — folded into one entry per key, in declaration
   * order, and checked once for the rest of its shape.
   *
   * The outputs become a second map of the same type, holding whatever
   * the owner and its alters declared it emits. Its entries carry no
   * locked flag, no contributed values and no refiners: outputs are
   * never refined.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The advertised surface.
   *
   * @throws \LogicException
   *   When a key refines, directly or indirectly, against itself.
   */
  public function seal(): DataSurfaceInterface;

}
