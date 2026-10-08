<?php

declare(strict_types=1);

namespace Drupal\data_surface\Surface;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\TypedData\MapDataDefinition;

/**
 * What only the owner may do, on top of adding.
 *
 * Handed to defineInputs() and defineOutputs(). Outputs may not be
 * refined, which the framework refuses when sealing rather than with a
 * separate type.
 */
interface ShapeInterface extends ShapeAdditionsInterface {

  /**
   * A slot: a subsurface whose shape one sibling INPUT key chooses.
   *
   * The parent never names the children. Every surface carrying
   * #[SurfaceVariant] for this surface and key fills the slot, one per
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
   *   The key the subsurface sits at.
   * @param string $by
   *   The sibling input key whose value chooses the subsurface.
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
   * Says what the shape depends on, for as long as the surface is reused.
   *
   * A shape is read from live site state as often as from literals: a
   * key offered only while a module is installed, a label read from
   * configuration. Whatever is declared here is carried by the sealed
   * surface and merged with what each refiner that runs declares, so a
   * form rendered from the surface is cached no longer than its shape
   * holds. A surface holds no service, so what it hands over is a
   * CacheableMetadata built by hand: a cache tag, a context.
   *
   * @param \Drupal\Core\Cache\CacheableDependencyInterface $dependency
   *   The dependency.
   *
   * @return $this
   */
  public function addCacheableDependency(CacheableDependencyInterface $dependency): static;

}
