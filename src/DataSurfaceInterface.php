<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Storage\SettingsLayoutInterface;

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
 * \Drupal\data_surface\Pipeline\DataSurfacePipelineInterface.
 *
 * Data surfaces are built by the build step,
 * \Drupal\data_surface\ResourceBuild\ResourcesInterface, which seals a
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
 * @see \Drupal\data_surface\DataSurfaceBuilderInterface::seal()
 * @see \Drupal\data_surface\Pipeline\DataSurfacePipelineInterface
 * @see docs/refinement.md
 * @see docs/outputs.md
 */
interface DataSurfaceInterface extends CacheableDependencyInterface {

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
   * @return \Drupal\data_surface\DefinitionMap
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
   *   is a variant, declared with attachBy() on that input.
   *
   * An output key the producer has nothing to say about is **absent**
   * from the emitted array, never NULL; Pipeline\Omitted is how a
   * producer says so from inside an array literal, and
   * DataSurfacePipelineInterface::conformOutput() is what checks an
   * emitted array against this map.
   *
   * @return \Drupal\data_surface\DefinitionMap
   *   The output definitions; empty when the data surface declares none.
   *
   * @see \Drupal\data_surface\Pipeline\Omitted
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
   * Gets how one provider's mounted third-party settings are stored.
   *
   * @param string $provider
   *   The module that mounted the settings.
   *
   * @return \Drupal\data_surface\Storage\SettingsLayoutInterface|null
   *   The layout the provider declared at build time, or NULL when its
   *   settings are stored exactly as the data surface describes them.
   *
   * @see \Drupal\data_surface\DataSurfaceBuilderInterface::setThirdPartyLayout()
   */
  public function getThirdPartyLayout(string $provider): ?SettingsLayoutInterface;

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

}
