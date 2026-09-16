<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Core\TypedData\DataDefinitionInterface;

/**
 * Removes from a refined surface, after every contribution has spoken.
 *
 * The third role in the contribution model, and the only one that may
 * act on a key it did not contribute to. A refiner speaks for the values
 * it owns; a filter speaks for the site — "this installation does not
 * allow that option, whoever added it" — so it runs last, over the union
 * of every contribution, and may only take away.
 *
 * Remove-only is checked, not trusted: what a filter returns is held to
 * the same narrowing contract every refiner link is held to, against the
 * union it was handed. A filter that hands back a wider definition is a
 * \LogicException naming the filter and the key, because a policy that
 * can add is not a policy, it is a second contributor arriving after the
 * advertisement.
 *
 * Filters see every key, not only the ones that declare refinement
 * dependencies: a policy is not a dependency of anything.
 *
 * Filters must be serializable — services or named classes, never
 * closures or anonymous classes — because surfaces ride along in cached
 * forms.
 *
 * @see \Drupal\data_surface\DataSurfaceBuilderInterface::addFilter()
 * @see \Drupal\data_surface\DataSurfaceRefinerInterface
 */
interface DataSurfaceFilterInterface {

  /**
   * Filters one definition, once every contribution has spoken.
   *
   * @param string $name
   *   The surface key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The refined definition. Always a clone — implementations may
   *   mutate and return it, or return a replacement.
   * @param array $values
   *   The values the surface is being refined against, keyed by surface
   *   key. Unlike a refiner's, these are not filtered down to declared
   *   dependencies and are not guaranteed to hold anything: a filter
   *   runs whether or not the keys it reads have been answered.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The filtered definition, which must accept no more than the one
   *   it was given.
   */
  public function filterDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface;

}
