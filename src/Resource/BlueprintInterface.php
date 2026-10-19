<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\TypedData\MapDataDefinition;

/**
 * What only the owner may do, on top of adding.
 *
 * Handed to defineInputs() and defineOutputs(). Outputs may not be
 * refined, which the framework refuses when sealing rather than with a
 * separate type.
 */
interface BlueprintInterface extends BlueprintAdditionsInterface {

  /**
   * A slot: a child structure one sibling INPUT key chooses.
   *
   * The parent never names the children. Every structure carrying
   * #[Variant] for this class and key fills the slot, one per
   * value of $by, and $by's allowed values become exactly those: a Choice
   * over the variants' values, narrower than any list it already
   * declares, which must allow every one of them. Until $by holds a value
   * the slot is an `any` stub that lists every variant; once it does, the
   * stub narrows to exactly that variant's map, which is an ordinary
   * refinement under the rule that `any` may become anything narrower. A
   * sibling the context locks resolves the slot from the start.
   *
   * A child that cannot be enumerated statically is the one case for a
   * #[RefinesInput] method on an `any` key returning the narrower
   * definition itself; no verb is needed for it.
   *
   * @param string $key
   *   The key the structure sits at.
   * @param string $by
   *   The sibling input key whose value chooses the structure.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The map at the key, for the owner to label and describe with the
   *   core setters, as attach() returns it.
   *
   * @throws \LogicException
   *   When the key is already declared, or for outputs, which do not
   *   attach yet.
   */
  public function attachBy(string $key, string $by): MapDataDefinition;

  /**
   * A port: a value this structure reads from its container.
   *
   * A structure never names a key of the container it is attached to (the
   * wall), so what it needs to read there it declares under a name of its
   * own; the container wires the port to one of its keys when it attaches
   * the structure (attach(..., reads: ['capacity' => 'seats'])). The
   * structure's #[RefinesInput] methods watch the port by that name, and
   * its declared rules name it in a condition, exactly as they would a
   * sibling key; it is handed what the wired key holds, live, as a
   * sibling is. A port is no key: nothing stores, draws or accepts it.
   *
   * Identity a parent hands through OperationContext::withChild(known:)
   * is a port of a fixed kind: settled at build, and locking a key the
   * child declares. A port is the live kind: a value the container's
   * user may still change.
   *
   * @code
   * $inputs->reads('capacity', 'integer', required: TRUE);
   * @endcode
   *
   * @param string $port
   *   The name the structure reads it by: no key it declares.
   * @param string $type
   *   The typed data type of the value: a key wired to it must have this
   *   type, or the build refuses the wiring; `any` reads any key.
   * @param bool $required
   *   Whether every container attaching the structure must wire it. An
   *   optional port left unwired holds nothing: a refiner watching it
   *   never runs, and a rule reads it as absent.
   *
   * @return $this
   *
   * @throws \LogicException
   *   For outputs, which read nothing; when the name is a key, or a port,
   *   already declared.
   * @throws \InvalidArgumentException
   *   When the type is no typed data type.
   *
   * @see docs/resources.md#ports
   */
  public function reads(string $port, string $type, bool $required = FALSE): static;

  /**
   * Says what the declared keys depend on, while the build is reused.
   *
   * What a blueprint declares is read from live site state as often as
   * from literals: a key offered only while a module is installed, a
   * label read from configuration. Whatever is declared here is carried
   * by the sealed data surface and merged with what each refiner that
   * runs declares, so a form rendered from it is cached no longer than
   * its declaration holds. A resource or structure holds no service, so
   * what it hands over is a CacheableMetadata built by hand: a cache tag,
   * a context.
   *
   * @param \Drupal\Core\Cache\CacheableDependencyInterface $dependency
   *   The dependency.
   *
   * @return $this
   */
  public function addCacheableDependency(CacheableDependencyInterface $dependency): static;

}
