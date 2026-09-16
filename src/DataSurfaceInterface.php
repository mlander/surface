<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;

/**
 * A runtime description of the values something accepts.
 *
 * An ordered set of core data definitions plus a refinement map naming
 * which definitions depend on which sibling values, with surface-level
 * locking. Built at runtime rather than read from static schema, so it
 * can carry live option lists and site-state constraints that YAML
 * cannot express.
 *
 * All of that is one DefinitionMap of SurfaceEntry objects. A second map
 * of the same type holds the other half of the contract: what the host's
 * execution emits, declared in the same vocabulary, narrowed against the
 * values it accepted, and checked by the pipeline's conformOutput(). The
 * rule the module holds itself to, once: contracts are objects, payloads
 * are arrays. What a surface says is read off typed objects; the values that
 * flow through accept, validate, prepare and commit are plain arrays,
 * and so is the syntax an author declares a surface in.
 *
 * One surface drives everything downstream: default values, refinement,
 * form generation (through the data surface widgets), validation
 * (through the pipeline), and eventually machine-readable schema — so a
 * form and any other consumer can never disagree about what the values
 * accept.
 *
 * A surface is pure data. It reaches no service and holds no behavior
 * beyond reading its own definitions and applying the refiners it was
 * sealed with, which is what lets it ride along in a cached form and be
 * read by a caller that has no container. Anything needing a service —
 * validation above all — belongs to
 * \Drupal\data_surface\Pipeline\DataSurfacePipelineInterface.
 *
 * Surfaces are built by a builder and sealed; nothing else constructs
 * one.
 *
 * A surface is also a cacheable dependency. What it advertises is
 * computed from live site state — option lists read from entity types,
 * languages, plugins — and from whatever the refiners that ran declared,
 * so anything that renders or stores a surface has to be able to say for
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
   * The contributor id standing for the surface's own provider.
   *
   * The owner is the first contributor: its definitions, its refinement
   * map and its refiner are contribution number one, and it is held to
   * the same rules as everybody who arrives later. The id is spelled so
   * that no module can claim it, because a module name never contains a
   * '#'.
   */
  const OWNER = '#owner';

  /**
   * Gets everything the surface advertises, in declaration order.
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
   * its definition, who introduced it, the *input* keys it refines
   * against and its refiner chains. A surface that declares no outputs
   * answers with an empty map, which is what every surface written
   * before outputs existed does, so nothing has to ask first.
   *
   * What differs from the input map, and why:
   * - No defaults. A default is what a value starts from when nobody
   *   sent one, and nobody sends an output.
   * - No locking. Locking narrows what a caller may send.
   * - An entry's dependencies name input keys, because an output is
   *   decided by what the host was given.
   *
   * An output key the producer has nothing to say about is **absent**
   * from the emitted array, never NULL; Pipeline\Omitted is how a
   * producer says so from inside an array literal, and
   * DataSurfacePipelineInterface::conformOutput() is what checks an
   * emitted array against this map.
   *
   * @return \Drupal\data_surface\DefinitionMap
   *   The output definitions; empty when the surface declares none.
   *
   * @see \Drupal\data_surface\Pipeline\Omitted
   * @see docs/outputs.md
   */
  public function getOutputDefinitions(): DefinitionMap;

  /**
   * Gets one definition, or NULL if the surface does not declare it.
   *
   * Kept beside the map because reading one key by name is what most
   * host code does, and getDefinitions()->get() says the same thing
   * twice.
   *
   * @param string $name
   *   The surface key.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The definition, or NULL when the surface declares no such key.
   */
  public function getDefinition(string $name): ?DataDefinitionInterface;

  /**
   * Returns whether a key's value is fixed by the surface.
   *
   * Locking is the degenerate refinement: the value space is narrowed to
   * exactly one value. That value is whatever storage already holds, and
   * the declared default only for a key storage has never held. A locked
   * key stays advertised, renders disabled, and ignores submitted
   * input.
   *
   * @param string $name
   *   The surface key.
   *
   * @return bool
   *   TRUE when the key's value is fixed.
   */
  public function isLocked(string $name): bool;

  /**
   * Gets the default value for one key.
   *
   * @param string $name
   *   The surface key.
   *
   * @return mixed
   *   The declared default, or NULL when the key declares none or the
   *   surface does not declare the key at all.
   */
  public function getDefault(string $name): mixed;

  /**
   * Builds the default values the surface declares.
   *
   * @return array
   *   Defaults keyed by surface key; keys without a declared default are
   *   present with NULL so consumers see the full shape.
   */
  public function getDefaultValues(): array;

  /**
   * Returns a surface with definitions refined against known values.
   *
   * A target refines only when every one of its dependencies holds a
   * configured value, judged by the same rule the pipeline applies
   * everywhere else: anything but NULL and the empty string, so a
   * checkbox that is off and a list with no items are answers a refiner
   * can narrow against.
   *
   * What refinement does with those values, in five lines:
   * 1. Deep-clone the advertised definition and divide its list of
   *    allowed values into the owner's — everything nobody contributed —
   *    and one slice per contributor.
   * 2. Run the owner's refiners over the owner's slice.
   * 3. Run each contributor's refiners over that contributor's slice.
   * 4. The refined definition is the owner's, with its list of allowed
   *    values replaced by the union of every narrowed slice.
   * 5. The policy filters then run over every key, each held to
   *    remove-only against the union it was handed.
   *
   * Every link and every filter is held to the narrowing contract
   * against what it was handed, so nothing can hand back a value it was
   * not given. The 'any' data type is the declared escape hatch: it may
   * refine to a concrete type. A refiner that declares cacheability, by
   * implementing CacheableDependencyInterface, has it merged into the
   * refined surface when it runs.
   *
   * @param array $values
   *   Values keyed by surface key.
   *
   * @return static
   *   A refined surface, or the same surface when nothing refines.
   *
   * @throws \LogicException
   *   When a refiner or a filter returns a definition that accepts more
   *   than the one it was given.
   */
  public function refine(array $values): static;

  /**
   * Returns a surface whose outputs are refined against input values.
   *
   * Deliberately not folded into refine(). The two narrow different
   * halves of the contract against the same values, and they are asked
   * at different moments by different callers: validate() refines the
   * inputs on every submission, while the outputs matter only once the
   * host has actually run. Folding them would run every output refiner
   * on every validation, for an answer nothing in that path reads.
   *
   * An output refines when every input key it declares as a dependency
   * holds a configured value — the same rule refine() applies, from the
   * same place. The narrowing contract is the same one and is checked on
   * every link; a refiner that declares cacheability has it merged into
   * the returned surface.
   *
   * The inputs come back untouched. What changes is
   * getOutputDefinitions(), which is what conformOutput() reads.
   *
   * @param array $input_values
   *   The accepted input values, keyed by surface key.
   *
   * @return static
   *   A surface with refined outputs, or the same surface when nothing
   *   refines.
   *
   * @throws \LogicException
   *   When an output refiner returns a definition that accepts more
   *   than the one it was given.
   */
  public function refineOutputs(array $input_values): static;

}
