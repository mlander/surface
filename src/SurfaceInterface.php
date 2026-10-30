<?php

declare(strict_types=1);

namespace Drupal\surface;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Storage\StorageLayoutInterface;

/**
 * A runtime description of the values something accepts.
 *
 * An ordered set of core data definitions plus a refinement map naming
 * which definitions depend on which sibling values, with per-key
 * locking. Built at runtime rather than read from static schema, so it
 * can carry live option lists and site-state constraints that YAML
 * cannot express.
 *
 * All of that is one DefinitionMap of Entry objects. A second map
 * of the same type holds the other half of the contract: what the host's
 * execution emits, declared in the same vocabulary, never refined, and
 * checked by the pipeline's conformOutput(). The
 * rule the module holds itself to, once: contracts are objects, payloads
 * are arrays. What a data surface says is read off typed objects; the
 * values that flow through accept, validate, prepare and commit are plain
 * arrays, and so is the syntax an author declares a resource or structure
 * in.
 *
 * One data surface drives everything downstream: default values,
 * refinement, form generation (through the data surface widgets),
 * validation (through the pipeline), and eventually machine-readable
 * schema, so a form and any other consumer can never disagree about what
 * the values accept.
 *
 * A data surface is pure data. It reaches no service and holds no behavior
 * beyond reading its own definitions and applying the refiners it was
 * sealed with (the #[RefinesInput] methods the build step bound), which
 * is what lets it ride along in a cached form and be read by a caller
 * that has no container. Anything needing a service (validation above
 * all) belongs to
 * \Drupal\surface\Pipeline\SurfacePipelineInterface.
 *
 * Data surfaces are built by the build step,
 * \Drupal\surface\ResourceBuild\ResourcesInterface, which seals a
 * builder; nothing else constructs one. What it builds is a resource or
 * a structure, so a data surface is the built resource or the built
 * structure.
 *
 * A data surface is also a cacheable dependency. What it advertises is
 * computed from live site state (option lists read from entity types,
 * languages, plugins) and from whatever the refiners that ran declared,
 * so anything that renders or stores a data surface has to be able to say for
 * how long, and under which conditions, that rendering holds. The rules
 * for reading it are in docs/refinement.md.
 *
 * @see \Drupal\surface\SurfaceBuilderInterface::seal()
 * @see \Drupal\surface\Pipeline\SurfacePipelineInterface
 * @see docs/refinement.md
 * @see docs/outputs.md
 */
interface SurfaceInterface extends CacheableDependencyInterface {

  /**
   * The contributor id standing for the owner.
   *
   * The owner is the resource or structure that declared the shape, and
   * the first contributor: its definitions, its refinement map and its
   * #[RefinesInput] methods are contribution number one, and it is held
   * to the same rules as everybody who arrives later. The id
   * is spelled so that no module can claim it, because a module name
   * never contains a '#'.
   */
  const OWNER = '#owner';

  /**
   * The coded refiners: the #[RefinesInput] methods not declared runtime.
   *
   * One of three kinds of link a key's chain holds, which refine() may be
   * asked to run alone or together. Decision: see
   * docs/decisions.md#a-rule-declared-as-data.
   */
  const CODED = 1;

  /**
   * The declared rules: conditions and effects stated as data.
   */
  const DECLARED = 2;

  /**
   * The runtime refiners: the #[RefinesInput] methods declared runtime.
   */
  const RUNTIME = 4;

  /**
   * The static refiners, coded and declared: what may be stated ahead.
   */
  const STATIC = self::CODED | self::DECLARED;

  /**
   * Every refiner: what the pipeline holds the values to.
   */
  const EVERY = self::STATIC | self::RUNTIME;

  /**
   * Gets everything the data surface advertises, in declaration order.
   *
   * The whole contract in one object: iterate it for name => definition,
   * ask it for one entry to learn who introduced a key, whether it is
   * locked, what it refines against and whose values it offers. The
   * refinement map, the contributions and the locked keys are read from
   * here rather than from accessors of their own.
   *
   * @return \Drupal\surface\DefinitionMap
   *   The definition map.
   */
  public function getDefinitions(): DefinitionMap;

  /**
   * Gets what the host's execution emits, in declaration order.
   *
   * The other half of the contract, in the same vocabulary and the same
   * collection type as the inputs: one entry per output key, carrying
   * its definition and who introduced it. A data surface that declares no
   * outputs answers with an empty map, so nothing has to ask first.
   *
   * What differs from the input map, and why:
   * - No defaults. A default is what a value starts from when nobody
   *   sent one, and nobody sends an output.
   * - No locking. Locking narrows what a caller may send.
   * - No refinement. A refinement narrows what may be sent, and nobody
   *   sends an output; an output whose shape depends on an input value
   *   is a variant, declared with attachVariants() on that input.
   *
   * An output key the producer has nothing to say about is **absent**
   * from the emitted array, never NULL; Pipeline\NotEmitted is how a
   * producer says so from inside an array literal, and
   * SurfacePipelineInterface::conformOutput() is what checks an
   * emitted array against this map.
   *
   * @return \Drupal\surface\DefinitionMap
   *   The output definitions; empty when the data surface declares none.
   *
   * @see \Drupal\surface\Pipeline\NotEmitted
   * @see docs/outputs.md
   */
  public function getOutputDefinitions(): DefinitionMap;

  /**
   * Gets one definition, or NULL if the data surface does not declare it.
   *
   * Kept beside the map because reading one key by name is what most
   * host code does, and getDefinitions()->get() says the same thing
   * twice.
   *
   * @param string $name
   *   The definition key.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The definition, or NULL when the data surface declares no such key.
   */
  public function getDefinition(string $name): ?DataDefinitionInterface;

  /**
   * Returns whether a key's value is fixed by the data surface.
   *
   * Locking is the degenerate refinement: the value space is narrowed to
   * exactly one value. That value is whatever storage already holds, and
   * the declared default only for a key storage has never held. A locked
   * key stays advertised, renders disabled, and ignores submitted
   * input.
   *
   * @param string $name
   *   The definition key.
   *
   * @return bool
   *   TRUE when the key's value is fixed.
   */
  public function isLocked(string $name): bool;

  /**
   * Says why every key is held to what is stored, if an answer said so.
   *
   * Set when an access answer locked the whole: a resource's own, asked on
   * its own (every key shown and fixed, and a submit refused before
   * anything is written), or a variant's (offered and shown, fixed).
   *
   * Decision: see docs/decisions.md#locked-at-the-top.
   *
   * @return string|\Stringable|null
   *   The reason, or NULL when the keys are not locked as a whole.
   */
  public function lockedWhole(): string|\Stringable|null;

  /**
   * Answers whether a runtime answer at the top marked every key.
   *
   * Set when the access asked for the resource as a whole was set aside,
   * built for the account alone or for no account: once the subject is
   * known, any key may be found locked or left out, so every key is
   * marked (DefinitionMap::isRuntimeAccess()), and a listing says so once
   * rather than on each key.
   *
   * Decision: see
   * docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability.
   *
   * @return bool
   *   TRUE when every key is marked by an answer at the top.
   */
  public function isRuntimeAccessWhole(): bool;

  /**
   * Answers whether a key is marked by an access answer of its own.
   *
   * A key a child's access, a mixin's or a variant's marks as decided
   * once the subject is known: its mark is its own, whether or not an
   * answer at the top marked every key as well.
   *
   * Decision: see
   * docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability.
   *
   * @param string $key
   *   The key.
   *
   * @return bool
   *   TRUE when the key's own answer marked it.
   */
  public function isRuntimeAccessOwn(string $key): bool;

  /**
   * Answers whether it was built for an operation that changes nothing.
   *
   * Decision: see docs/decisions.md#an-operation-states-how-it-behaves.
   *
   * @return bool
   *   TRUE for an operation declared read only: the pipeline writes
   *   nothing for it.
   */
  public function changesNothing(): bool;

  /**
   * Gets the watched values refinement handed over as nothing.
   *
   * A value a refiner watches that its own key's definition refuses is
   * passed to the refiners as if the key held nothing; a refusal that
   * names what a key depends on says so of it, not what was sent.
   *
   * Decision: see docs/decisions.md#a-refiner-never-sees-an-invalid-sibling.
   *
   * @return array<string, mixed>
   *   The values, by the watched key's dotted path, each as it was given;
   *   empty on a data surface refine() did not make, or when nothing was
   *   refused.
   */
  public function withheldValues(): array;

  /**
   * Gets which version of its stored values it describes.
   *
   * The `version` of the #[Resource] or #[Structure] it was built from, 1
   * for one that declares none and for one no build step made. Both
   * documents stamp it as `x-surface.version`.
   *
   * Decision: see docs/decisions.md#a-resource-evolves-in-the-open.
   *
   * @return int
   *   The version, from 1.
   */
  public function version(): int;

  /**
   * Gets how one provider's mounted third-party settings are stored.
   *
   * @param string $provider
   *   The module that mounted the settings.
   *
   * @return \Drupal\surface\Storage\StorageLayoutInterface|null
   *   The layout the provider declared at build time, or NULL when its
   *   settings are stored exactly as the data surface describes them.
   *
   * @see \Drupal\surface\SurfaceBuilderInterface::setThirdPartyLayout()
   */
  public function getThirdPartyLayout(string $provider): ?StorageLayoutInterface;

  /**
   * Gets the default value for one key.
   *
   * @param string $name
   *   The definition key.
   *
   * @return mixed
   *   The declared default, or NULL when the key declares none or the
   *   data surface does not declare the key at all.
   */
  public function getDefault(string $name): mixed;

  /**
   * Builds the default values the data surface declares.
   *
   * @return array
   *   Defaults keyed by definition key; keys without a declared default are
   *   present with NULL so consumers see the full shape.
   */
  public function getDefaultValues(): array;

  /**
   * Lays the declared defaults under values, following the definitions.
   *
   * What every door that starts from storage reads: a key the values leave
   * out holds its default, and so does an entry left out of a map they
   * hold, at any depth. The keys of an attached structure, and the entries
   * of a map of keys from the site, merge one by one. A list is the list
   * the values hold, never padded with default items, and each item of a
   * collection merges with the defaults of the structure it is. A key with
   * variants merges within the variant its deciding key chooses; a value
   * written for another variant stays as it is. Anything the values hold
   * that no definition declares stays as it is.
   *
   * Decision: see
   * docs/decisions.md#defaults-follow-the-definitions-under-a-stored-value.
   *
   * @param array $values
   *   The values, keyed by key: what storage holds, typically.
   *
   * @return array
   *   The values with the defaults under them, every declared key present.
   */
  public function withDefaults(array $values): array;

  /**
   * Returns a data surface with definitions refined against known values.
   *
   * A target refines only when every one of its dependencies holds a
   * configured value, judged by the same rule the pipeline applies
   * everywhere else: anything but NULL and the empty string, so a
   * checkbox that is off and a list with no items are answers a refiner
   * can narrow against.
   *
   * A refiner never sees an invalid sibling value. Each watched value is
   * first held to its own key's refined definition, and a value that
   * definition refuses is passed as if the key held nothing: a whole key
   * withholds the refiners watching it, a key an alter mounted is handed
   * as NULL. The values themselves are not changed, so a form still
   * shows what was typed and the pipeline still refuses it, under its own
   * key; only refinement treats it as absent.
   *
   * What refinement does with those values, in four lines:
   * 1. Deep-clone the advertised definition and divide its list of
   *    allowed values into the owner's (everything nobody contributed)
   *    and one slice per contributor.
   * 2. Run the owner's refiners over the owner's slice.
   * 3. Run each contributor's refiners over that contributor's slice.
   * 4. The refined definition is the owner's, with its list of allowed
   *    values replaced by the union of every narrowed slice.
   *
   * Every link is held to the narrowing contract
   * against what it was handed, so nothing can hand back a value it was
   * not given. The 'any' data type is the declared escape hatch: it may
   * refine to a concrete type. A refiner that declares cacheability, by
   * implementing CacheableDependencyInterface, has it merged into the
   * refined data surface when it runs.
   *
   * A refiner is coded, declared or runtime. A runtime refiner's link
   * runs in its chain like any other, and one that watches nothing runs
   * whatever the key's gate says, since it has nothing to wait for. A
   * declared rule is never gated either: it reads the keys its condition
   * names as they stand, whatever the key's other refiners wait for.
   * Asked for the static refiners alone, the runtime ones are left out
   * and each key waits only for what its coded refiners watch: what the
   * data surface can state ahead of time, which is all the served
   * contract's full schema and its conditionals may say. The served
   * contract asks for the coded ones alone too, since a declared rule is
   * stated as written, never enumerated.
   *
   * @param array $values
   *   Values keyed by definition key.
   * @param bool|int $runtime
   *   TRUE, the default, for what the pipeline holds the values to: every
   *   refiner (self::EVERY). FALSE for the static refiners alone
   *   (self::STATIC). Or the kinds to run, of self::CODED,
   *   self::DECLARED and self::RUNTIME.
   *
   * @return static
   *   A refined data surface, or the same one when nothing refines.
   *
   * @throws \LogicException
   *   When a refiner returns a definition that accepts more
   *   than the one it was given.
   */
  public function refine(array $values, bool|int $runtime = TRUE): static;

  /**
   * Returns the same data surface reading its ports from these values.
   *
   * A port is a value the data surface reads from its container, declared
   * with BlueprintInterface::reads() and wired by the container to one of
   * its keys. Its container binds it: refine() then hands each port's
   * value to the refiners and rules that read it, by the port's name, as
   * a sibling's value is handed. Nothing else changes: a port is no key,
   * holds no value of the data surface's own, and is never stored. A map
   * of keys from the site whose entries read a sibling map's entries
   * reads that map through a port (Port::$through) and binds each entry's
   * ports here, to the entry of the same id.
   *
   * @param array<string, mixed> $values
   *   What each port holds, by port name; names that are no port are
   *   ignored, and a port left out holds nothing.
   * @param array<string, \Drupal\Core\TypedData\DataDefinitionInterface> $definitions
   *   The container's key each port reads, by port name: what a refusal
   *   names when it depends on the port.
   *
   * @return static
   *   The data surface, bound.
   *
   * @see docs/resources.md#ports
   */
  public function withPorts(array $values, array $definitions = []): static;

  /**
   * Gets the container's key a port reads, as its container bound it.
   *
   * @param string $port
   *   The port.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The key's definition, or NULL for no port, or one no container
   *   bound.
   */
  public function getPortDefinition(string $port): ?DataDefinitionInterface;

  /**
   * Gets what each port holds, as its container bound it.
   *
   * @return array<string, mixed>
   *   Every port the data surface reads, by name, NULL where nothing
   *   holds it.
   */
  public function getPortValues(): array;

}
